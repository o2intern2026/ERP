<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Orders\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #158 (revised 2026-09-25): the portal list forms open with a visibly generated 柜号 (CTN-XXXXXX) the client may overwrite;
 * 重新生成 fetches another; what the client submits is kept, a blank or a generated number another list already carries gets a fresh one.
 */
class PortalContainerNumberTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/imports/consolidation_dsf.xlsx';

    private function upload(): UploadedFile
    {
        return new UploadedFile(base_path(self::FIXTURE), 'consolidation.xlsx', null, null, true);
    }

    public function test_the_form_opens_with_a_generated_editable_container_number_and_the_list_keeps_what_the_client_submits(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $pattern = OrderImportService::GENERATED_CONTAINER_PATTERN;

        // Upload and manual forms: an editable 柜号 box prefilled with a generated number, the 重新生成 button, the hint; no raw lang key.
        $page = $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee(__('portal.inbound.container_generate'))->assertSee(__('portal.inbound.container_auto_hint'))->assertSee(route('portal.asns.imports.container_no'), false);
        $this->assertSame(1, preg_match('/name="container_no" id="container-no" maxlength="20" value="(CTN-[2-9A-HJ-NP-Z]{6})"/', $page->getContent(), $m), 'the box opens with a generated number');
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\./', $page->getContent());
        $manual = $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="container_no" id="container-no" maxlength="20" value="(CTN-[2-9A-HJ-NP-Z]{6})"/', $manual, $m2));
        $this->assertNotSame($m[1], $m2[1], 'every opening shows a fresh number');

        // 重新生成: JSON with another generated number (client users only).
        $json = $this->actingAs($user)->getJson(route('portal.asns.imports.container_no'))->assertOk()->json('container_no');
        $this->assertMatchesRegularExpression($pattern, $json);
        $this->actingAs($this->staff('customer_service'))->getJson(route('portal.asns.imports.container_no'))->assertForbidden();

        // Submitting the generated number keeps it; the client's own shipping-line number is kept (upper-cased); a blank gets a fresh one.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'container_no' => $json, 'container_size' => '40'])->assertSessionHasNoErrors();
        $first = OrderImport::query()->latest('id')->first();
        $this->assertSame([$json, $json], [$first->container_no, $first->errors['context']['inbound']['container_no']]);
        $this->assertArrayNotHasKey('container_no_generated', $first->errors['context']['inbound']);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $first))->assertOk()->assertSee($json);
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee($json);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'container_no' => 'cosu0000001'])->assertSessionHasNoErrors();
        $this->assertSame('COSU0000001', OrderImport::query()->latest('id')->first()->container_no);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'container_no' => ''])->assertSessionHasNoErrors();
        $blank = OrderImport::query()->latest('id')->first();
        $this->assertMatchesRegularExpression($pattern, $blank->container_no);
        $this->assertTrue($blank->errors['context']['inbound']['container_no_generated']);

        // A generated number another list already carries is replaced (never two lists on one generated 柜号); a real number may repeat.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'container_no' => $json])->assertSessionHasNoErrors();
        $dup = OrderImport::query()->latest('id')->first();
        $this->assertNotSame($json, $dup->container_no);
        $this->assertSame($json, $dup->errors['context']['inbound']['container_no_adjusted_from']);
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'container_no' => 'COSU0000001'])->assertSessionHasNoErrors();
        $this->assertSame('COSU0000001', OrderImport::query()->latest('id')->first()->container_no, 'the shipping line\'s number may cover several lists');

        // A 提货直送 list has no 柜号; the API keeps a given number too.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'order_type' => 'pickup_deliver', 'requested_date' => today()->addDays(3)->toDateString(),
            'pickup' => ['name' => 'Shipper', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015']])->assertSessionHasNoErrors();
        $this->assertNull(OrderImport::query()->latest('id')->first()->container_no);
        $token = app(OrderApiTokenService::class)->issue((int) $client->id, 'sys', null)['plain'];
        $this->withToken($token)->post(route('orders.api.imports.store'), ['manifest' => $this->upload(), 'container_no' => 'msku1234567', 'force' => '1'])->assertStatus(202);
        $this->assertSame('MSKU1234567', OrderImport::query()->latest('id')->first()->container_no);

        // After confirming the first list, customer service sees the number in 待建预报 and the 选中并填入 button carries it.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $first))->assertRedirect();
        $this->actingAs($this->staff('customer_service'))->get(route('orders.inbound.index'))->assertOk()->assertSee($json)->assertSee('data-container-no="'.$json.'"', false);
    }
}
