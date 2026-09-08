<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\OrderEnums;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\JobService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OrderCreationService
{
    public function __construct(private readonly JobService $jobs, private readonly TailgateRule $tailgate) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createManual(array $attributes, ?int $actorId): Order
    {
        return $this->create($attributes, $actorId, 'manual');
    }

    /**
     * Shared creation path for manual, Excel, portal, API and ASN-generated orders.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $actorId, string $source): Order
    {
        if (! in_array($source, OrderEnums::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown order source: {$source}");
        }

        if (filled($attributes['job_id'] ?? null)) {
            $job = Job::query()->findOrFail((int) $attributes['job_id']);
            if ((int) $job->client_id !== (int) $attributes['client_id']) {
                throw new InvalidArgumentException('The selected Job does not belong to the selected client.');
            }
        } else {
            // A11b: a pure transport order opens its own transport_only Job; anything else without a Job gets a loose one (A27 JobService).
            $attributes['job_id'] = $this->jobs->create((int) $attributes['client_id'], ($attributes['order_type'] ?? null) === 'pickup_deliver' ? 'transport_only' : 'loose', [
                'reference' => $attributes['external_ref'] ?? $attributes['consignment_mark'] ?? null,
            ])['job_id'];
        }

        return DB::transaction(function () use ($attributes, $actorId, $source): Order {
            $order = Order::query()->create([
                ...Arr::only($attributes, [
                    'client_id', 'job_id', 'order_type', 'external_ref', 'consignment_mark', 'fba_reference',
                    'pickup_address', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address',
                    'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'deliver_to_address_type',
                    'delivery_instructions', 'requested_date', 'service_level', 'original_order_id',
                ]),
                'order_no' => $this->nextOrderNo(),
                'source' => $source,
                'operational_status' => 'received',
                'fulfilment_status' => 'unfulfilled',
                'billing_status' => 'unbilled',
                'tailgate_required' => false,
                'created_by' => $actorId,
            ]);

            foreach ($attributes['lines'] ?? [] as $line) {
                $order->lines()->create(Arr::only($line, [
                    'description_cn', 'description_en', 'hs_code', 'material', 'usage', 'brand', 'package_type',
                    'carton_qty', 'unit_qty', 'unit_price_cents', 'total_price_cents', 'actual_weight_kg',
                    'length_mm', 'width_mm', 'height_mm', 'cbm', 'asn_line_id', 'stock_unit_ref', 'original_order_line_id',
                ]));
            }

            foreach ($attributes['declared_packages'] ?? [] as $package) {
                $order->declaredPackages()->create(Arr::only($package, [
                    'package_type', 'qty', 'weight_kg', 'length_mm', 'width_mm', 'height_mm',
                ]));
            }

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'dimension' => 'operational',
                'from_status' => null,
                'to_status' => 'received',
                'actor_type' => $actorId === null ? 'system' : 'user',
                'actor_id' => $actorId,
                'note' => null,
                'created_at' => now(),
            ]);

            if (filled($attributes['client_address_id'] ?? null)) {
                $address = ClientAddress::query()
                    ->whereKey((int) $attributes['client_address_id'])
                    ->where('client_id', $order->client_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $address->update([
                    'usage_count' => $address->usage_count + 1,
                    'last_used_at' => now(),
                ]);
            }

            $this->tailgate->apply($order->load('lines', 'declaredPackages')); // A16: automatic rule at entry, re-checked at confirmation

            return $order->load('lines', 'declaredPackages', 'events');
        });
    }

    /** ORD-YYYYMMDD-NNNN, sequence per day under the same transaction as creation. */
    private function nextOrderNo(): string
    {
        $prefix = 'ORD-'.now()->format('Ymd').'-';
        $last = Order::query()->withoutGlobalScopes()
            ->where('order_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('order_no')
            ->value('order_no');

        $sequence = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
