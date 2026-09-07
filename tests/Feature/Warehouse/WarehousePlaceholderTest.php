<?php

namespace Tests\Feature\Warehouse;

use Tests\TestCase;

class WarehousePlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/warehouse')
            ->assertOk()
            ->assertSee(__('warehouse.title'));
    }
}
