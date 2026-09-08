<?php

namespace App\Modules\Orders\Services;

use App\Support\Enums;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A12: heuristic field extraction from a purchase-order text (Chinese or English labels). Every field is optional; the
 * caller fills placeholders and a person checks the draft. `matched` lists which fields were actually found.
 *
 * @phpstan-type Parsed array{external_ref:?string, consignment_mark:?string, fba_reference:?string, deliver_to_name:?string, deliver_to_phone:?string, deliver_to_address:?string, deliver_to_suburb:?string, deliver_to_state:?string, deliver_to_postcode:?string, requested_date:?string, lines:list<array{description:string, carton_qty:int, actual_weight_kg:?float}>, matched:list<string>}
 */
final class DraftOrderParser
{
    /** @return Parsed */
    public function parse(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $joined = implode("\n", $lines);

        $result = [
            'external_ref' => $this->first($joined, '/(?:PO|P\.O\.|Purchase Order|Order|Ref(?:erence)?|采购单号|订单号|参考号|单号)\s*(?:No\.?|Number|#|号)?\s*[:：#]?\s*([A-Z0-9][A-Z0-9\-\/_]{2,})/iu'),
            'consignment_mark' => $this->first($joined, '/(?:Mark|Shipping Mark|唛头)\s*[:：]?\s*([^\s,，]+)/iu'),
            'fba_reference' => $this->first($joined, '/\b(FBA[A-Z0-9]{6,})\b/'),
            'deliver_to_name' => $this->first($joined, '/(?:Deliver(?:y)? to|Ship to|Consignee|Receiver|收件人|收货人|收货方|送达)\s*[:：]?\s*(.+)/iu'),
            'deliver_to_phone' => $this->first($joined, '/(?:Phone|Tel(?:ephone)?|Mobile|电话|联系电话|手机)\s*[:：]?\s*(\+?[\d][\d\s\-]{6,}\d)/iu'),
            'deliver_to_address' => null,
            'deliver_to_suburb' => null,
            'deliver_to_state' => null,
            'deliver_to_postcode' => null,
            'requested_date' => null,
            'lines' => [],
            'matched' => [],
        ];

        $address = $this->first($joined, '/(?:Address|Deliver(?:y)? address|地址|收货地址|送货地址)\s*[:：]?\s*(.+)/iu');
        $addressSource = $address ?? $joined;
        if (preg_match('/\b('.implode('|', Enums::STATES).')\b[\s,]*(\d{4})\b/i', $addressSource, $m)) {
            $result['deliver_to_state'] = strtoupper($m[1]);
            $result['deliver_to_postcode'] = $m[2];
            if ($address !== null) {
                $before = trim(preg_replace('/\s*,?\s*\b'.$m[1].'\b[\s,]*'.$m[2].'.*$/i', '', $address) ?? '');
                $segments = array_values(array_filter(array_map('trim', explode(',', $before))));
                if (count($segments) >= 2) {
                    $result['deliver_to_suburb'] = array_pop($segments);
                    $result['deliver_to_address'] = implode(', ', $segments);
                } elseif ($before !== '' && preg_match('/^(.*\S)\s+([A-Za-z][A-Za-z ]+)$/', $before, $s)) {
                    $result['deliver_to_address'] = trim($s[1]);
                    $result['deliver_to_suburb'] = trim($s[2]);
                } elseif ($before !== '') {
                    $result['deliver_to_address'] = $before;
                }
            }
        } elseif ($address !== null) {
            $result['deliver_to_address'] = $address;
        }

        if (preg_match('/(?:Requested|Required|Delivery date|Deliver(?:y)? by|ETA|要求送达|送达日期|交货日期|送货日期)\s*[:：]?\s*(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4})/iu', $joined, $d)) {
            $result['requested_date'] = $this->date($d[1]);
        }

        foreach ($lines as $line) {
            $description = null;
            $qty = 0;
            $weight = null;
            if (preg_match('/^(?!.*(?:Phone|Tel|电话|Address|地址|Ref|PO\b|Order))(.+?)\s*[\s:：x×*]\s*(\d{1,5})\s*(?:ctns?|cartons?|boxes?|箱|件)\b(?:.*?(\d+(?:\.\d+)?)\s*kgs?)?/iu', $line, $m)) {
                [$description, $qty, $weight] = [$m[1], (int) $m[2], $m[3] ?? null];
            } elseif (preg_match('/^(\d{1,5})\s*(?:ctns?|cartons?|boxes?|箱|件|x|×)\s+(.+?)(?:\s+(\d+(?:\.\d+)?)\s*kgs?)?$/iu', $line, $m)) {
                [$description, $qty, $weight] = [$m[2], (int) $m[1], $m[3] ?? null];
            }
            if ($description !== null && $qty > 0 && trim($description, " \t-:：") !== '') {
                $result['lines'][] = ['description' => trim($description, " \t-:："), 'carton_qty' => $qty, 'actual_weight_kg' => $weight !== null && $weight !== '' ? (float) $weight : null];
            }
        }

        foreach ($result as $key => $value) {
            if (! in_array($key, ['lines', 'matched'], true) && $value !== null) {
                $result['matched'][] = $key;
            }
        }
        if ($result['lines'] !== []) {
            $result['matched'][] = 'lines';
        }

        return $result;
    }

    private function first(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $m)) {
            $value = trim($m[1], " \t,，;；");

            return $value !== '' ? mb_substr($value, 0, 255) : null;
        }

        return null;
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'Y/m/d', 'Y.m.d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
