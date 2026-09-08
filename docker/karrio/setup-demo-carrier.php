#!/usr/bin/env php
<?php

/**
 * One-shot helper: create the demo custom carrier ("Demo Freight") and its AUD rate sheet inside the local Karrio, using
 * the ERP's own Karrio settings (config/services.php → KARRIO_BASE_URL / KARRIO_API_KEY). Idempotent: re-running finds the
 * existing connection / rate sheet. Prints a summary and never prints the key.
 *
 *   php docker/karrio/setup-demo-carrier.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = rtrim((string) config('services.karrio.base_url'), '/');
$key = trim((string) config('services.karrio.api_key'));
if ($key === '') {
    fwrite(STDERR, "KARRIO_API_KEY is empty in .env — create a key in the Karrio dashboard (Developers → API Keys) first.\n");
    exit(1);
}

$gql = function (string $query, array $variables = []) use ($base, $key): array {
    $response = Http::withHeaders(['Authorization' => 'Token '.$key])->acceptJson()->timeout(30)->post($base.'/graphql', $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables]);
    if (! $response->successful()) {
        throw new RuntimeException('Karrio GraphQL HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));
    }
    $body = $response->json();
    if (! empty($body['errors'])) {
        throw new RuntimeException('Karrio GraphQL error: '.json_encode($body['errors']));
    }

    return $body['data'] ?? [];
};

$carrierId = 'demo-freight';
$connections = collect($gql('{ user_connections { edges { node { id carrier_id carrier_name display_name active } } } }')['user_connections']['edges'] ?? [])->pluck('node')->all();
$connection = collect($connections)->first(fn ($c) => ($c['carrier_id'] ?? null) === $carrierId);
if ($connection === null) {
    $data = $gql('mutation ($input: CreateCarrierConnectionMutationInput!) { create_carrier_connection(input: $input) { connection { id carrier_id carrier_name display_name active } errors { field messages } } }', [
        'input' => ['carrier_name' => 'generic', 'carrier_id' => $carrierId, 'active' => true, 'credentials' => ['display_name' => 'Demo Freight', 'custom_carrier_name' => 'demo_freight', 'account_country_code' => 'AU'], 'metadata' => ['created_by' => 'erp setup-demo-carrier']],
    ]);
    if (! empty($data['create_carrier_connection']['errors'])) {
        throw new RuntimeException('create_carrier_connection: '.json_encode($data['create_carrier_connection']['errors']));
    }
    $connection = $data['create_carrier_connection']['connection'];
    echo "Created carrier connection {$connection['carrier_id']} ({$connection['id']})\n";
} else {
    echo "Carrier connection {$connection['carrier_id']} already exists ({$connection['id']})\n";
}

$sheetName = 'Demo Freight AU rates';
$sheets = $gql('{ rate_sheets { edges { node { id name carrier_name services { id service_code cost currency transit_days } carriers { id carrier_id } } } } }')['rate_sheets']['edges'] ?? [];
$sheet = collect($sheets)->pluck('node')->first(fn ($s) => ($s['name'] ?? null) === $sheetName);
if ($sheet === null) {
    // Flat AUD prices per service (no zones) — enough for a demo; real carriers bring their own rating.
    $services = [
        ['service_name' => 'Road Freight — standard', 'service_code' => 'road_standard', 'currency' => 'AUD', 'cost' => 86.5, 'transit_days' => 3, 'domicile' => true, 'international' => false, 'active' => true, 'weight_unit' => 'KG', 'dimension_unit' => 'CM', 'max_weight' => 1200],
        ['service_name' => 'Road Freight — express', 'service_code' => 'road_express', 'currency' => 'AUD', 'cost' => 129.0, 'transit_days' => 1, 'domicile' => true, 'international' => false, 'active' => true, 'weight_unit' => 'KG', 'dimension_unit' => 'CM', 'max_weight' => 1200],
        ['service_name' => 'Same-day metro', 'service_code' => 'same_day_metro', 'currency' => 'AUD', 'cost' => 190.0, 'transit_days' => 0, 'domicile' => true, 'international' => false, 'active' => true, 'weight_unit' => 'KG', 'dimension_unit' => 'CM', 'max_weight' => 500],
    ];
    $data = $gql('mutation ($input: CreateRateSheetMutationInput!) { create_rate_sheet(input: $input) { rate_sheet { id name services { id service_code cost currency transit_days } carriers { id carrier_id } } errors { field messages } } }', [
        'input' => ['name' => $sheetName, 'carrier_name' => 'generic', 'services' => $services, 'carriers' => [$connection['id']], 'origin_countries' => ['AU']],
    ]);
    if (! empty($data['create_rate_sheet']['errors'])) {
        throw new RuntimeException('create_rate_sheet: '.json_encode($data['create_rate_sheet']['errors']));
    }
    $sheet = $data['create_rate_sheet']['rate_sheet'];
    echo "Created rate sheet {$sheet['name']} ({$sheet['id']})\n";
} else {
    echo "Rate sheet {$sheet['name']} already exists ({$sheet['id']})\n";
}
foreach ($sheet['services'] ?? [] as $s) {
    printf("  %-16s %8.2f %s  transit %s d\n", $s['service_code'], $s['cost'], $s['currency'], $s['transit_days'] ?? '-');
}
echo 'Linked carriers: '.implode(', ', array_map(fn ($c) => $c['carrier_id'], $sheet['carriers'] ?? []))."\n";

// Karrio prices a custom carrier from its zone matrix: one shared zone (all of Australia) × a sell rate per service.
$sheetQuery = 'query ($id: String!) { rate_sheet(id: $id) { id zones { id label country_codes } services { id service_code cost transit_days } service_rates { service_id zone_id rate } } }';
$full = $gql($sheetQuery, ['id' => $sheet['id']])['rate_sheet'];
if (($full['zones'] ?? []) === []) {
    $data = $gql('mutation ($input: UpdateRateSheetMutationInput!) { update_rate_sheet(input: $input) { rate_sheet { id } errors { field messages } } }', [
        'input' => ['id' => $sheet['id'], 'zones' => [['id' => 'au-all', 'label' => 'Australia (all states)', 'country_codes' => ['AU'], 'transit_days' => 3]]],
    ]);
    if (! empty($data['update_rate_sheet']['errors'])) {
        throw new RuntimeException('update_rate_sheet zones: '.json_encode($data['update_rate_sheet']['errors']));
    }
    $full = $gql($sheetQuery, ['id' => $sheet['id']])['rate_sheet'];
    echo "Added zone: Australia (all states)\n";
}
$priced = collect($full['service_rates'] ?? [])->filter(fn ($r) => (float) ($r['rate'] ?? 0) > 0)->count();
echo 'Matrix: '.count($full['zones'] ?? []).' zone(s), '.count($full['service_rates'] ?? []).' cell(s), '.$priced." priced\n";
if ($priced < count($full['services'] ?? []) && ($full['zones'] ?? []) !== []) {
    $zoneId = $full['zones'][0]['id'];
    $serviceRates = array_map(fn (array $svc) => ['service_id' => $svc['id'], 'zone_id' => $zoneId, 'rate' => (float) $svc['cost'], 'cost' => round((float) $svc['cost'] * 0.8, 2), 'transit_days' => (int) ($svc['transit_days'] ?? 3)], $full['services']);
    $data = $gql('mutation ($input: UpdateRateSheetMutationInput!) { update_rate_sheet(input: $input) { rate_sheet { id } errors { field messages } } }', [
        'input' => ['id' => $sheet['id'], 'service_rates' => $serviceRates],
    ]);
    if (! empty($data['update_rate_sheet']['errors'])) {
        throw new RuntimeException('update_rate_sheet service_rates: '.json_encode($data['update_rate_sheet']['errors']));
    }
    echo 'Added sell rates for '.count($serviceRates)." services in that zone\n";
}

// Each service must list the zone it is priced in (zone_ids), otherwise Karrio has nothing to rate.
$full = $gql('query ($id: String!) { rate_sheet(id: $id) { zones { id } services { id service_code zone_ids } } }', ['id' => $sheet['id']])['rate_sheet'];
$zoneIds = array_map(fn ($z) => $z['id'], $full['zones'] ?? []);
$unlinked = array_values(array_filter($full['services'] ?? [], fn ($svc) => array_diff($zoneIds, $svc['zone_ids'] ?? []) !== []));
if ($unlinked !== [] && $zoneIds !== []) {
    $data = $gql('mutation ($input: UpdateRateSheetMutationInput!) { update_rate_sheet(input: $input) { rate_sheet { id } errors { field messages } } }', [
        'input' => ['id' => $sheet['id'], 'services' => array_map(fn ($svc) => ['id' => $svc['id'], 'zone_ids' => $zoneIds], $unlinked)],
    ]);
    if (! empty($data['update_rate_sheet']['errors'])) {
        throw new RuntimeException('update_rate_sheet zone_ids: '.json_encode($data['update_rate_sheet']['errors']));
    }
    echo 'Linked '.count($unlinked)." service(s) to the zone\n";
}

// Smoke test: one rate request through the REST API, exactly what the ERP adapter sends.
$rates = Http::withHeaders(['Authorization' => 'Token '.$key])->acceptJson()->timeout(30)->post($base.'/v1/proxy/rates', [
    'shipper' => ['address_line1' => '1 Depot Rd', 'city' => 'Dandenong South', 'state_code' => 'VIC', 'postal_code' => '3175', 'country_code' => 'AU', 'company_name' => 'Edward Logistics', 'person_name' => 'Ops', 'phone_number' => '0300000000'],
    'recipient' => ['address_line1' => '2 End St', 'city' => 'Sydney', 'state_code' => 'NSW', 'postal_code' => '2000', 'country_code' => 'AU', 'company_name' => 'Receiver Pty Ltd', 'person_name' => 'Receiver', 'phone_number' => '0400000000'],
    'parcels' => [['weight' => 10, 'weight_unit' => 'KG', 'length' => 40, 'width' => 30, 'height' => 25, 'dimension_unit' => 'CM', 'packaging_type' => 'your_packaging']],
    'options' => ['currency' => 'AUD'],
]);
echo 'Rate check HTTP '.$rates->status().': '.count($rates->json('rates') ?? []).' rate(s)';
foreach ($rates->json('rates') ?? [] as $r) {
    printf("\n  %s / %s → %.2f %s", $r['carrier_id'] ?? '?', $r['service'] ?? '?', (float) ($r['total_charge'] ?? 0), $r['currency'] ?? '');
}
if (! empty($rates->json('messages'))) {
    echo "\n  messages: ".json_encode($rates->json('messages'));
}
echo "\n";
