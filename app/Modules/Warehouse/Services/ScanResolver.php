<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;

/**
 * B11: one scan input for everything — a unit label goes to the unit, a location code to the stock in that location,
 * a consignment mark on an open ASN to that ASN's receiving page. Scanner guns type + Enter; the phone camera page posts the same code.
 */
final class ScanResolver
{
    /** @return array{type:string, id:int, url:string, label:string}|null */
    public function resolve(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        if ($unit = StockUnit::query()->where('label_code', $code)->first()) {
            return ['type' => 'unit', 'id' => $unit->id, 'url' => $unit->putaway_completed ? route('warehouse.stock.show', $unit) : route('warehouse.putaway.index', ['highlight' => $unit->id]), 'label' => $unit->label_code];
        }

        if ($location = Location::query()->where('full_code', $code)->first()) {
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
