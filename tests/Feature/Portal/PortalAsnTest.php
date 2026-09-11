<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Platform\Models\Document;
use App\Modules\Portal\Models\PortalAsnSubmission;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 客户自助预报入库 (lead request 2026-09-11, CHANGE_REQUESTS #116): the client uploads its packing list with container and ETA in
 * the portal (or pushes the same through the API) and the ASN exists at once — created through Warehouse's own services with
 * created_by_type = client — waiting for customer service to confirm. Staff flows and warehouse rules are untouched.
 */
class PortalAsnTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function workbook(): UploadedFile
    {
        return new UploadedFile(base_path('data/需派送货物清单.xlsx'), '需派送货物清单.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_client_submits_a_packing_list_and_customer_service_confirms_the_pending_asn(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();

        $this->actingAs($user)->get(route('portal.asns.index'))->assertOk()->assertSee(__('portal.asns.empty'))->assertSee(route('portal.asns.create'));
        $this->actingAs($user)->get(route('portal.asns.create'))->assertOk()->assertSee('name="packing_list"', false)->assertSee(route('portal.asns.template'));
        $template = $this->actingAs($user)->get(route('portal.asns.template'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('唛头', $template->getContent());

        // Chinese validation: a container booking needs its container number and the packing list.
        $this->actingAs($user)->from(route('portal.asns.create'))
            ->post(route('portal.asns.store'), ['warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'expected_date' => today()->addDays(10)->toDateString()])
            ->assertRedirect(route('portal.asns.create'))
            ->assertSessionHasErrors(['container_no' => trans('portal.validation.messages')['container_no.required_if'], 'packing_list' => trans('portal.validation.messages')['packing_list.required']]);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());

        $response = $this->actingAs($user)->post(route('portal.asns.store'), [
            'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'container_no' => 'MSKU1234567', 'container_size' => '40', 'unpack_mode' => 'loose',
            'expected_date' => today()->addDays(10)->toDateString(), 'reference' => 'PO-2026-09', 'notes' => '周五前到', 'packing_list' => $this->workbook(),
        ]);
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $response->assertRedirect(route('portal.asns.show', $asn))->assertSessionHas('status', __('portal.asns.messages.submitted', ['asn_no' => $asn->asn_no, 'rows' => 135, 'errors' => 10]));
        $this->assertSame(['client', $client->id, $warehouse->id, 'booked', 'container', $user->id], [$asn->created_by_type, $asn->client_id, $asn->warehouse_id, $asn->status, $asn->inbound_type, $asn->created_by]);
        $this->assertTrue($asn->isPendingClientConfirmation());
        $this->assertSame('PO-2026-09', $asn->job->reference);
        $this->assertSame(['MSKU1234567', '40', 135], [$asn->containers()->value('container_no'), $asn->containers()->value('size'), (int) $asn->containers()->value('line_count')]);
        $this->assertSame(135, $asn->lines()->count(), 'the real workbook, parsed by the shared manifest parser');
        $submission = PortalAsnSubmission::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['portal', $asn->id, $user->id, 135], [$submission->channel, $submission->asn_id, $submission->user_id, $submission->line_count]);

        // Detail and list for the client; nothing for another client; nothing for staff on the portal.
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee($asn->asn_no)->assertSee('MSKU1234567')->assertSee('PO-2026-09')->assertSee(__('portal.asns.pending_badge'))
            ->assertSee(__('portal.asns.imports.rows', ['rows' => 135]))->assertSee(__('portal.asns.imports.errors', ['count' => 10]))
            ->assertSee(__('portal.asns.replace_hint'))->assertSee(__('portal.asns.receipts.none'));
        $this->actingAs($user)->get(route('portal.asns.index'))->assertOk()->assertSee($asn->asn_no)->assertSee(__('portal.asns.pending_badge'));
        $this->actingAs($user)->get(route('portal.asns.index', ['q' => 'MSKU']))->assertOk()->assertSee($asn->asn_no);
        $other = $this->clientUser($this->client());
        $this->actingAs($other)->get(route('portal.asns.show', $asn))->assertNotFound();
        $this->actingAs($other)->get(route('portal.asns.index'))->assertOk()->assertDontSee($asn->asn_no);
        $this->actingAs($this->staff('customer_service'))->get(route('portal.asns.index'))->assertForbidden();

        // Customer service: nav badge, 待确认 filter, banner with the submitter, confirm (warehouse supervisor may not).
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('warehouse.asns.index', ['pending' => 1]))->assertOk()
            ->assertSee($asn->asn_no)->assertSee(__('warehouse.asns.client_pending_badge'))
            ->assertSee('title="'.__('warehouse.asns.filter_pending').'">1<', false);
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.confirm_client'))->assertSee($user->name);
        $this->actingAs($this->staff('warehouse_supervisor'))->post(route('warehouse.asns.confirm_client', $asn))->assertForbidden();
        $this->actingAs($cs)->from(route('warehouse.asns.show', $asn))->post(route('warehouse.asns.confirm_client', $asn))
            ->assertRedirect(route('warehouse.asns.show', $asn))->assertSessionHas('status', __('warehouse.asns.client_confirmed', ['no' => $asn->asn_no]));
        $asn->refresh();
        $this->assertNotNull($asn->client_confirmed_at);
        $this->assertSame($cs->id, $asn->client_confirmed_by);
        $this->assertFalse($asn->isPendingClientConfirmation());
        $this->actingAs($cs)->get(route('warehouse.asns.index', ['pending' => 1]))->assertOk()->assertDontSee(route('warehouse.asns.show', $asn), false); // the row is gone (the flash still names the ASN)
        $this->actingAs($cs)->from(route('warehouse.asns.show', $asn))->post(route('warehouse.asns.confirm_client', $asn))
            ->assertSessionHasErrors(['confirm_client' => __('warehouse.asns.errors.already_confirmed', ['no' => $asn->asn_no])]);

        // The portal shows the confirmation; the packing list can no longer be replaced.
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee(__('portal.asns.confirmed_badge'))->assertDontSee(__('portal.asns.replace_hint'));
        $this->actingAs($user)->from(route('portal.asns.show', $asn))->post(route('portal.asns.import', $asn), ['packing_list' => $this->workbook()])
            ->assertRedirect(route('portal.asns.show', $asn))
            ->assertSessionHasErrors(['packing_list' => __('warehouse.asns.errors.draft_lines_locked', ['no' => $asn->asn_no])]);
        $this->assertSame(135, $asn->lines()->count());
    }

    public function test_a_draft_packing_list_is_replaced_until_goods_arrive_and_receipts_are_downloadable(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $this->actingAs($user)->post(route('portal.asns.store'), ['warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'expected_date' => today()->toDateString(), 'packing_list' => $this->workbook()])->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(0, $asn->containers()->count());
        $firstIds = $asn->lines()->pluck('id')->all();
        $this->assertCount(135, $firstIds);

        // Replace while still a draft: the old lines go, the new file's lines come in — never doubled.
        $this->actingAs($user)->post(route('portal.asns.import', $asn), ['packing_list' => $this->workbook()])
            ->assertRedirect(route('portal.asns.show', $asn))->assertSessionHas('status', __('portal.asns.messages.replaced', ['rows' => 135, 'errors' => 10]));
        $this->assertSame(135, $asn->lines()->count());
        $this->assertSame(0, $asn->lines()->whereIn('id', $firstIds)->count(), 'the draft lines were removed, not appended to');

        // The warehouse receives a line without waiting for any confirmation (rules unchanged) → the list is history; the 入库单 appears in the portal.
        $line = $asn->lines()->orderBy('id')->firstOrFail();
        $operator = $this->staff('warehouse_operator');
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $line->expected_cartons, 'units' => [['unit_type' => 'carton', 'carton_qty' => $line->expected_cartons]]], $this->location($warehouse, 'receiving'), $operator->id);
        $receipt = GoodsReceipt::query()->withoutGlobalScopes()->sole();
        $this->actingAs($user)->from(route('portal.asns.show', $asn))->post(route('portal.asns.import', $asn), ['packing_list' => $this->workbook()])
            ->assertSessionHasErrors(['packing_list' => __('warehouse.asns.errors.draft_lines_locked', ['no' => $asn->asn_no])]);
        $this->assertSame(135, $asn->lines()->count());
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee($receipt->receipt_no)->assertSee(__('portal.asns.receipts.draft'))->assertDontSee(__('portal.asns.replace_hint'));

        config(['erp.pdf_cjk_font' => storage_path('fonts/cjk.ttf')]);
        app(GoodsReceiptService::class)->complete($receipt, $operator->id);
        $document = Document::query()->withoutGlobalScopes()->findOrFail($receipt->fresh()->pdf_document_id);
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee(route('portal.documents.download', $document))->assertSee(__('portal.asns.receipts.download'));
        $this->actingAs($user)->get(route('portal.documents.download', $document))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->clientUser($this->client()))->get(route('portal.documents.download', $document))->assertNotFound();
    }

    public function test_client_systems_push_asns_through_the_api_with_idempotent_replay(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $plain = app(OrderApiTokenService::class)->issue($client->id, 'Client ERP prod', null)['plain'];
        $payload = [
            'inbound_type' => 'container', 'expected_date' => today()->addDays(7)->toDateString(), 'reference' => 'API-001', 'warehouse_code' => $warehouse->code,
            'containers' => [['container_no' => 'TCLU7654321', 'size' => '20', 'unpack_mode' => 'pallet', 'gross_weight_kg' => 8000]],
            'lines' => [
                ['container_no' => 'TCLU7654321', 'consignment_mark' => 'API-A', 'description' => 'Bluetooth speakers', 'expected_cartons' => 40, 'package_type' => 'carton',
                    'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_address' => '1 Warehouse Rd', 'deliver_to_suburb' => 'Moorebank', 'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'fba_reference' => 'FBA15API001', 'weight_kg' => 12.5],
                ['container_no' => 'TCLU7654321', 'consignment_mark' => 'API-B', 'description' => 'Kettles', 'expected_cartons' => 12],
            ],
        ];

        $this->postJson(route('portal.api.asns.store'), $payload)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        $this->withToken($plain)->postJson(route('portal.api.asns.store'), ['inbound_type' => 'container', 'lines' => []])->assertStatus(422)->assertJsonValidationErrors(['containers', 'lines']);
        $mismatch = $this->withToken($plain)->postJson(route('portal.api.asns.store'), array_replace($payload, ['lines' => [['container_no' => 'OTHER', 'description' => 'x', 'expected_cartons' => 1]]]))->assertStatus(422);
        $this->assertSame([__('portal.asns.api.errors.container_unknown', ['no' => 'OTHER'])], $mismatch->json('errors')['lines.0.container_no']);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());

        $created = $this->withToken($plain)->withHeader('Idempotency-Key', 'asn-push-1')->postJson(route('portal.api.asns.store'), $payload)
            ->assertCreated()->assertJsonPath('confirmation', 'pending')->assertJsonPath('lines', 2)->assertJsonPath('replayed', false)->assertJsonPath('status', 'booked');
        $asn = Asn::query()->withoutGlobalScopes()->findOrFail($created->json('asn_id'));
        $this->assertSame(['client', $client->id, $warehouse->id, 'API-001', null], [$asn->created_by_type, $asn->client_id, $asn->warehouse_id, $asn->job->reference, $asn->created_by]);
        $this->assertSame($asn->job->job_no, $created->json('job_no'));
        $this->assertSame(['TCLU7654321', '20', 'pallet', 2], [$asn->containers()->value('container_no'), $asn->containers()->value('size'), $asn->containers()->value('unpack_mode'), (int) $asn->containers()->value('line_count')]);
        $first = $asn->lines()->orderBy('id')->firstOrFail();
        $this->assertSame(['API-A', 'Moorebank', 'FBA15API001', 40, $asn->containers()->value('id')], [$first->consignment_mark, $first->deliver_to_suburb, $first->fba_reference, $first->expected_cartons, $first->container_id]);
        $this->assertTrue($first->hasCompleteDelivery());

        $this->withToken($plain)->withHeader('Idempotency-Key', 'asn-push-1')->postJson(route('portal.api.asns.store'), $payload)
            ->assertOk()->assertJsonPath('asn_id', $asn->id)->assertJsonPath('replayed', true);
        $this->assertSame(1, Asn::query()->withoutGlobalScopes()->count(), 'a replay never creates a second ASN');
        $submission = PortalAsnSubmission::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['api', 'asn-push-1', 2], [$submission->channel, $submission->idempotency_key, $submission->line_count]);
        $this->assertNotNull($submission->token_id);

        // Another key → another ASN; an unknown warehouse code → 422; both submissions wait for customer service.
        $this->withToken($plain)->withHeader('Idempotency-Key', 'asn-push-2')
            ->postJson(route('portal.api.asns.store'), array_replace($payload, ['containers' => [['container_no' => 'TCLU0000001', 'size' => '40']], 'lines' => [['description' => 'Mugs', 'expected_cartons' => 3]]]))->assertCreated();
        $this->withToken($plain)->postJson(route('portal.api.asns.store'), array_replace($payload, ['warehouse_code' => 'NOPE']))
            ->assertStatus(422)->assertJsonPath('errors.warehouse_code.0', __('portal.asns.api.errors.warehouse_unknown'));
        $this->assertSame(2, app(AsnService::class)->pendingClientConfirmationCount());
        $this->actingAs($this->staff('admin'))->get(route('warehouse.asns.index', ['pending' => 1]))->assertOk()->assertSee($asn->asn_no)->assertSee(__('warehouse.asns.client_pending_badge'));
    }
}
