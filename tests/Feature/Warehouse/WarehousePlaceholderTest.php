<?php

namespace Tests\Feature\Warehouse;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class WarehousePlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->actingAs($this->staff('warehouse_operator'))
            ->get('/warehouse')
            ->assertOk()
            ->assertSee(__('warehouse.title'));
    }
}
