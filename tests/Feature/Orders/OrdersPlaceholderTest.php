<?php

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class OrdersPlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->actingAs($this->staff('customer_service'))
            ->get('/orders')
            ->assertOk()
            ->assertSee(__('orders.title'));
    }
}
