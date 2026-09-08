<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;

/**
 * Item 3b (tester feedback): while a delivery address is typed, the order forms suggest addresses the client has used
 * before — the address book (client_addresses) first, then distinct deliver_to_* snapshots of the client's past orders —
 * matched with LIKE %q% on address / suburb / name, at most LIMIT entries, only for that client. Nothing is suggested
 * for an address the client never used: street-level autocomplete needs an external provider (CHANGE_REQUESTS #78).
 */
final class AddressSuggestionService
{
    public const LIMIT = 8;

    public const MIN_LENGTH = 2;

    /** @return list<array{label: string, name: ?string, phone: ?string, address: string, suburb: ?string, state: ?string, postcode: ?string, address_type: ?string, source: string}> */
    public function suggest(int $clientId, string $q, int $limit = self::LIMIT): array
    {
        $q = trim($q);
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return [];
        }
        $like = '%'.addcslashes($q, '%_\\').'%';

        $book = ClientAddress::query()->where('client_id', $clientId)
            ->where(fn ($w) => $w->where('address', 'like', $like)->orWhere('suburb', 'like', $like)->orWhere('contact_name', 'like', $like)->orWhere('label', 'like', $like))
            ->orderByDesc('usage_count')->orderByDesc('last_used_at')->orderBy('label')
            ->limit($limit)
            ->get()
            ->map(fn (ClientAddress $a) => $this->entry($a->contact_name ?: $a->label, $a->phone, $a->address, $a->suburb, $a->state, $a->postcode, $a->address_type, 'book'));

        $columns = ['deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'deliver_to_address_type'];
        $history = Order::query()->where('client_id', $clientId)
            ->where(fn ($w) => $w->where('deliver_to_address', 'like', $like)->orWhere('deliver_to_suburb', 'like', $like)->orWhere('deliver_to_name', 'like', $like))
            ->groupBy($columns)
            ->orderByRaw('max(id) desc') // most recently used snapshot first
            ->limit($limit)
            ->get($columns)
            ->map(fn (Order $o) => $this->entry($o->deliver_to_name, $o->deliver_to_phone, $o->deliver_to_address, $o->deliver_to_suburb, $o->deliver_to_state, $o->deliver_to_postcode, $o->deliver_to_address_type, 'history'));

        return $book->concat($history)
            ->unique(fn (array $e) => mb_strtolower(implode('|', [trim($e['address']), trim((string) $e['suburb']), trim((string) $e['state']), trim((string) $e['postcode']), trim((string) $e['name'])])))
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array{label: string, name: ?string, phone: ?string, address: string, suburb: ?string, state: ?string, postcode: ?string, address_type: ?string, source: string} */
    private function entry(?string $name, ?string $phone, ?string $address, ?string $suburb, ?string $state, ?string $postcode, ?string $type, string $source): array
    {
        $place = trim(implode(' ', array_filter([$suburb, $state, $postcode], fn ($v) => filled($v))));

        return [
            'label' => trim(($name ? $name.' — ' : '').$address.($place !== '' ? ', '.$place : '')),
            'name' => $name,
            'phone' => $phone,
            'address' => (string) $address,
            'suburb' => $suburb,
            'state' => $state,
            'postcode' => $postcode,
            'address_type' => $type,
            'source' => $source,
        ];
    }
}
