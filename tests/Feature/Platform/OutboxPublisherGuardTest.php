<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Services\DatabaseOutboxPublisher;
use App\Support\Outbox\OutboxPublisher;
use LogicException;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** No RefreshDatabase here on purpose: it would wrap the test in a transaction and hide the guard. */
class OutboxPublisherGuardTest extends TestCase
{
    public function test_publishing_outside_a_transaction_is_refused(): void
    {
        $this->assertInstanceOf(DatabaseOutboxPublisher::class, app(OutboxPublisher::class));

        $this->expectException(LogicException::class);
        app(OutboxPublisher::class)->publish(new TestEvent);
    }
}
