<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** CHANGE_REQUESTS #158: the client no longer types a 柜号 — a from_stock list is numbered CTN-<date>-NNNN in sequence when it is read. */
class PortalContainerNumberTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/imports/consolidation_dsf.xlsx';

    private function upload(): UploadedFile
    {
        return new UploadedFile(base_path(self::FIXTURE), 'consolidation.xlsx', null, null, true);
    }

    public function test_a_list_without_a_container_number_is_numbered_in_sequence_and_the_number_reaches_the_preview_the_list_and_待建预报(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $stem = 'CTN-'.today()->format('Ymd').'-';

        // The upload form has no 柜号 input any more, only the note; the manual form shares the partial.
        $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()->assertDontSee('name="container_no"', false)->assertSee(__('portal.inbound.container_auto'))->assertSee(__('portal.inbound.container_auto_hint'));
        $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()->assertDontSee('name="container_no"', false);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'container_size' => '40'])->assertSessionHasNoErrors();
        $first = OrderImport::query()->latest('id')->first();
        $this->assertSame($stem.'0001', $first->container_no);
        $this->assertSame($stem.'0001', $first->errors['context']['inbound']['container_no']);
        $this->assertTrue($first->errors['context']['inbound']['container_no_generated']);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $first))->assertOk()->assertSee($stem.'0001');
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee($stem.'0001');

        // The next list of the day is the next number; a 提货直送 list gets none; a number given through the API is kept.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'force' => 1])->assertSessionHasNoErrors();
        $second = OrderImport::query()->latest('id')->first();
        $this->assertSame($stem.'0002', $second->container_no);
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload(), 'order_type' => 'pickup_deliver', 'requested_date' => today()->addDays(3)->toDateString(),
            'pickup' => ['name' => 'Shipper', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015']])->assertSessionHasNoErrors();
        $this->assertNull(OrderImport::query()->latest('id')->first()->container_no);
        $token = app(OrderApiTokenService::class)->issue((int) $client->id, 'sys', null)['plain'];
        $this->withToken($token)->post(route('orders.api.imports.store'), ['manifest' => $this->upload(), 'container_no' => 'cosu0000001', 'force' => '1'])->assertStatus(202);
        $api = OrderImport::query()->latest('id')->first();
        $this->assertSame(['COSU0000001', 'COSU0000001'], [$api->container_no, $api->errors['context']['inbound']['container_no']]);
        $this->assertArrayNotHasKey('container_no_generated', $api->errors['context']['inbound']);

        // After confirming the first list, customer service sees the generated number in 待建预报 and the 选中并填入 button carries it.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $first))->assertRedirect();
        $this->actingAs($this->staff('customer_service'))->get(route('orders.inbound.index'))->assertOk()->assertSee($stem.'0001')->assertSee('data-container-no="'.$stem.'0001"', false);
    }
}
