<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeliveryRunService
{
    /** CHANGE_REQUESTS #133 (audit TMS-01): a run can still be corrected while it is open — planned, or dispatched with no stop delivered yet. */
    public const OPEN_STATUSES = ['planned', 'dispatched'];

    /** Stop statuses the driver has not acted on: only these leave a run (移出班次) or are released by 取消班次. */
    public const OPEN_STOP_STATUSES = ['pending', 'arrived'];

    public function __construct(private readonly ShipmentBookingService $bookings) {}

    public function create(string $runDate, int $driverId, string $vehicle): DeliveryRun
    {
        $driver = $this->activeDriver($driverId);

        return DeliveryRun::query()->create([
            'run_no' => 'RUN-'.str_replace('-', '', $runDate).'-'.Str::upper(Str::random(6)),
            'run_date' => $runDate,
            'driver_id' => $driver->id,
            'vehicle' => trim($vehicle),
            'status' => 'planned',
        ]);
    }

    /**
     * CHANGE_REQUESTS #133: date, driver and vehicle of an open run with no delivered stop (created for the wrong day, the driver
     * is sick, rolled to tomorrow). The run number keeps the date it was created with — it is an identifier, not a fact.
     */
    public function update(DeliveryRun $run, string $runDate, int $driverId, string $vehicle): DeliveryRun
    {
        $driver = $this->activeDriver($driverId);

        return DB::transaction(function () use ($run, $runDate, $driver, $vehicle): DeliveryRun {
            $locked = $this->lockOpenRun($run);
            $old = ['run_date' => $locked->run_date->toDateString(), 'driver_id' => $locked->driver_id, 'vehicle' => $locked->vehicle];
            $attributes = ['run_date' => $runDate, 'driver_id' => $driver->id, 'vehicle' => trim($vehicle)];
            $locked->fill($attributes)->save();
            $this->log($locked, 'updated', ['old' => $old, 'attributes' => $attributes]);

            return $locked->refresh();
        });
    }

    /**
     * CHANGE_REQUESTS #133: a stop the driver has not acted on leaves the run (wrong shipment on the truck, a drop that cannot be
     * reached today). Its shipment keeps `booked` and has no run again, so 加入自有车队运单 offers it for another run; the remaining
     * stops are renumbered 1..n. A dispatched run whose remaining stops are all delivered / failed completes, as a delivery would.
     */
    public function removeStop(DeliveryRun $run, RunStop $stop): DeliveryRun
    {
        return DB::transaction(function () use ($run, $stop): DeliveryRun {
            $lockedRun = DeliveryRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($lockedRun->status, self::OPEN_STATUSES, true)) {
                throw new DomainException(__('transport.runs.not_editable'));
            }

            $lockedStop = RunStop::query()->lockForUpdate()->findOrFail($stop->id);
            if ((int) $lockedStop->delivery_run_id !== (int) $lockedRun->id) {
                throw new DomainException(__('transport.runs.stop_not_in_run'));
            }
            if (! in_array($lockedStop->status, self::OPEN_STOP_STATUSES, true)) {
                throw new DomainException(__('transport.runs.stop_not_pending'));
            }

            $released = $this->release($lockedRun, collect([$lockedStop]));
            $this->renumber($lockedRun);
            if ($lockedRun->status === 'dispatched' && ! $this->hasOpenStops($lockedRun)) {
                $lockedRun->status = 'completed';
                $lockedRun->save();
            }
            $this->log($lockedRun, 'stop_removed', ['attributes' => ['seq' => $lockedStop->seq, 'shipments' => $released]]);

            return $lockedRun->refresh();
        });
    }

    /**
     * CHANGE_REQUESTS #133: the run is cancelled and every stop the driver has not acted on is released exactly as removeStop()
     * does; a failed stop stays as the record of the attempt (its shipment is re-planned through 重新派送). Refused once a stop
     * was delivered — that run is history and completes on its own.
     */
    public function cancel(DeliveryRun $run): DeliveryRun
    {
        return DB::transaction(function () use ($run): DeliveryRun {
            $locked = $this->lockOpenRun($run);
            $open = RunStop::query()
                ->where('delivery_run_id', $locked->id)
                ->whereIn('status', self::OPEN_STOP_STATUSES)
                ->orderBy('seq')
                ->lockForUpdate()
                ->get();
            $released = $this->release($locked, $open);
            $locked->status = 'cancelled';
            $locked->save();
            $this->log($locked, 'cancelled', ['attributes' => ['shipments' => $released]]);

            return $locked->refresh();
        });
    }

    public function addShipment(DeliveryRun $run, Shipment $shipment, ?string $eta = null): RunStop
    {
        return DB::transaction(function () use ($run, $shipment, $eta): RunStop {
            $lockedRun = DeliveryRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($lockedRun->status !== 'planned') {
                throw new DomainException(__('transport.runs.not_planned'));
            }

            if ($lockedShipment->delivery_run_id === $lockedRun->id) {
                return RunStop::query()
                    ->where('delivery_run_id', $lockedRun->id)
                    ->where('shipment_id', $lockedShipment->id)
                    ->firstOrFail();
            }

            if ($lockedShipment->delivery_run_id !== null) {
                throw new DomainException(__('transport.runs.already_assigned'));
            }

            $selectedQuote = $lockedShipment->selected_quote_id === null
                ? null
                : TransportQuote::query()->find($lockedShipment->selected_quote_id);
            if (! TransportEnums::isDelivery($lockedShipment->shipment_type)
                || ! in_array($lockedShipment->status, ['quote_confirmed', 'booked'], true)
                || $selectedQuote === null
                || $selectedQuote->shipment_id !== $lockedShipment->id
                || $selectedQuote->quote_stage !== 'final'
                || $selectedQuote->status !== 'selected'
                || $selectedQuote->source !== 'own_fleet') {
                throw new DomainException(__('transport.runs.own_fleet_only'));
            }

            $nextSequence = (int) RunStop::query()
                ->where('delivery_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->max('seq') + 1;

            $stop = RunStop::query()->create([
                'delivery_run_id' => $lockedRun->id,
                'shipment_id' => $lockedShipment->id,
                'seq' => $nextSequence,
                'eta' => $eta,
                'status' => 'pending',
            ]);

            $lockedShipment->update(['delivery_run_id' => $lockedRun->id]);
            if ($lockedShipment->status === 'quote_confirmed') {
                $this->bookings->book($lockedShipment->refresh());
            }

            return $stop->refresh();
        });
    }

    /** @param list<int> $stopIds */
    public function reorder(DeliveryRun $run, array $stopIds): DeliveryRun
    {
        return DB::transaction(function () use ($run, $stopIds): DeliveryRun {
            $lockedRun = DeliveryRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($lockedRun->status !== 'planned') {
                throw new DomainException(__('transport.runs.not_planned'));
            }

            $stops = RunStop::query()
                ->where('delivery_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->get();
            $expected = $stops->pluck('id')->sort()->values()->all();
            $submitted = collect($stopIds)->map(fn (mixed $id): int => (int) $id)->all();

            if (count($submitted) !== count(array_unique($submitted))
                || collect($submitted)->sort()->values()->all() !== $expected) {
                throw new DomainException(__('transport.runs.invalid_order'));
            }

            RunStop::query()
                ->where('delivery_run_id', $lockedRun->id)
                ->update(['seq' => DB::raw('seq + 10000')]);

            foreach ($submitted as $index => $stopId) {
                RunStop::query()->whereKey($stopId)->update(['seq' => $index + 1]);
            }

            return $lockedRun->load('stops');
        });
    }

    /** The run, locked, while it may still be changed as a whole: open and without a delivered stop. */
    private function lockOpenRun(DeliveryRun $run): DeliveryRun
    {
        $locked = DeliveryRun::query()->lockForUpdate()->findOrFail($run->id);
        if (! in_array($locked->status, self::OPEN_STATUSES, true)) {
            throw new DomainException(__('transport.runs.not_editable'));
        }
        if (RunStop::query()->where('delivery_run_id', $locked->id)->where('status', 'delivered')->exists()) {
            throw new DomainException(__('transport.runs.delivered_stop'));
        }

        return $locked;
    }

    /**
     * The stops leave the run and their shipments are free (still booked) to be planned again.
     *
     * @param  Collection<int, RunStop>  $stops
     * @return list<string> the released shipment numbers
     */
    private function release(DeliveryRun $run, Collection $stops): array
    {
        $numbers = [];
        foreach ($stops as $stop) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($stop->shipment_id);
            if ((int) $shipment->delivery_run_id === (int) $run->id) {
                $shipment->update(['delivery_run_id' => null]);
            }
            $stop->delete();
            $numbers[] = $shipment->shipment_no;
        }

        return $numbers;
    }

    /** Closes the gap a removed stop leaves: ascending order, so (run, seq) never collides on the way. */
    private function renumber(DeliveryRun $run): void
    {
        $ids = RunStop::query()->where('delivery_run_id', $run->id)->orderBy('seq')->pluck('id');
        foreach ($ids as $index => $id) {
            RunStop::query()->whereKey($id)->update(['seq' => $index + 1]);
        }
    }

    private function hasOpenStops(DeliveryRun $run): bool
    {
        return RunStop::query()
            ->where('delivery_run_id', $run->id)
            ->whereNotIn('status', ['delivered', 'failed'])
            ->exists();
    }

    private function activeDriver(int $driverId): User
    {
        $driver = User::query()->find($driverId);
        if ($driver === null || ! $driver->is_active || ! $driver->hasRole('transport_operator')) {
            throw new DomainException(__('transport.runs.invalid_driver'));
        }

        return $driver;
    }

    /** @param array<string, mixed> $properties */
    private function log(DeliveryRun $run, string $event, array $properties): void
    {
        $activity = activity('delivery_run')->performedOn($run)->withProperties($properties);
        if (auth()->user() !== null) {
            $activity->causedBy(auth()->user());
        }
        $activity->log(__('transport.runs.log.'.$event));
    }
}
