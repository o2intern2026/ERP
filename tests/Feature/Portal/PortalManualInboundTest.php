<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Models\Document;
use App\Modules\Warehouse\Models\Asn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\PortalCollectionFlow;
use Tests\Support\StubCarrierAdapter;
use Tests\TestCase;

/**
 * 门户手工建立入库清单 (CHANGE_REQUESTS #128): rows typed on the page and / or the client's existing orders ticked into ONE submission
 * that goes through the same preview → confirm as the CSV upload. 以订单为准: an attached order is shown from the ORDER and never
 * changed, whatever is posted for it. Drafts reopen with rows, ticks and context. Another client's draft / submission / order id is
 * a 404 or a Chinese refusal with nothing stored.
 */
class PortalManualInboundTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, PortalCollectionFlow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->bindStubCarriers(['own_fleet' => ['code' => 'OWN-PMI', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => 7500]]);
    }

    /** @return array<string, string> one complete typed row (the CSV template's fields, canonical names) */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'consignment_mark' => 'MK-A', 'description_cn' => '蓝牙音箱', 'description_en' => 'Bluetooth speaker', 'package_type' => 'carton', 'carton_qty' => '10',
            'actual_weight_kg' => '85', 'length_mm' => '600', 'width_mm' => '400', 'height_mm' => '400',
            'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_phone' => '0400 000 001', 'deliver_to_address' => '1 Warehouse Rd', 'deliver_to_suburb' => 'Moorebank',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'fba_reference' => 'FBA15ABC123', 'storage_tier' => 'standard', 'requested_date' => '',
        ];
    }

    /** An existing from_stock order of the client (status received, one goods line without an ASN line) — a 待建预报 candidate. */
    private function existingOrder(Client $client, User $actor, array $overrides = [], array $line = []): Order
    {
        return app(OrderCreationService::class)->create($overrides + [
            'client_id' => $client->id, 'order_type' => 'from_stock', 'consignment_mark' => 'MK-O', 'deliver_to_name' => 'Shop One', 'deliver_to_phone' => '0399990001',
            'deliver_to_address' => '12 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(14)->toDateString(), 'service_level' => 'standard',
            'lines' => [$line + ['description_cn' => '电热水壶', 'description_en' => 'Kettle', 'package_type' => 'carton', 'carton_qty' => 5, 'actual_weight_kg' => 32.5, 'length_mm' => 450, 'width_mm' => 350, 'height_mm' => 300]],
        ], $actor->id, 'portal')->fresh(['lines']);
    }

    /** The order and its lines as stored — compared before / after to prove 以订单为准. */
    private function snapshot(Order $order): array
    {
        $fresh = Order::query()->withoutGlobalScopes()->with('lines')->findOrFail($order->id);

        return ['order' => $fresh->getAttributes(), 'lines' => $fresh->lines->map(fn ($line) => $line->getAttributes())->all()];
    }

    public function test_the_form_lists_only_attachable_orders_and_row_errors_come_back_in_chinese_with_the_input_kept(): void
    {
        $client = $this->client(['name' => 'Manual Co']);
        $user = $this->clientUser($client);
        $stranger = $this->clientUser($this->client(['name' => 'Other']));
        $attachable = $this->existingOrder($client, $user, ['consignment_mark' => 'MK-FREE']);
        $inDraft = $this->existingOrder($client, $user, ['consignment_mark' => 'MK-TAKEN']);
        $picking = $this->existingOrder($client, $user, ['consignment_mark' => 'MK-PICKING']);
        Order::query()->withoutGlobalScopes()->whereKey($picking->id)->update(['operational_status' => 'picking']);
        $foreign = $this->existingOrder($stranger->client, $stranger, ['consignment_mark' => 'MK-FOREIGN']);
        // MK-TAKEN sits in another submission of the client (a draft) — it must not be offered twice.
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), ['action' => 'draft', 'attached_order_ids' => [$inDraft->id]])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('portal.asns.index'))->assertOk()->assertSee(route('portal.asns.imports.manual.create'), false)->assertSee(__('portal.inbound.manual.button'));
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee(route('portal.asns.imports.manual.create'), false);
        $form = $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()
            ->assertSee(__('portal.inbound.manual.title'))->assertSee(__('portal.inbound.manual.attach_hint'))->assertSee(__('portal.inbound.manual.columns.actual_weight_kg'))
            ->assertSee('name="attached_order_ids[]" value="'.$attachable->id.'"', false)->assertSee($attachable->order_no)->assertSee('Shop One')
            ->assertDontSee($inDraft->order_no)->assertDontSee($picking->order_no)->assertDontSee($foreign->order_no)
            ->assertSee('name="action" value="draft"', false)->assertSee('name="action" value="preview"', false)->assertSee('id="collection-fields" hidden disabled', false);
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\./', $form->getContent(), 'no raw lang key');
        $this->actingAs($this->staff('customer_service'))->get(route('portal.asns.imports.manual.create'))->assertForbidden();

        // Two broken rows: Chinese messages naming the row and the column, keyed to the cell; nothing stored; the form comes back as typed.
        $this->actingAs($user)->from(route('portal.asns.imports.manual.create'))->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview',
            'rows' => [$this->row(['deliver_to_postcode' => 'ABCD', 'deliver_to_name' => '']), $this->row(['consignment_mark' => 'MK-B', 'carton_qty' => '1.5', 'deliver_to_state' => ''])],
            'container_no' => 'msku7654321',
        ])->assertRedirect(route('portal.asns.imports.manual.create'))->assertSessionHasErrors([
            'rows.0.deliver_to_postcode' => '第 1 行「邮编」必须是 4 位澳大利亚邮编（现为“ABCD”）。',
            'rows.0.deliver_to_name' => '第 1 行「收件人」不能为空。',
            'rows.1.carton_qty' => '第 2 行「箱数」必须是整数（现为“1.5”）；箱数不能有小数。',
        ]);
        $this->assertSame(1, OrderImport::query()->withoutGlobalScopes()->count(), 'only the draft from above');
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('consignment_mark', 'MK-B')->count());
        $again = $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()
            ->assertSee('第 1 行「邮编」必须是 4 位澳大利亚邮编')->assertSee('第 2 行「箱数」必须是整数')
            ->assertSee('name="rows[0][deliver_to_postcode]" value="ABCD"', false)->assertSee('name="rows[1][consignment_mark]" value="MK-B"', false)
            ->assertSee('name="rows[0][deliver_to_postcode]" value="ABCD" maxlength="4" inputmode="numeric" placeholder="邮编" aria-label="邮编" aria-invalid="true"', false); // CR #158: no 柜号 input any more
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\.|orders\.fields\./', $again->getContent(), 'no raw lang key with errors');

        // Neither a row nor a ticked order: refused in Chinese.
        $this->actingAs($user)->from(route('portal.asns.imports.manual.create'))->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [['consignment_mark' => '']]])
            ->assertRedirect(route('portal.asns.imports.manual.create'))->assertSessionHasErrors(['rows' => __('portal.inbound.manual.errors.nothing')]);
        $this->assertSame(1, OrderImport::query()->withoutGlobalScopes()->count());

        // A list with no typed row at all — only a ticked order: previews (no groups), confirms (nothing created, one attached), and the order is untouched.
        $snapshot = $this->snapshot($attachable);
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [['consignment_mark' => '', 'carton_qty' => '']], 'attached_order_ids' => [$attachable->id], 'reference' => 'ONLY-ATTACHED'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $attachedOnly = OrderImport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['pending', 0, [], [$attachable->id]], [$attachedOnly->status, $attachedOnly->row_count, $attachedOnly->errors['groups'], $attachedOnly->manualAttachedIds()]);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $attachedOnly))->assertOk()->assertSee($attachable->order_no)->assertSee('Shop One')->assertSee(__('portal.inbound.actions.confirm'));
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $attachedOnly))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('portal.inbound.manual.messages.confirmed', ['count' => 0, 'attached' => 1]));
        $attachedOnly->refresh();
        $this->assertSame(['imported', [], [['order_id' => $attachable->id, 'order_no' => $attachable->order_no]], [$attachable->id]], [$attachedOnly->status, $attachedOnly->errors['result']['created'], $attachedOnly->errors['result']['attached'], $attachedOnly->orderIds()]);
        $this->assertEquals($snapshot, $this->snapshot($attachable));
        $this->assertNull($attachedOnly->errors['context']['job_id'], 'no typed row, no loose Job opened');

        // The upload page still works with the shared partials (regression): context + 到仓方式 fieldset + file input, and an upload previews.
        $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee('name="manifest"', false)->assertSee('name="container_size"', false)->assertSee('name="inbound_transport"', false)->assertSee('id="collection-fields" hidden disabled', false)
            ->assertSee(route('portal.asns.imports.manual.create'), false);
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->collectionCsv($this->collectionRows()), 'container_no' => 'csku1'])->assertSessionHasNoErrors()->assertRedirect();
        $upload = OrderImport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['pending', 'CSKU1', '提货清单.csv', false], [$upload->status, $upload->errors['context']['inbound']['container_no'], $upload->errors['context']['original_name'], $upload->isManual()]);
        $this->assertNotNull($upload->document_id);
    }

    public function test_typed_rows_and_an_attached_order_preview_from_the_order_and_confirm_never_changes_the_attached_order(): void
    {
        $client = $this->client(['name' => 'Manual Co']);
        $user = $this->clientUser($client);
        $stranger = $this->clientUser($this->client(['name' => 'Other']));
        $order = $this->existingOrder($client, $user);
        $foreign = $this->existingOrder($stranger->client, $stranger, ['consignment_mark' => 'MK-FOREIGN']);
        $before = $this->snapshot($order);
        $eta = today()->addDays(10);

        // Another client's order id, or a made-up one: Chinese refusal, nothing stored.
        foreach ([$foreign->id, 999999] as $badId) {
            $this->actingAs($user)->from(route('portal.asns.imports.manual.create'))->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [$this->row()], 'attached_order_ids' => [$badId]])
                ->assertRedirect(route('portal.asns.imports.manual.create'))->assertSessionHasErrors(['attached_order_ids' => __('orders.imports.errors.order_unknown', ['id' => $badId])]);
        }
        $this->assertSame(0, OrderImport::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('consignment_mark', 'MK-A')->count());

        // 以订单为准: the client posts a typed row under the ORDER's mark with a different consignee, and junk "fields" for the order id — none of it touches the order.
        $response = $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview',
            'rows' => [
                $this->row(),
                $this->row(['consignment_mark' => 'MK-A', 'description_cn' => '充电线', 'description_en' => 'USB cable', 'carton_qty' => '2', 'actual_weight_kg' => '4.5', 'length_mm' => '300', 'width_mm' => '200', 'height_mm' => '150', 'deliver_to_name' => '', 'deliver_to_phone' => '', 'deliver_to_address' => '', 'deliver_to_suburb' => '', 'deliver_to_state' => '', 'deliver_to_postcode' => '', 'fba_reference' => '']),
                $this->row(['consignment_mark' => 'MK-O', 'description_cn' => '水杯', 'description_en' => 'Mugs', 'package_type' => 'pallet', 'carton_qty' => '1', 'actual_weight_kg' => '300', 'length_mm' => '1200', 'width_mm' => '1000', 'height_mm' => '1400', 'deliver_to_name' => 'Hacker', 'deliver_to_phone' => '0400 000 009', 'deliver_to_address' => '9 Wrong St', 'deliver_to_suburb' => 'Darwin', 'deliver_to_state' => 'NT', 'deliver_to_postcode' => '800', 'fba_reference' => '', 'storage_tier' => 'bottom']),
            ],
            'attached_order_ids' => [$order->id, (string) $order->id],
            'attached' => [$order->id => ['deliver_to_name' => 'Hacker', 'deliver_to_postcode' => '0800']],
            'orders' => [$order->id => ['consignment_mark' => 'HACKED']],
            'container_no' => 'msku1234567', 'container_size' => '40', 'expected_date' => $eta->toDateString(), 'reference' => 'PO-128', 'notes' => '周五到港',
        ]);
        $import = OrderImport::query()->withoutGlobalScopes()->sole();
        $response->assertSessionHasNoErrors()->assertRedirect(route('portal.asns.imports.show', $import));
        $this->assertSame(['portal', $client->id, 'pending', null, null, 3, 0, true], [$import->source, $import->client_id, $import->status, $import->document_id, $import->errors['context']['original_name'], $import->row_count, $import->error_count, $import->isManual()]);
        $this->assertSame([$order->id], $import->errors['context']['manual']['attached_order_ids']);
        $this->assertSame('MK-O', $import->errors['context']['manual']['rows'][2]['consignment_mark']);
        $this->assertEquals(['container_no' => 'MSKU1234567', 'container_size' => '40', 'expected_date' => $eta->toDateString(), 'reference' => 'PO-128', 'notes' => '周五到港'], array_intersect_key($import->errors['context']['inbound'], array_flip(['container_no', 'container_size', 'expected_date', 'reference', 'notes'])));
        $groups = collect($import->errors['groups'])->keyBy('consignment_mark');
        $this->assertSame(['MK-A' => 'ready', 'MK-O' => 'ready'], $groups->map(fn ($g) => $g['status'])->all());
        $this->assertSame([[1, 2], [3]], [$groups['MK-A']['row_numbers'], $groups['MK-O']['row_numbers']]);
        $this->assertSame(['Amazon FBA BWU2', '0400000001', 'NSW', '2170'], [$groups['MK-A']['deliver_to_name'], $groups['MK-A']['deliver_to_phone'], $groups['MK-A']['rows'][1]['deliver_to_state'], $groups['MK-A']['rows'][1]['deliver_to_postcode']], 'blank consignee cells carry down under the same mark, as on a sheet');
        $this->assertSame(['bottom', 'client', '0800'], [$groups['MK-O']['rows'][0]['storage_tier'], $groups['MK-O']['rows'][0]['storage_tier_source'], $groups['MK-O']['deliver_to_postcode']]);
        $this->assertSame(0, Document::query()->withoutGlobalScopes()->count(), 'no file, no Document');
        $this->assertEquals($before, $this->snapshot($order), 'the preview changed nothing on the attached order');

        // The preview: 手工录入 instead of a file, no download link, the typed groups, and the attached order from the ORDER — its own consignee, not the posted junk.
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk();
        foreach ([__('portal.inbound.manual.source'), 'MSKU1234567', '40ft', 'PO-128', 'MK-A', 'Amazon FBA BWU2', '蓝牙音箱 / Bluetooth speaker', 'USB cable', 'MK-O', 'Hacker', 'Darwin',
            __('portal.inbound.manual.attached_title'), $order->order_no, 'Shop One', '0399990001', '12 High St, Richmond VIC 3121', '电热水壶 / Kettle', '450×350×300', '32.5',
            __('portal.inbound.manual.attached_count', ['count' => 1]), __('portal.inbound.actions.confirm')] as $text) {
            $page->assertSee($text);
        }
        $page->assertDontSee(__('portal.inbound.actions.download_file'))->assertDontSee('HACKED')->assertDontSee('name="attached_order_ids', false);
        $this->assertSame(1, substr_count($page->getContent(), 'Hacker'), 'Hacker appears once: the typed MK-O group, never on the attached order');
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\.|orders\.fields\./', $page->getContent());
        $this->actingAs($stranger)->get(route('portal.asns.imports.show', $import))->assertNotFound();
        $this->actingAs($stranger)->post(route('portal.asns.imports.confirm', $import))->assertNotFound();

        // Confirm: the typed groups become orders, the attached order is recorded by id and stays exactly as it was; the flash counts both.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect(route('portal.asns.imports.show', $import))
            ->assertSessionHas('status', __('portal.inbound.manual.messages.confirmed', ['count' => 2, 'attached' => 1]));
        $import->refresh();
        $this->assertSame('imported', $import->status);
        $this->assertSame([['order_id' => $order->id, 'order_no' => $order->order_no]], $import->errors['result']['attached']);
        $this->assertCount(2, $import->errors['result']['created']);
        $this->assertEqualsCanonicalizing([...array_column($import->errors['result']['created'], 'order_id'), $order->id], $import->orderIds());
        $this->assertEquals($before, $this->snapshot($order), 'confirm changed nothing on the attached order (columns, lines, timestamps)');
        $this->assertSame('received', $order->fresh()->operational_status);
        $created = Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->whereKeyNot($order->id)->orderBy('id')->get()->keyBy('consignment_mark');
        $this->assertSame(['MK-A', 'MK-O'], $created->keys()->all());
        $this->assertSame(['Hacker', 'NT', '0800', 'portal', 'received'], [$created['MK-O']->deliver_to_name, $created['MK-O']->deliver_to_state, $created['MK-O']->deliver_to_postcode, $created['MK-O']->source, $created['MK-O']->operational_status], 'the typed MK-O row is a NEW order, not an edit of the attached one');
        $this->assertSame(1, $created->pluck('job_id')->unique()->count(), 'one loose Job for the typed rows');
        $this->assertNotSame((int) $order->job_id, (int) $created['MK-A']->job_id, 'the attached order keeps its own Job until customer service generates the ASN');

        // After confirm: created and attached orders listed; the list shows 手工录入 and counts the attached order; no ASN yet.
        $done = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.after_confirm'))->assertSee('Shop One');
        foreach ([$created['MK-A'], $created['MK-O'], $order] as $listed) {
            $done->assertSee($listed->order_no)->assertSee(route('portal.orders.show', $listed), false);
        }
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee(__('portal.inbound.manual.source'))->assertSee(__('portal.inbound.manual.attached_count', ['count' => 1]))->assertSee($order->order_no)->assertSee($created['MK-A']->order_no);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());
        // Once confirmed, neither the attached nor the created orders can be attached to a new list.
        $next = $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()->assertSee(__('portal.inbound.manual.attach_empty'));
        foreach ([$created['MK-A'], $created['MK-O'], $order] as $taken) {
            $next->assertDontSee('name="attached_order_ids[]" value="'.$taken->id.'"', false);
        }
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHas('status', __('portal.inbound.not_pending'));
        $this->assertSame(3, Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->count());
    }

    public function test_a_draft_is_saved_reopened_with_rows_ticks_and_context_then_submitted_and_another_clients_draft_is_a_404(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $stranger = $this->clientUser($this->client());
        $warehouse = $this->warehouse();
        $order = $this->existingOrder($client, $user);

        // A half-finished list is saved as is: an incomplete row, a ticked order, the context and an incomplete pickup request — no parsing, no required rule.
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), [
            'action' => 'draft',
            'rows' => [$this->row(['deliver_to_postcode' => '', 'deliver_to_name' => ''])],
            'attached_order_ids' => [$order->id],
            'container_no' => 'draft001', 'expected_date' => today()->addDays(9)->toDateString(), 'reference' => 'PO-D',
            'inbound_transport' => 'we_collect', 'warehouse_id' => $warehouse->id, 'collection' => ['name' => 'Factory', 'address' => '9 Supplier Rd', 'suburb' => '', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $draft = OrderImport::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['draft', 'portal', $client->id, null, true, 1, 0], [$draft->status, $draft->source, $draft->client_id, $draft->document_id, $draft->isManual(), $draft->row_count, $draft->error_count]);
        $this->assertSame([$order->id], $draft->manualAttachedIds());
        $this->assertSame([], $draft->orderIds(), 'a draft stands for no order yet');
        $this->assertArrayNotHasKey('groups', $draft->errors);
        $this->assertSame(['DRAFT001', 'PO-D', 'Factory', ''], [$draft->errors['context']['inbound']['container_no'], $draft->errors['context']['inbound']['reference'], $draft->errors['context']['inbound']['collection']['address']['name'], $draft->errors['context']['inbound']['collection']['address']['suburb']]);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count(), 'a draft creates no order');

        // The list shows 草稿 with 继续编辑; the show page of a draft goes to the form; the form reopens with rows, tick, context and the pickup fieldset open.
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee(__('portal.inbound.statuses.draft'))->assertSee(route('portal.asns.imports.manual.edit', $draft), false)->assertSee(__('portal.inbound.manual.actions.continue'));
        $this->actingAs($user)->get(route('portal.asns.imports.show', $draft))->assertRedirect(route('portal.asns.imports.manual.edit', $draft));
        foreach ([route('portal.asns.imports.manual.edit', $draft), route('portal.asns.imports.manual.create', ['draft' => $draft->id])] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('name="draft_id" value="'.$draft->id.'"', false)->assertSee(__('portal.inbound.manual.draft_note', ['id' => $draft->id, 'time' => $draft->updated_at->format('Y-m-d H:i')]))
                ->assertSee('name="rows[0][consignment_mark]" value="MK-A"', false)->assertSee('name="rows[0][actual_weight_kg]" value="85"', false)
                ->assertSee('name="attached_order_ids[]" value="'.$order->id.'" aria-label="'.$order->order_no.'" checked', false)
                ->assertSee('value="DRAFT001" disabled', false)->assertSee('name="reference" maxlength="60" value="PO-D"', false) // CR #158: the draft's 柜号 shows read-only
                ->assertSee('value="we_collect" checked', false)->assertDontSee('id="collection-fields" hidden disabled', false)
                ->assertSee('name="collection[name]" maxlength="255" value="Factory"', false)->assertSee('name="collection[postcode]" maxlength="4" inputmode="numeric" value="3028"', false);
        }
        // Tenancy: another client never reaches the draft.
        $this->actingAs($stranger)->get(route('portal.asns.imports.manual.edit', $draft))->assertNotFound();
        $this->actingAs($stranger)->get(route('portal.asns.imports.manual.create', ['draft' => $draft->id]))->assertNotFound();
        $this->actingAs($stranger)->get(route('portal.asns.imports.show', $draft))->assertNotFound();
        $this->actingAs($stranger)->post(route('portal.asns.imports.manual.store'), ['action' => 'draft', 'draft_id' => $draft->id, 'rows' => [$this->row()]])->assertNotFound();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(1, OrderImport::query()->withoutGlobalScopes()->count());

        // Submitting the draft with a still-broken row keeps it a draft and shows the row error on the form.
        $this->actingAs($user)->from(route('portal.asns.imports.manual.edit', $draft))->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview', 'draft_id' => $draft->id, 'rows' => [$this->row(['deliver_to_postcode' => ''])], 'attached_order_ids' => [$order->id],
        ])->assertRedirect(route('portal.asns.imports.manual.edit', $draft))->assertSessionHasErrors(['rows.0.deliver_to_postcode' => '第 1 行「邮编」不能为空。']);
        $this->assertSame('draft', $draft->fresh()->status);

        // Completed and submitted: the SAME row moves to pending with groups and the tick; then confirm creates + attaches.
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview', 'draft_id' => $draft->id, 'rows' => [$this->row()], 'attached_order_ids' => [$order->id], 'container_no' => 'DRAFT001', 'inbound_transport' => 'client_delivers',
        ])->assertSessionHasNoErrors()->assertRedirect(route('portal.asns.imports.show', $draft));
        $draft->refresh();
        $this->assertSame(['pending', 1, [$order->id]], [$draft->status, count($draft->errors['groups']), $draft->manualAttachedIds()]);
        $this->assertArrayNotHasKey('collection', $draft->errors['context']['inbound'], 'the pickup request was dropped with 我们自己送到仓库');
        $this->assertSame(1, OrderImport::query()->withoutGlobalScopes()->count(), 'the draft was reused, not copied');
        $this->actingAs($user)->get(route('portal.asns.imports.manual.edit', $draft))->assertNotFound(); // no longer a draft
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $draft))->assertSessionHasNoErrors();
        $this->assertSame(['imported', 1, [['order_id' => $order->id, 'order_no' => $order->order_no]]], [$draft->fresh()->status, count($draft->fresh()->errors['result']['created']), $draft->fresh()->errors['result']['attached']]);
    }

    public function test_a_collection_request_on_a_manual_list_prices_the_attached_orders_lines_too_and_confirms_with_a_plan(): void
    {
        $client = $this->client(['default_markup_percent' => 20]);
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $order = $this->existingOrder($client, $user);
        $before = $this->snapshot($order);

        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview', 'rows' => [$this->row()], 'attached_order_ids' => [$order->id],
        ] + $this->collectionFields($warehouse))->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Factory', $import->errors['context']['inbound']['collection']['address']['name']);

        // The preview prices the typed row AND the attached order's line, and lists both as packages (the order's by its number).
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.collection.plans_title'))->assertSee('Edward Own Fleet')->assertSee('$75.00')->assertSee('name="collection_choice"', false)
            ->assertSee(__('portal.inbound.collection.packages'))->assertSee('600×400×400')->assertSee('450×350×300')->assertSee('<td>'.$order->order_no.'</td>', false);
        $page->assertDontSee('7500')->assertDontSee('cost_cents');
        $request = collect(StubCarrierAdapter::$requests)->where('description', 'estimate')->last();
        $this->assertSame([['蓝牙音箱 / Bluetooth speaker', 10, 8.5], ['电热水壶 / Kettle', 5, 6.5]], array_map(fn (array $i) => [$i['description'], $i['qty'], $i['weight_kg']], $request['items']));

        // Confirm with the plan: the snapshot and the packages (incl. the attached order's line) are recorded; the order itself is untouched.
        $this->confirmCollection($user, $import, $this->planKey('own_fleet', 'standard'))->assertSessionHasNoErrors()->assertRedirect(route('portal.asns.imports.show', $import));
        $import->refresh();
        $collection = $import->errors['context']['inbound']['collection'];
        $this->assertSame(['imported', 'own_fleet', 7500], [$import->status, $collection['preference']['source'], $collection['preference']['customer_price_cents']]);
        $this->assertEquals([
            ['row' => 1, 'package_type' => 'carton', 'qty' => 10, 'weight_kg' => 8.5, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400],
            ['row' => 0, 'package_type' => 'carton', 'qty' => 5, 'weight_kg' => 6.5, 'length_mm' => 450, 'width_mm' => 350, 'height_mm' => 300, 'label' => $order->order_no],
        ], $collection['packages']);
        $this->assertSame([['order_id' => $order->id, 'order_no' => $order->order_no]], $import->errors['result']['attached']);
        $this->assertEquals($before, $this->snapshot($order));
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee(__('portal.inbound.collection.badge'))->assertSee(__('portal.inbound.manual.source'));
    }

    public function test_an_attached_order_taken_by_another_submission_before_confirm_is_refused_and_nothing_is_created(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->existingOrder($client, $user);

        // Two pending manual lists cannot hold the same order: the second preview is refused by order number.
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [$this->row()], 'attached_order_ids' => [$order->id]])->assertSessionHasNoErrors();
        $first = OrderImport::query()->withoutGlobalScopes()->sole();
        $this->actingAs($user)->from(route('portal.asns.imports.manual.create'))->post(route('portal.asns.imports.manual.store'), ['action' => 'preview', 'rows' => [$this->row(['consignment_mark' => 'MK-B'])], 'attached_order_ids' => [$order->id]])
            ->assertRedirect(route('portal.asns.imports.manual.create'))->assertSessionHasErrors(['attached_order_ids' => __('orders.imports.errors.order_not_attachable', ['order_no' => $order->order_no])]);
        $this->assertSame(1, OrderImport::query()->withoutGlobalScopes()->count());

        // Customer service generates the ASN for the order meanwhile: confirming the pending list is refused in Chinese and creates nothing.
        $this->actingAs($this->staff('customer_service'))->post(route('orders.inbound.store'), ['order_ids' => [$order->id], 'warehouse_id' => $this->warehouse()->id, 'inbound_type' => 'loose_truck'])->assertSessionHasNoErrors();
        $this->assertSame(1, Asn::query()->withoutGlobalScopes()->count());
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $first))->assertRedirect(route('portal.asns.imports.show', $first))
            ->assertSessionHasErrors(['attached_order_ids' => __('orders.imports.errors.order_not_attachable', ['order_no' => $order->order_no])]);
        $first->refresh();
        $this->assertSame(['pending', []], [$first->status, $first->errors['result']['created']]);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count(), 'the typed row was not created either — the transaction rolled back');
        $this->actingAs($user)->get(route('portal.asns.imports.show', $first))->assertOk()->assertSee(__('orders.imports.errors.order_not_attachable', ['order_no' => $order->order_no]));
    }
}
