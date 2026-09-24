<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Modules\Portal\Http\Controllers\PortalInboundImportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #123 客户门户 入库清单 CSV 提交: the client uploads its list, checks every mapped field on the preview, confirms, and
 * its orders (source portal, status received) wait in the staff 待建预报 list together with the 柜号 / ETA it submitted; customer
 * service builds the ASN from there (CR #117). Clients still never create ASNs. Another client's import is a 404.
 */
class PortalInboundImportTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const HEADERS = '唛头,中文品名,英文品名,包装类型,箱数,产品数量,实重(KG),长(CM),宽(CM),高(CM),收件人,电话,地址,城区,州,邮编,FBA参考号,要求送达日';

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('清单.csv', "\xEF\xBB\xBF".implode("\n", [self::HEADERS, ...$rows])."\n");
    }

    public function test_client_uploads_previews_every_mapped_field_confirms_and_the_orders_wait_for_customer_service(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Edward']);
        $user = $this->clientUser($client);
        $stranger = $this->clientUser($this->client(['name' => 'Other']));
        $eta = today()->addDays(10);

        $this->actingAs($user)->get(route('portal.asns.index'))->assertOk()->assertSee(route('portal.asns.imports.create'), false);
        $this->actingAs($user)->get('/portal')->assertOk()->assertSee(route('portal.asns.imports.create'), false);
        $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()->assertSee(__('portal.inbound.template'))->assertSee('MSKU1234567');

        $response = $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'manifest' => $this->csv([
                'MK-A,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,FBA15ABC123,',
                'MK-A,充电线,USB cable,纸箱,2,100,4.5,30,20,15,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,FBA15ABC123,',
                'MK-B,电热水壶,Kettle,纸箱,5,30,32.5,45,35,30,Shop B,03 9999 0000,12 High St,Richmond,VIC,3121,,2026-12-01',
                'MK-C,水杯,Mugs,托盘,1,500,300,120,100,140,Darwin Store,08 8888 8888,5 Mitchell St,Darwin,NT,800,,',
            ]),
            'container_no' => 'msku1234567',
            'container_size' => '40',
            'expected_date' => $eta->toDateString(),
            'reference' => 'PO-2026-9',
            'notes' => '周五到港',
        ]);
        $import = OrderImport::query()->sole();
        $response->assertSessionHasNoErrors()->assertRedirect(route('portal.asns.imports.show', $import));
        $this->assertSame(['portal', $client->id, 'pending', 4, 0], [$import->source, $import->client_id, $import->status, $import->row_count, $import->error_count]);
        $this->assertNull($import->errors['context']['job_id'], 'no Job until the client confirms');
        $this->assertSame('MSKU1234567', $import->errors['context']['inbound']['container_no']);
        $this->assertTrue(Document::query()->findOrFail($import->document_id)->client_visible);

        // The preview shows every field the way it will land on the order, plus what the parser fixed on its own.
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk();
        foreach (['MK-A', 'Amazon FBA BWU2', '0400000001', '1 Warehouse Rd', 'Moorebank', 'NSW', '2170', 'FBA15ABC123', '蓝牙音箱 / Bluetooth speaker', '600×400×400', 'USB cable',
            'MK-B', 'Shop B', '0399990000', 'Richmond', 'VIC', '3121', '2026-12-01', 'MK-C', 'Darwin Store', '0800', '托盘',
            'MSKU1234567', '40ft', 'PO-2026-9', '周五到港', $eta->copy()->addDays(7)->toDateString(), __('portal.inbound.actions.confirm')] as $text) {
            $page->assertSee($text);
        }
        $page->assertSee('补回了前导 0（800 → 0800）');
        $page->assertSee(__('portal.inbound.ready_count', ['ready' => 3, 'blocked' => 0, 'errors' => 0]));

        // Confirm → three orders under ONE new loose Job, every field exact; the sheet's 要求送达日 wins for MK-B.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect(route('portal.asns.imports.show', $import));
        $import->refresh();
        $this->assertSame('imported', $import->status);
        $orders = Order::query()->withoutGlobalScopes()->with(['lines', 'declaredPackages'])->orderBy('id')->get()->keyBy('consignment_mark');
        $this->assertCount(3, $orders);
        $this->assertSame(1, $orders->pluck('job_id')->unique()->count());
        $job = Job::query()->findOrFail($orders['MK-A']->job_id);
        $this->assertSame([$client->id, 'loose', 'PO-2026-9'], [$job->client_id, $job->job_type, $job->reference]);
        $this->assertSame($job->id, $import->errors['context']['job_id']);

        $a = $orders['MK-A'];
        $this->assertSame(['portal', 'received', 'from_stock', $client->id, 'Amazon FBA BWU2', '0400000001', '1 Warehouse Rd', 'Moorebank', 'NSW', '2170', 'FBA15ABC123', 'fba', $eta->copy()->addDays(7)->toDateString(), 'standard'], [
            $a->source, $a->operational_status, $a->order_type, $a->client_id, $a->deliver_to_name, $a->deliver_to_phone, $a->deliver_to_address, $a->deliver_to_suburb, $a->deliver_to_state, $a->deliver_to_postcode, $a->fba_reference, $a->deliver_to_address_type, $a->requested_date->toDateString(), $a->service_level,
        ]);
        $this->assertCount(2, $a->lines);
        $line = $a->lines->sortBy('id')->first();
        $this->assertSame(['蓝牙音箱', 'Bluetooth speaker', 'carton', 10, 200, 85.0, 600, 400, 400], [
            $line->description_cn, $line->description_en, $line->package_type, (int) $line->carton_qty, (int) $line->unit_qty, (float) $line->actual_weight_kg, (int) $line->length_mm, (int) $line->width_mm, (int) $line->height_mm,
        ]);
        $this->assertCount(2, $a->declaredPackages);
        $this->assertSame([10, 8.5, 600], [(int) $a->declaredPackages->first()->qty, (float) $a->declaredPackages->first()->weight_kg, (int) $a->declaredPackages->first()->length_mm], 'declared weight is per piece: 85 kg ÷ 10 cartons');
        $this->assertFalse($a->tailgate_required, '8.5 kg pieces need no tailgate (the line total would have flagged it)');
        $this->assertSame(['2026-12-01', '0399990000', 'business'], [$orders['MK-B']->requested_date->toDateString(), $orders['MK-B']->deliver_to_phone, $orders['MK-B']->deliver_to_address_type]);
        $this->assertSame(['0800', 'NT', 'pallet', 300.0], [$orders['MK-C']->deliver_to_postcode, $orders['MK-C']->deliver_to_state, $orders['MK-C']->lines->first()->package_type, (float) $orders['MK-C']->lines->first()->actual_weight_kg]);

        // After confirm the page lists the order numbers (portal links) and says customer service builds the ASN; the list shows the submission.
        $done = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.after_confirm'))->assertDontSee(route('portal.asns.imports.confirm', $import), false);
        foreach ($orders as $order) {
            $done->assertSee($order->order_no)->assertSee(route('portal.orders.show', $order), false);
        }
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee('#'.$import->id)->assertSee('清单.csv')->assertSee('MSKU1234567')->assertSee($orders['MK-A']->order_no);
        $this->actingAs($user)->get(route('portal.orders.show', $orders['MK-A']))->assertOk()->assertSee('蓝牙音箱')->assertSee('Amazon FBA BWU2');
        $this->actingAs($user)->get(route('portal.documents.download', $import->document_id))->assertOk();

        // Staff: the orders wait in 待建预报 with the client's inbound context and a one-click pre-fill; the import is listed as 客户门户, read-only.
        $cs = $this->staff('customer_service');
        $inbound = $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()
            ->assertSee('MSKU1234567')->assertSee('40ft')->assertSee($eta->toDateString())->assertSee('PO-2026-9')->assertSee('周五到港')->assertSee('清单.csv')
            ->assertSee(__('orders.imports.inbound.select'))->assertSee(__('orders.imports.inbound.import', ['id' => $import->id]))
            ->assertSee('data-orders="'.$orders->pluck('id')->implode(',').'"', false);
        foreach ($orders as $order) {
            $inbound->assertSee($order->order_no);
        }
        $this->assertSame(3, substr_count($inbound->getContent(), e(__('orders.imports.inbound.import', ['id' => $import->id]))), 'every order row names its portal submission');
        $this->actingAs($cs)->get(route('orders.imports.index'))->assertOk()->assertSee(__('orders.sources.portal'))->assertSee('清单.csv');
        $this->actingAs($cs)->get(route('orders.imports.show', $import))->assertOk()->assertSee('MSKU1234567')->assertSee(__('orders.imports.portal_note'))->assertSee($orders['MK-A']->order_no);

        // Tenancy: another client never reaches the import, its file or its list entry; staff never use the portal upload.
        $this->actingAs($stranger)->get(route('portal.asns.imports.show', $import))->assertNotFound();
        $this->actingAs($stranger)->post(route('portal.asns.imports.confirm', $import))->assertNotFound();
        $this->actingAs($stranger)->get(route('portal.asns.imports.index'))->assertOk()->assertDontSee('#'.$import->id)->assertDontSee('MSKU1234567');
        $this->actingAs($stranger)->get(route('portal.documents.download', $import->document_id))->assertNotFound();
        $this->actingAs($stranger)->get(route('portal.orders.show', $orders['MK-A']))->assertNotFound();
        $this->actingAs($cs)->get(route('portal.asns.imports.create'))->assertForbidden();
        $this->actingAs($cs)->get(route('portal.asns.imports.index'))->assertForbidden();
        $this->actingAs($user)->get('/portal/asns/create')->assertNotFound(); // clients still never create ASNs (CR #117)

        // Confirming twice changes nothing.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect(route('portal.asns.imports.show', $import))->assertSessionHas('status', __('portal.inbound.not_pending'));
        $this->assertSame(3, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_template_download_has_the_bom_and_headers_and_round_trips_through_the_parser(): void
    {
        Storage::fake('local');
        $user = $this->clientUser();

        $response = $this->actingAs($user)->get(route('portal.asns.imports.template'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        // CHANGE_REQUESTS #146: the template is the client's consolidation list one to one — group row, the 27 English headers, three sample cartons.
        $lines = explode("\n", trim(substr($body, 3)));
        $this->assertStringStartsWith('寄件人信息,"寄件人信息 可写国内地址",寄件人信息,', $lines[0]);
        $this->assertSame('"Sender\'s Name","Sender\'s Address","Sender\'s Phone",ChannelWaybillNumber,,Country,State/Province,City,Suburb,"Street Name","Unit/Street Number",Recipient,"Recipient\'s Email","Recipient\'s Phone Number","Postal Code","Detailed Address","Battery Type","Battery Packaging",Commodity,"TTL VALUE(AUD)",商品数量,"每箱产品总价 （AUD)",Length(cm),Width(cm),Height(cm),Weight(kg),Cube(m3)', $lines[1]);
        $this->assertCount(5, $lines);
        $this->assertStringContainsString(',CW1001-1,,Australia,VIC,Richmond,', $lines[2]);
        $this->assertStringContainsString(',CW1002,,Australia,NSW,Moorebank,', $lines[4]);
        $this->actingAs($this->staff('customer_service'))->get(route('portal.asns.imports.template'))->assertForbidden();

        // The template itself uploads cleanly: three cartons, every sample field mapped (one carton per row, prices, cm → mm).
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => UploadedFile::fake()->createWithContent('inbound-list-template.csv', $body)])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $this->assertSame([], $import->errors['issues']);
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['ready', 'ready', 'ready'], $groups->pluck('status')->values()->all());
        $this->assertSame(['CW1001-1', 'CW1001-2', 'CW1002'], $groups->keys()->all());
        $a = $groups['CW1001-1']['rows'][0];
        $this->assertSame(['Sample Recipient A', '0412000001', 'Richmond', 'VIC', '3121', '12 High St, Richmond VIC 3121'], [$a['deliver_to_name'], $a['deliver_to_phone'], $a['deliver_to_suburb'], $a['deliver_to_state'], $a['deliver_to_postcode'], $a['deliver_to_address']]);
        // The audit is JSON: a whole-number weight comes back as an int, so the numbers are compared by value.
        $this->assertEquals([1, 10, 2500, 25000, 12, 600, 400, 400, 0.096], [$a['carton_qty'], $a['unit_qty'], $a['unit_price_cents'], $a['total_price_cents'], $a['actual_weight_kg'], $a['length_mm'], $a['width_mm'], $a['height_mm'], $a['cbm']]);
        $this->assertSame(30.0, (float) $groups['CW1001-2']['rows'][0]['actual_weight_kg']);
        $this->assertSame('business', $groups['CW1002']['deliver_to_address_type'], 'no 地址类型 column → the auto rule (no FBA reference, no address-book match → business)');
    }

    public function test_the_excel_template_is_the_consolidation_sheet_with_sample_cartons_and_the_page_explains_every_header(): void
    {
        Storage::fake('local');
        $user = $this->clientUser();

        // CHANGE_REQUESTS #146: the .xlsx is the client's own sheet layout (data removed, properties scrubbed) with three sample cartons.
        $response = $this->actingAs($user)->get(route('portal.asns.imports.template_xlsx'))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition', 'attachment; filename=inbound-list-template.xlsx');
        $this->assertFileExists(base_path(PortalInboundImportController::TEMPLATE_XLSX));
        $parsed = app(SpreadsheetManifestParser::class)->parse(base_path(PortalInboundImportController::TEMPLATE_XLSX));
        $this->assertSame([], $parsed['errors']);
        $this->assertSame(['CW1001-1', 'CW1001-2', 'CW1002'], array_column($parsed['rows'], 'consignment_mark'), 'the header is row 2, one carton per row');
        $this->assertSame([2500, 25000, 10, 12.0], [$parsed['rows'][0]['unit_price_cents'], $parsed['rows'][0]['total_price_cents'], $parsed['rows'][0]['unit_qty'], $parsed['rows'][0]['actual_weight_kg']]);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(base_path(PortalInboundImportController::TEMPLATE_XLSX)));
        $this->assertStringContainsString('Logistics ERP', (string) $zip->getFromName('docProps/core.xml'), 'document properties scrubbed (creator / lastModifiedBy)');
        $this->assertFalse($zip->locateName('docProps/custom.xml'), 'no custom properties of the original workbook');
        $this->assertStringNotContainsString('DSFAU', (string) $zip->getFromName('xl/worksheets/sheet1.xml'), 'no tracking numbers of the real list');
        $zip->close();
        $this->actingAs($this->staff('customer_service'))->get(route('portal.asns.imports.template_xlsx'))->assertForbidden();

        // The upload page links both templates and explains every header (English header = meaning), without raw lang keys.
        $page = $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee(route('portal.asns.imports.template_xlsx'), false)->assertSee(__('portal.inbound.template_xlsx'))
            ->assertSee('ChannelWaybillNumber＝')->assertSee('TTL VALUE(AUD)＝'.__('portal.inbound.template_columns.TTL VALUE(AUD)'))->assertSee("Sender's Name＝"); // escaped by assertSee like the page escapes it
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\./', $page->getContent());
    }

    public function test_hard_errors_are_chinese_with_row_and_column_and_block_confirmation_and_a_duplicate_file_is_flagged(): void
    {
        Storage::fake('local');
        $user = $this->clientUser();

        // Every row broken in a different way → nothing to confirm.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([
            'ERR-1,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,,',
            'ERR-2,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Shop,0400 000 001,1 Warehouse Rd,Moorebank,NSW,ABCD,,',
            'ERR-3,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Shop,4.12E+08,1 Warehouse Rd,Moorebank,NSW,2170,,',
            'ERR-4,蓝牙音箱,Bluetooth speaker,纸箱,10.5,200,85,60,40,40,Shop,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,,',
            'ERR-5,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Shop,0400 000 001,1 Warehouse Rd,Moorebank,Mars,2170,,',
        ])])->assertRedirect();
        $import = OrderImport::query()->sole();
        $this->assertSame(['pending', 5, 5], [$import->status, $import->row_count, $import->error_count]);
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee('第 2 行「收件人」不能为空。')
            ->assertSee('第 3 行「邮编」必须是 4 位澳大利亚邮编（现为“ABCD”）')
            ->assertSee('第 4 行「电话」被 Excel 转成了数字（4.12E+08）')
            ->assertSee('第 5 行「箱数」必须是整数（现为“10.5”）')
            ->assertSee('第 6 行「州」无法识别为澳大利亚的州（现为“Mars”）')
            ->assertSee(__('portal.inbound.no_ready'))
            ->assertDontSee(route('portal.asns.imports.confirm', $import), false);
        $this->assertDoesNotMatchRegularExpression('/orders\.imports\.|orders\.fields\./', $page->getContent(), 'no raw lang key on the page');
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect(route('portal.asns.imports.show', $import))->assertSessionHas('status', __('portal.inbound.messages.nothing'));
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame('pending', $import->fresh()->status);

        // A legacy XLS is refused with the fix, not a crash.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => new UploadedFile(base_path('tests/Fixtures/imports/legacy.xls'), '清单.xls', null, null, true)])->assertSessionHasNoErrors()->assertRedirect();
        $legacy = OrderImport::query()->latest('id')->firstOrFail();
        $this->actingAs($user)->get(route('portal.asns.imports.show', $legacy))->assertOk()->assertSee('旧版 XLS 文件无法读取');

        // The same valid file twice: the second preview warns, and confirming it never creates a second order.
        $valid = fn () => $this->csv(['OK-1,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Shop,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,,']);
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $valid()])->assertRedirect();
        $first = OrderImport::query()->latest('id')->firstOrFail();
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $first))->assertRedirect();
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $valid()])->assertRedirect();
        $second = OrderImport::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('文件与导入 #'.$first->id, $second->errors['warnings'][0]['message']);
        $this->assertSame('duplicate', $second->errors['groups'][0]['status']);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $second))->assertOk()->assertSee('文件与导入 #'.$first->id)->assertSee(__('portal.inbound.group_statuses.duplicate'));
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $second))->assertRedirect();
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }
}
