<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Services\TransportOptionService;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * 客户门户申请上门提货 (CHANGE_REQUESTS #125) test steps: stub carriers bound into TransportOptionService, the client's 入库清单 with
 * 需要我们上门提货, its confirm with a plan, and customer service generating the ASN from 待建预报 with the request pre-filled.
 */
trait PortalCollectionFlow
{
    /** @param array<string, array{code:string, name:string, level:string, cost:int}> $carriers source → carrier */
    protected function bindStubCarriers(array $carriers): void
    {
        StubCarrierAdapter::$costs = [];
        StubCarrierAdapter::$requests = [];
        $adapters = [];
        foreach ($carriers as $source => $c) {
            // Idempotent, so a test can re-bind with an extra carrier after setUp() bound the first ones.
            $carrier = Carrier::query()->firstOrCreate(['code' => $c['code']], ['name' => $c['name'], 'status' => 'active']);
            CarrierService::query()->firstOrCreate(['carrier_id' => $carrier->id, 'source' => $source, 'service_level' => $c['level']], ['default_eta_days' => 1, 'active' => true]);
            StubCarrierAdapter::$costs[$source] = $c['cost'];
            $adapters[] = new StubCarrierAdapter($source, $c['level']);
        }

        $service = new TransportOptionService($adapters, app(ShipmentQuoteRequestFactory::class), app(RateService::class), app(ExceptionService::class), app(QuoteSelectionService::class));
        $this->app->instance(TransportOptionService::class, $service);
        $this->app->instance(TransportOptionServiceContract::class, $service);
    }

    /** @param list<string> $rows */
    protected function collectionCsv(array $rows): UploadedFile
    {
        $headers = '唛头,中文品名,英文品名,包装类型,箱数,产品数量,实重(KG),长(CM),宽(CM),高(CM),收件人,电话,地址,城区,州,邮编,FBA参考号,要求送达日';

        return UploadedFile::fake()->createWithContent('提货清单.csv', "\xEF\xBB\xBF".implode("\n", [$headers, ...$rows])."\n");
    }

    /** @return list<string> two marks: 10 cartons of 8.5 kg each, and one 300 kg pallet (→ tailgate at pickup) */
    protected function collectionRows(): array
    {
        return [
            'MK-A,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,FBA15ABC123,',
            'MK-B,水杯,Mugs,托盘,1,500,300,120,100,140,Shop B,03 9999 0000,12 High St,Richmond,VIC,3121,,',
        ];
    }

    /** @return array<string, mixed> the upload's 到仓方式 fields for 需要你们上门提货 */
    protected function collectionFields(Warehouse $warehouse, array $overrides = []): array
    {
        return array_replace_recursive([
            'inbound_transport' => 'we_collect',
            'warehouse_id' => $warehouse->id,
            'collection' => ['name' => 'Factory', 'phone' => '0400 000 009', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
            'collection_ready_date' => today()->addDays(2)->toDateString(),
            'collection_notes' => 'Dock 3, 8-4',
        ], $overrides);
    }

    /** @param list<string>|null $rows */
    protected function uploadCollection(User $user, Warehouse $warehouse, ?array $rows = null, array $overrides = []): OrderImport
    {
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->collectionCsv($rows ?? $this->collectionRows())] + $this->collectionFields($warehouse, $overrides))
            ->assertSessionHasNoErrors()->assertRedirect();

        return OrderImport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function confirmCollection(User $user, OrderImport $import, ?string $choice): TestResponse
    {
        return $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import), $choice === null ? [] : ['collection_choice' => $choice]);
    }

    protected function planKey(string $source, string $level): string
    {
        return $source.'|'.$level.'|'.CarrierService::query()->where('source', $source)->value('carrier_id');
    }

    /** @return list<int> */
    protected function importedOrderIds(OrderImport $import): array
    {
        return array_map('intval', array_column($import->fresh()->errors['result']['created'] ?? [], 'order_id'));
    }

    /** 待建预报 → 生成预报单 with every field 选中并填入 copies from the submission (the JS), plus `$overrides`. */
    protected function generateFromImport(User $staff, OrderImport $import, array $overrides = []): TestResponse
    {
        $collection = $import->fresh()->errors['context']['inbound']['collection'];

        return $this->actingAs($staff)->from(route('orders.inbound.index'))->post(route('orders.inbound.store'), array_replace([
            'order_ids' => $this->importedOrderIds($import),
            'warehouse_id' => $collection['warehouse_id'],
            'inbound_type' => 'loose_truck',
            'inbound_transport' => 'we_collect',
            'collection' => $collection['address'],
            'collection_ready_date' => $collection['ready_date'],
            'collection_notes' => $collection['notes'],
            'collection_import_id' => $import->id,
        ], $overrides));
    }
}
