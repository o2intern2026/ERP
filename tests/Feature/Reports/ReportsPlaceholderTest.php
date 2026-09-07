<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class ReportsPlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->actingAs($this->staff('admin'))
            ->get('/reports')
            ->assertOk()
            ->assertSee(__('reports.title'));
    }
}
