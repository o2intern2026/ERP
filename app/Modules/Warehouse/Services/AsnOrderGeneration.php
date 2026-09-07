<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Support\Contracts\OrderService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B2c: after putaway, one click turns an ASN's goods lines into delivery orders through OMS's
 * OrderService::createFromAsn (grouping mark + address + FBA is theirs); WMS only writes back asn_lines.order_line_id,
 * which is also what makes a second click create nothing (§4.7 #17, #18).
 */
final class AsnOrderGeneration
{
    public function __construct(private readonly OrderService $orders) {}

    /** @return array{orders: list<array{order_id:int, order_no:string, asn_line_ids:list<int>}>, blocked: list<array<string, mixed>>, linked_lines: int} */
    public function generate(Asn $asn): array
    {
        if (! in_array($asn->status, ['putaway', 'closed'], true)) {
            throw new InvalidArgumentException('Orders are generated after putaway is complete.');
        }

        return DB::transaction(function () use ($asn): array {
            $result = $this->orders->createFromAsn($asn->id);
            $linked = 0;

            foreach ($result['orders'] ?? [] as $order) {
                $orderLines = DB::table('order_lines')->where('order_id', $order['order_id'])->whereNotNull('asn_line_id')->pluck('id', 'asn_line_id');
                foreach ($order['asn_line_ids'] ?? [] as $asnLineId) {
                    if (isset($orderLines[$asnLineId])) {
                        $linked += $asn->lines()->whereKey($asnLineId)->whereNull('order_line_id')->update(['order_line_id' => $orderLines[$asnLineId]]);
                    }
                }
            }

            return ['orders' => $result['orders'] ?? [], 'blocked' => $result['blocked'] ?? [], 'linked_lines' => $linked];
        });
    }
}
