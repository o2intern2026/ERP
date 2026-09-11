<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\OrderLine;
use App\Support\Contracts\OrderService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Real Orders-owned implementation of contracts/services.md §2. */
final class AsnOrderService implements OrderService
{
    public function __construct(
        private readonly OrderCreationService $orders,
        private readonly OrderInboundService $inbound,
    ) {}

    /**
     * 从订单导入货物行 (CHANGE_REQUESTS #119): the client's orders still waiting for an ASN, oldest first.
     *
     * @return list<array{order_id:int, order_no:string, job_id:int, job_no:string, consignment_mark:?string, deliver_to_name:?string, deliver_to_suburb:?string, deliver_to_state:?string, requested_date:?string, operational_status:string, unlinked_lines:int, total_lines:int, unlinked_cartons:int}>
     */
    public function awaitingAsn(int $clientId): array
    {
        return $this->inbound->awaitingAsn($clientId);
    }

    /**
     * 从订单导入货物行 (CHANGE_REQUESTS #119): the picked orders' unlinked goods lines become lines of the existing ASN, the
     * orders join its Job. Same rules as 从订单生成预报单 (OrderInboundService::attachToAsn).
     *
     * @param  list<int>  $orderIds
     * @return array{orders:int, lines:int, merged:list<string>, cancelled:list<string>, job_no:string}
     */
    public function attachOrdersToAsn(int $asnId, array $orderIds, ?int $actorId, ?string $containerNo = null): array
    {
        return $this->inbound->attachToAsn($asnId, $orderIds, $actorId, $containerNo);
    }

    public function createFromAsn(int $asnId, string $groupingKey = 'mark_address_fba'): array
    {
        if ($groupingKey !== 'mark_address_fba') {
            throw new InvalidArgumentException("Unsupported ASN order grouping key: {$groupingKey}");
        }

        $asn = DB::table('asns')->where('id', $asnId)->first();
        if ($asn === null) {
            throw new InvalidArgumentException("ASN {$asnId} does not exist.");
        }

        $usedIds = OrderLine::query()->whereNotNull('asn_line_id')->pluck('asn_line_id');
        $lines = DB::table('asn_lines')->where('asn_id', $asnId)
            ->whereNull('order_line_id')->where('received_cartons', '>', 0)
            ->whereNotIn('id', $usedIds)->orderBy('id')->get();
        $created = [];
        $blocked = [];

        foreach ($lines->groupBy(fn ($line) => mb_strtolower(trim((string) $line->consignment_mark))) as $markLines) {
            $first = $markLines->first();
            $ids = $markLines->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $mark = trim((string) $first->consignment_mark);
            $signatures = $markLines->map(fn ($line) => $this->signature($line))->unique();

            if ($mark === '' || $signatures->count() > 1 || ! $this->completeDelivery($first)) {
                $blocked[] = [
                    'consignment_mark' => $mark,
                    'reason' => $mark === '' ? 'missing_consignment_mark' : ($signatures->count() > 1 ? 'inconsistent_delivery_or_fba' : 'incomplete_delivery'),
                    'asn_line_ids' => $ids,
                ];

                continue;
            }

            $order = $this->orders->create([
                'client_id' => (int) $asn->client_id,
                'job_id' => (int) $asn->job_id,
                'order_type' => 'from_stock',
                'consignment_mark' => $mark,
                'fba_reference' => $first->fba_reference,
                'deliver_to_name' => $first->deliver_to_name,
                'deliver_to_phone' => $first->deliver_to_phone,
                'deliver_to_address' => $first->deliver_to_address,
                'deliver_to_suburb' => $first->deliver_to_suburb,
                'deliver_to_state' => $first->deliver_to_state,
                'deliver_to_postcode' => $first->deliver_to_postcode,
                'deliver_to_address_type' => filled($first->fba_reference) ? 'fba' : 'business',
                'requested_date' => $asn->expected_date ?? now()->toDateString(),
                'service_level' => 'standard',
                'lines' => $markLines->map(fn ($line) => [
                    'description_en' => $line->description,
                    'package_type' => $line->package_type ?: 'carton',
                    'carton_qty' => (int) $line->received_cartons,
                    'actual_weight_kg' => $line->weight_kg,
                    'length_mm' => $line->length_mm,
                    'width_mm' => $line->width_mm,
                    'height_mm' => $line->height_mm,
                    'cbm' => $line->cbm,
                    'asn_line_id' => (int) $line->id,
                ])->all(),
            ], null, 'manual');

            $created[] = ['order_id' => $order->id, 'order_no' => $order->order_no, 'asn_line_ids' => $ids];
        }

        return ['orders' => $created, 'blocked' => $blocked];
    }

    private function signature(object $line): string
    {
        return mb_strtolower(implode('|', array_map(fn ($value) => trim((string) $value), [
            $line->deliver_to_name, $line->deliver_to_address, $line->deliver_to_state,
            $line->deliver_to_postcode, $line->fba_reference,
        ])));
    }

    private function completeDelivery(object $line): bool
    {
        return filled($line->deliver_to_name) && filled($line->deliver_to_address)
            && filled($line->deliver_to_suburb) && filled($line->deliver_to_state) && filled($line->deliver_to_postcode);
    }
}
