<?php

namespace Tests\Feature\Orders;

use Tests\TestCase;

class OrdersPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/orders')
            ->assertOk()
            ->assertSee(__('orders.title'));
    }
}
