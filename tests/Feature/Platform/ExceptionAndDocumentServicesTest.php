<?php

namespace Tests\Feature\Platform;

use App\Support\Contracts\DocumentService;
use App\Support\Contracts\ExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Platform services other seats write through (contracts/services.md §6–§7). */
class ExceptionAndDocumentServicesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_holds_are_exceptions_and_block_only_their_client(): void
    {
        $a = $this->client();
        $b = $this->client();
        $service = app(ExceptionService::class);

        $id = $service->raise('hold', 'orders', ['client_id' => $a->id, 'hold_type' => 'financial', 'message' => 'overdue', 'order_id' => 5]);

        $this->assertDatabaseHas('exceptions', ['id' => $id, 'type' => 'hold', 'hold_type' => 'financial', 'status' => 'open', 'client_id' => $a->id]);
        $this->assertTrue($service->hasActiveHold('financial', $a->id));
        $this->assertTrue($service->hasActiveHold('financial', $a->id, 5));
        $this->assertFalse($service->hasActiveHold('financial', $a->id, 6));
        $this->assertFalse($service->hasActiveHold('financial', $b->id));
        $this->assertFalse($service->hasActiveHold('stock', $a->id));

        $finance = $this->staff('finance');
        $service->resolve($id, $finance->id, 'paid in full');

        $this->assertFalse($service->hasActiveHold('financial', $a->id));
        $this->assertDatabaseHas('exceptions', ['id' => $id, 'status' => 'resolved', 'released_by' => $finance->id, 'release_reason' => 'paid in full']);
    }

    public function test_a_client_wide_hold_covers_every_order(): void
    {
        $a = $this->client();
        app(ExceptionService::class)->raise('hold', 'billing', ['client_id' => $a->id, 'hold_type' => 'financial']);

        $this->assertTrue(app(ExceptionService::class)->hasActiveHold('financial', $a->id, 999));
    }

    public function test_enum_values_are_enforced(): void
    {
        $service = app(ExceptionService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->raise('hold', 'orders', ['hold_type' => 'not_a_hold_type']);
    }

    public function test_unknown_exception_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ExceptionService::class)->raise('typo', 'orders');
    }

    public function test_documents_are_attached_with_visibility(): void
    {
        $client = $this->client();
        $id = app(DocumentService::class)->attach('pod', 'shipment', 42, 'pods/42.pdf', ['client_id' => $client->id, 'client_visible' => true, 'mime' => 'application/pdf']);

        $this->assertDatabaseHas('documents', ['id' => $id, 'type' => 'pod', 'related_type' => 'shipment', 'related_id' => 42, 'client_visible' => 1]);

        $this->expectException(InvalidArgumentException::class);
        app(DocumentService::class)->attach('selfie', 'shipment', 42, 'x.jpg');
    }
}
