<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderImportService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #136, audit PORTAL-05: a 唛头 with a row the parser refused is blocked — an order is never generated short of a goods
 * line — and a list that will skip rows (not read, or a blocked / duplicate mark) is confirmed only after an explicit acknowledgement:
 * the button says 只提交可生成的 N 张（跳过 M 行） and the tick 我已知道跳过的行不会生成订单 is required. Portal upload, manual rows, staff import.
 */
class PortalInboundBlockedMarkTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const HEADERS = '唛头,中文品名,英文品名,包装类型,箱数,产品数量,实重(KG),长(CM),宽(CM),高(CM),收件人,电话,地址,城区,州,邮编,FBA参考号,要求送达日';

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('清单.csv', "\xEF\xBB\xBF".implode("\n", [self::HEADERS, ...$rows])."\n");
    }

    /** @return array<string, string> one complete typed row of the manual form (canonical field names) */
    private function typed(array $overrides = []): array
    {
        return $overrides + [
            'consignment_mark' => 'MK-A', 'description_cn' => '蓝牙音箱', 'description_en' => 'Bluetooth speaker', 'package_type' => 'carton', 'carton_qty' => '10',
            'actual_weight_kg' => '85', 'length_mm' => '600', 'width_mm' => '400', 'height_mm' => '400',
            'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_phone' => '0400 000 001', 'deliver_to_address' => '1 Warehouse Rd', 'deliver_to_suburb' => 'Moorebank',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'deliver_to_address_type' => '', 'fba_reference' => 'FBA15ABC123', 'storage_tier' => 'standard', 'requested_date' => '',
        ];
    }

    public function test_a_mark_with_a_refused_row_is_blocked_and_the_client_must_acknowledge_the_skipped_rows(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['name' => 'Edward']));

        // MK-A has three goods lines; the third (sheet row 4) has an unreadable postcode. MK-B is clean.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([
            'MK-A,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,FBA15ABC123,',
            'MK-A,充电线,USB cable,纸箱,2,100,4.5,30,20,15,,,,,,,,',
            'MK-A,耳机,Headphones,纸箱,3,60,9,30,20,15,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,ABCD,FBA15ABC123,',
            'MK-B,电热水壶,Kettle,纸箱,5,30,32.5,45,35,30,Shop B,03 9999 0000,12 High St,Richmond,VIC,3121,,',
        ])])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['blocked', 'ready'], [$groups['MK-A']['status'], $groups['MK-B']['status']]);
        $this->assertSame([[2, 3], [4]], [$groups['MK-A']['row_numbers'], $groups['MK-A']['error_rows']]);
        $this->assertSame(__('orders.imports.errors.rows_not_read', ['mark' => 'MK-A', 'rows' => '4']), $groups['MK-A']['message']);
        $this->assertSame(['MK-A', 4], [$import->errors['issues'][0]['consignment_mark'], $import->errors['issues'][0]['row']], 'the refused row carries its mark');
        $this->assertSame([4, 3], [$import->row_count, $import->error_count], 'rows 2 and 3 (blocked mark) and 4 (refused) are the skipped rows');

        // The preview names the rows; the button says how many orders it makes and how many rows it skips; the tick is required.
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee('唛头 MK-A 的第 4 行未读入')
            ->assertSee(__('portal.inbound.actions.confirm_partial', ['ready' => 1, 'skipped' => 3]))
            ->assertSee(__('portal.inbound.skip_acknowledge'))
            ->assertSee('name="skip_acknowledged" value="1" required', false)
            ->assertDontSee('>'.__('portal.inbound.actions.confirm').'</button>', false);
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\./', $page->getContent(), 'no raw lang key');

        // Confirm without the tick: refused in Chinese, nothing created, still pending. With it: MK-B only, MK-A stays blocked.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect(route('portal.asns.imports.show', $import))
            ->assertSessionHasErrors(['skip_acknowledged' => __('portal.inbound.errors.skip_unacknowledged')]);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame('pending', $import->fresh()->status);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.errors.skip_unacknowledged'));

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import), ['skip_acknowledged' => '1'])->assertRedirect(route('portal.asns.imports.show', $import))
            ->assertSessionHas('status', __('portal.inbound.messages.confirmed', ['count' => 1]));
        $import->refresh();
        $this->assertSame(['MK-B'], Order::query()->withoutGlobalScopes()->pluck('consignment_mark')->all());
        $this->assertSame(['imported', 3, 'blocked'], [$import->status, $import->errors['result']['failed_rows'], collect($import->errors['groups'])->firstWhere('consignment_mark', 'MK-A')['status']]);

        // A clean list keeps the plain 确认提交 and asks for no tick.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv(['MK-C,水杯,Mugs,纸箱,4,40,8,30,20,15,Shop C,03 9999 0001,1 Low St,Fitzroy,VIC,3065,,'])])->assertRedirect();
        $clean = OrderImport::query()->latest('id')->firstOrFail();
        $this->actingAs($user)->get(route('portal.asns.imports.show', $clean))->assertOk()->assertSee(__('portal.inbound.actions.confirm'))->assertDontSee('name="skip_acknowledged"', false);
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $clean))->assertSessionHasNoErrors();
        $this->assertSame(2, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_manual_rows_go_through_the_same_grouping_and_the_form_still_refuses_a_broken_row_before_any_preview(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Manual Co']);
        $user = $this->clientUser($client);
        $good = $this->typed();
        $bad = $this->typed(['description_cn' => '充电线', 'description_en' => 'USB cable', 'carton_qty' => '1.5']);
        $other = $this->typed(['consignment_mark' => 'MK-B', 'deliver_to_name' => 'Shop B', 'fba_reference' => '']);

        // The page refuses the row before a preview exists (CR #128) — the Chinese message on the cell, nothing stored.
        $this->actingAs($user)->from(route('portal.asns.imports.manual.create'))->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [$good, $bad, $other]])
            ->assertRedirect(route('portal.asns.imports.manual.create'))->assertSessionHasErrors(['rows.1.carton_qty' => '第 2 行「箱数」必须是整数（现为“1.5”）；箱数不能有小数。']);
        $this->assertSame(0, OrderImport::query()->withoutGlobalScopes()->count());

        // The grouping behind the page (ManifestParser::fromRows → OrderImportService) blocks MK-A when one of its typed rows is refused, exactly like the upload.
        $context = ['client_id' => $client->id, 'requested_date' => today()->addDays(7)->toDateString(), 'service_level' => 'standard'];
        $import = app(OrderImportService::class)->previewRows([$good, $bad, $other], [], $context, $user->id);
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['blocked', 'ready'], [$groups['MK-A']['status'], $groups['MK-B']['status']]);
        $this->assertSame([[1], [2]], [$groups['MK-A']['row_numbers'], $groups['MK-A']['error_rows']]);
        $this->assertSame(__('orders.imports.errors.rows_not_read', ['mark' => 'MK-A', 'rows' => '2']), $groups['MK-A']['message']);
        $this->assertSame(['MK-A', 2], [$import->errors['issues'][0]['consignment_mark'], $import->errors['issues'][0]['row']]);
        $this->assertSame([3, 2], [$import->row_count, $import->error_count]);

        // Portal preview: the partial button and the tick; refused without it, MK-B only with it.
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.manual.source'))->assertSee('唛头 MK-A 的第 2 行未读入')
            ->assertSee(__('portal.inbound.actions.confirm_partial', ['ready' => 1, 'skipped' => 2]))->assertSee(__('portal.inbound.skip_acknowledge'));
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHasErrors(['skip_acknowledged' => __('portal.inbound.errors.skip_unacknowledged')]);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import), ['skip_acknowledged' => '1'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('portal.inbound.manual.messages.confirmed', ['count' => 1, 'attached' => 0]));
        $this->assertSame(['MK-B'], Order::query()->withoutGlobalScopes()->pluck('consignment_mark')->all());
        $this->assertSame(['imported', 'blocked'], [$import->fresh()->status, collect($import->fresh()->errors['groups'])->firstWhere('consignment_mark', 'MK-A')['status']]);
    }

    public function test_the_staff_import_shows_the_partial_button_and_needs_the_same_acknowledgement(): void
    {
        Storage::fake('local');
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');

        // MK-A: one good line (row 2) and one refused line (row 3, postcode); MK-B clean (row 4).
        $this->actingAs($user)->post(route('orders.imports.preview'), [
            'client_id' => $client->id, 'job_id' => $job['job_id'], 'requested_date' => '2026-09-30', 'service_level' => 'standard',
            'manifest' => UploadedFile::fake()->createWithContent('dispatch.csv', implode("\n", [self::HEADERS,
                'MK-A,蓝牙音箱,Bluetooth speaker,纸箱,10,200,85,60,40,40,Amazon FBA BWU2,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,FBA15ABC123,',
                'MK-A,耳机,Headphones,纸箱,3,60,9,30,20,15,,,,,,ABCD,,',
                'MK-B,电热水壶,Kettle,纸箱,5,30,32.5,45,35,30,Shop B,03 9999 0000,12 High St,Richmond,VIC,3121,,',
            ])),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['blocked', 'ready'], [$groups['MK-A']['status'], $groups['MK-B']['status']]);
        $this->assertSame([3], $groups['MK-A']['error_rows']);

        $page = $this->actingAs($user)->get(route('orders.imports.show', $import))->assertOk()
            ->assertSee('唛头 MK-A 的第 3 行未读入')
            ->assertSee(__('orders.imports.actions.confirm_partial', ['ready' => 1, 'skipped' => 2]))
            ->assertSee(__('orders.imports.actions.skip_acknowledge'))
            ->assertSee('name="skip_acknowledged" value="1" required', false)
            ->assertSee(__('orders.address_types.fba'))->assertSee(__('orders.address_types.business'));
        $this->assertDoesNotMatchRegularExpression('/orders\.imports\.|orders\.address_types\./', $page->getContent(), 'no raw lang key');

        $ready = $groups['MK-B']['key'];
        $this->actingAs($user)->post(route('orders.imports.confirm', $import), ['groups' => [$ready]])->assertRedirect(route('orders.imports.show', $import))
            ->assertSessionHasErrors(['skip_acknowledged' => __('orders.imports.errors.skip_unacknowledged')]);
        $this->assertSame(0, Order::query()->count());
        $this->actingAs($user)->get(route('orders.imports.show', $import))->assertOk()->assertSee(__('orders.imports.errors.skip_unacknowledged'));

        $this->actingAs($user)->post(route('orders.imports.confirm', $import), ['groups' => [$ready], 'skip_acknowledged' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['MK-B'], Order::query()->pluck('consignment_mark')->all());
        $this->assertSame(['imported', 2], [$import->fresh()->status, $import->fresh()->errors['result']['failed_rows']]);
        $this->actingAs($user)->get(route('orders.imports.errors', $import))->assertOk()->assertSee('唛头 MK-A 的第 3 行未读入');
    }
}
