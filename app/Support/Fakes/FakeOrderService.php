<?php

namespace App\Support\Fakes;

use App\Support\Contracts\OrderService;

/** Returns one deterministic order per ASN; seed asn_line ids with linesForAsn() in tests. */
final class FakeOrderService implements OrderService
{
    /** @var array<int, list<int>> */
    private array $linesByAsn = [];

    /** @param list<int> $asnLineIds */
    public function linesForAsn(int $asnId, array $asnLineIds): void
    {
        $this->linesByAsn[$asnId] = $asnLineIds;
    }

    public function createFromAsn(int $asnId, string $groupingKey = 'mark_address_fba'): array
    {
        return [
            'orders' => [[
                'order_id' => $asnId * 100 + 1,
                'order_no' => sprintf('ORD-FAKE-%06d-0001', $asnId),
                'asn_line_ids' => $this->linesByAsn[$asnId] ?? [],
            ]],
            'blocked' => [],
        ];
    }
}
