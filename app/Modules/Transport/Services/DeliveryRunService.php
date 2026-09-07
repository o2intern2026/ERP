<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeliveryRunService
{
    public function __construct(private readonly ShipmentBookingService $bookings) {}

    public function create(string $runDate, int $driverId, string $vehicle): DeliveryRun
    {
        $driver = User::query()->find($driverId);
        if ($driver === null || ! $driver->is_active || ! $driver->hasRole('transport_operator')) {
            throw new DomainException(__('transport.runs.invalid_driver'));
        }

        return DeliveryRun::query()->create([
            'run_no' => 'RUN-'.str_replace('-', '', $runDate).'-'.Str::upper(Str::random(6)),
            'run_date' => $runDate,
            'driver_id' => $driver->id,
            'vehicle' => trim($vehicle),
            'status' => 'planned',
        ]);
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
            if ($lockedShipment->shipment_type !== 'outbound'
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
}
