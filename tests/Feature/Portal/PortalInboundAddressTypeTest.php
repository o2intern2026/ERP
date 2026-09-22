<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #136, audit PORTAL-07: the inbound list (CSV column 地址类型 / Address type, and the manual row's select) carries the
 * consignee's address type — a residential consignee lands on the order as `residential` and the existing OMS-13 TailgateRule flags
 * the order (at entry and again at confirmation); an empty cell keeps the address-book / FBA-reference / business inference; an
 * unknown word is a Chinese row error. The CSV template carries the column with FBA / 商业 / 住宅 sample rows.
 */
class PortalInboundAddressTypeTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const HEADERS = '唛头,中文品名,英文品名,包装类型,箱数,产品数量,实重(KG),长(CM),宽(CM),高(CM),收件人,电话,地址,城区,州,邮编,地址类型,FBA参考号,要求送达日';

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('清单.csv', "\xEF\xBB\xBF".implode("\n", [self::HEADERS, ...$rows])."\n");
    }

    public function test_csv_address_type_reaches_the_order_and_a_residential_consignee_needs_the_tailgate(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Edward']);
        $user = $this->clientUser($client);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([
            'MK-R,台灯,Desk lamp,纸箱,2,2,6,40,30,30,Ms Li,0412 345 678,8 Rose St,Box Hill,VIC,3128,住宅,,',
            'MK-R,床头柜,Bedside table,纸箱,1,1,7,50,40,40,,,,,,,,,',
            'MK-F,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,,FBA15ABC123,',
            'MK-B,电热水壶,Kettle,纸箱,5,30,32.5,45,35,30,Shop B,03 9999 0000,12 High St,Richmond,VIC,3121,商业,,',
            'MK-E,水杯,Mugs,纸箱,4,40,8,30,20,15,Shop E,03 9999 0001,1 Low St,Fitzroy,VIC,3065,,,',
            'MK-X,花瓶,Vase,纸箱,1,1,2,20,20,30,Someone,03 9999 0002,2 Odd St,Carlton,VIC,3053,火星,,',
        ])])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['residential', 'fba', 'business', 'business'], [$groups['MK-R']['deliver_to_address_type'], $groups['MK-F']['deliver_to_address_type'], $groups['MK-B']['deliver_to_address_type'], $groups['MK-E']['deliver_to_address_type']], 'the column wins; empty → FBA reference → fba, else business');
        $this->assertSame(['ready', 'ready', 'ready', 'ready'], $groups->pluck('status')->values()->all());
        $this->assertSame('residential', $groups['MK-R']['rows'][1]['deliver_to_address_type'], 'a blank 地址类型 cell under the same 唛头 carries down like the other consignee cells');
        $this->assertFalse($groups->has('MK-X'));
        $this->assertCount(1, $import->errors['issues']);
        $this->assertSame([7, 'deliver_to_address_type', '地址类型', 'MK-X'], [$import->errors['issues'][0]['row'], $import->errors['issues'][0]['column'], $import->errors['issues'][0]['label'], $import->errors['issues'][0]['consignment_mark']]);
        $this->assertStringContainsString('第 7 行「地址类型」无法识别（现为“火星”）', $import->errors['issues'][0]['message']);

        // The preview shows the type per mark and flags the residential one; the refused MK-X row asks for the tick (PORTAL-05).
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee('<th>'.__('portal.inbound.fields.address_type').'</th>', false)
            ->assertSee(__('portal.inbound.residential_tailgate'))->assertSee(__('portal.inbound.address_types.fba'))->assertSee(__('portal.inbound.address_types.business'))
            ->assertSee('第 7 行「地址类型」无法识别')->assertSee(__('portal.inbound.actions.confirm_partial', ['ready' => 4, 'skipped' => 1]));
        $this->assertSame(1, substr_count($page->getContent(), __('portal.inbound.residential_tailgate')), 'one residential mark');
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\./', $page->getContent(), 'no raw lang key');
        // Staff read the same pending list with the type per group (read-only for a portal submission).
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('orders.imports.show', $import))->assertOk()->assertSee(__('orders.address_types.residential'))->assertSee(__('orders.address_types.fba'));

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import), ['skip_acknowledged' => '1'])->assertSessionHasNoErrors();
        $orders = Order::query()->withoutGlobalScopes()->get()->keyBy('consignment_mark');
        $this->assertSame(['residential', 'fba', 'business', 'business'], [$orders['MK-R']->deliver_to_address_type, $orders['MK-F']->deliver_to_address_type, $orders['MK-B']->deliver_to_address_type, $orders['MK-E']->deliver_to_address_type]);
        // OMS-13 (TailgateRule, unchanged): 3 kg parcels to a residential address need the tailgate; the business / FBA ones do not.
        $this->assertSame([true, 'residential_address'], [(bool) $orders['MK-R']->tailgate_required, $orders['MK-R']->tailgate_reason]);
        $this->assertSame([false, false, false], [(bool) $orders['MK-F']->tailgate_required, (bool) $orders['MK-B']->tailgate_required, (bool) $orders['MK-E']->tailgate_required]);
        $confirmed = app(OrderStatusService::class)->transitionOperational($orders['MK-R'], 'confirmed', $cs->id);
        $this->assertSame(['confirmed', true, 'residential_address'], [$confirmed->operational_status, (bool) $confirmed->tailgate_required, $confirmed->tailgate_reason], 'the rule is re-run at confirmation and keeps the tailgate');
        $this->assertFalse((bool) app(OrderStatusService::class)->transitionOperational($orders['MK-B'], 'confirmed', $cs->id)->tailgate_required);

        // English header and words go straight through the parser: home / Amazon FBA / Company.
        $path = tempnam(sys_get_temp_dir(), 'manifest').'.csv';
        file_put_contents($path, implode("\n", [
            'Mark,Cartons,Weight (kg),Consignee,Phone,Address,Suburb,State,Postcode,Address type',
            'EN-1,2,6,Ms Li,0412345678,8 Rose St,Box Hill,VIC,3128,home',
            'EN-2,2,6,Amazon,0412345678,1 Warehouse Rd,Moorebank,NSW,2170,Amazon FBA',
            'EN-3,2,6,Shop,0412345678,12 High St,Richmond,VIC,3121,Company',
        ]));
        try {
            $parsed = app(SpreadsheetManifestParser::class)->parse($path);
        } finally {
            unlink($path);
        }
        $this->assertSame([], $parsed['errors']);
        $this->assertSame(['residential', 'fba', 'business'], array_column($parsed['rows'], 'deliver_to_address_type'));
    }

    public function test_manual_rows_offer_the_same_choice_and_a_residential_row_becomes_a_residential_order(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Manual Co']);
        $user = $this->clientUser($client);
        $row = fn (array $overrides = []): array => $overrides + [
            'consignment_mark' => 'MK-A', 'description_cn' => '台灯', 'description_en' => 'Desk lamp', 'package_type' => 'carton', 'carton_qty' => '2',
            'actual_weight_kg' => '6', 'length_mm' => '400', 'width_mm' => '300', 'height_mm' => '300',
            'deliver_to_name' => 'Ms Li', 'deliver_to_phone' => '0412 345 678', 'deliver_to_address' => '8 Rose St', 'deliver_to_suburb' => 'Box Hill',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3128', 'deliver_to_address_type' => '', 'fba_reference' => '', 'storage_tier' => 'standard', 'requested_date' => '',
        ];

        // The form: one select per row with the three types and the 自动判断 default; no raw lang key.
        $form = $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()
            ->assertSee('name="rows[0][deliver_to_address_type]"', false)
            ->assertSee('value="residential"', false)->assertSee(__('portal.inbound.address_types.residential'))
            ->assertSee('value="fba"', false)->assertSee(__('portal.inbound.address_types.fba'))
            ->assertSee(__('portal.inbound.manual.address_type_auto'));
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\./', $form->getContent());

        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [
            $row(['deliver_to_address_type' => 'residential']),
            $row(['consignment_mark' => 'MK-F', 'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_address' => '1 Warehouse Rd', 'deliver_to_suburb' => 'Moorebank', 'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'fba_reference' => 'FBA15ABC123']),
            $row(['consignment_mark' => 'MK-B', 'deliver_to_name' => 'Shop B', 'deliver_to_address_type' => 'business']),
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['residential', 'fba', 'business'], [$groups['MK-A']['deliver_to_address_type'], $groups['MK-F']['deliver_to_address_type'], $groups['MK-B']['deliver_to_address_type']]);
        $this->assertSame('residential', $import->errors['context']['manual']['rows'][0]['deliver_to_address_type'], 'the typed value is kept with the list');
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.residential_tailgate'))->assertSee(__('portal.inbound.actions.confirm'));
        // Staff read the typed rows with the column and the type per group (pending list, read-only), no raw lang key.
        $staff = $this->actingAs($this->staff('customer_service'))->get(route('orders.imports.show', $import))->assertOk()
            ->assertSee('<th>'.__('orders.imports.columns.deliver_to_address_type').'</th>', false)->assertSee(__('orders.address_types.residential'));
        $this->assertDoesNotMatchRegularExpression('/orders\.imports\.|orders\.address_types\./', $staff->getContent());

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHasNoErrors();
        $orders = Order::query()->withoutGlobalScopes()->get()->keyBy('consignment_mark');
        $this->assertSame(['residential', true, 'residential_address'], [$orders['MK-A']->deliver_to_address_type, (bool) $orders['MK-A']->tailgate_required, $orders['MK-A']->tailgate_reason]);
        $this->assertSame(['fba', false, 'business', false], [$orders['MK-F']->deliver_to_address_type, (bool) $orders['MK-F']->tailgate_required, $orders['MK-B']->deliver_to_address_type, (bool) $orders['MK-B']->tailgate_required]);
        $this->assertTrue((bool) app(OrderStatusService::class)->transitionOperational($orders['MK-A'], 'confirmed')->tailgate_required);
    }
}
