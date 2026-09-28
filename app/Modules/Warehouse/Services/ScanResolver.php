<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockUnit;

/**
 * B11: one scan input for everything — a unit label goes to the unit, a location code to the stock in that location,
 * a consignment mark on an open ASN to that ASN's receiving page. Scanner guns type + Enter; the phone camera page posts the same code.
 * Unit and location codes come as the label's short token (`U<id>` / `L<id>`, ScanCodes) or the full code, either case (CHANGE_REQUESTS #131).
 */
final class ScanResolver
{
    /** @return array{type:string, id:int, url:string, label:string}|null */
    public function resolve(string $code): ?array
    {
        $code = ScanCodes::normalize($code);
        if ($code === '') {
            return null;
        }

        // CHANGE_REQUESTS #166: a pallet label (P<id> / P-000123) → its putaway row while at the dock, else the stock list filtered on it.
        if ($pallet = Pallet::query()->scanCode($code)->first()) {
            $first = StockUnit::query()->where('pallet_id', $pallet->id)->orderBy('id')->first();

            return ['type' => 'pallet', 'id' => $pallet->id, 'url' => $pallet->putaway_completed || $first === null ? route('warehouse.index', ['pallet' => $pallet->pallet_no]) : route('warehouse.putaway.index', ['highlight' => $first->id]), 'label' => $pallet->pallet_no];
        }
        // CHANGE_REQUESTS #131: the label barcode carries U<id> / L<id>; the full label_code / full_code still resolves.
        if ($unit = StockUnit::query()->scanCode($code)->first()) {
            return ['type' => 'unit', 'id' => $unit->id, 'url' => $unit->putaway_completed ? route('warehouse.stock.show', $unit) : route('warehouse.putaway.index', ['highlight' => $unit->id]), 'label' => $unit->label_code];
        }

        if ($location = Location::query()->scanCode($code)->first()) {
            return ['type' => 'location', 'id' => $location->id, 'url' => route('warehouse.index', ['location' => $location->full_code]), 'label' => $location->full_code];
        }

        $line = AsnLine::query()->whereRaw('UPPER(consignment_mark) = ?', [$code])
            ->whereHas('asn', fn ($q) => $q->whereIn('status', ['booked', 'arrived', 'receiving']))
            ->orderByDesc('id')->first();
        if ($line !== null) {
            return ['type' => 'asn_line', 'id' => $line->id, 'url' => route('warehouse.asns.show', $line->asn_id).'#line-'.$line->id, 'label' => $line->consignment_mark];
        }

        return null;
    }
}
