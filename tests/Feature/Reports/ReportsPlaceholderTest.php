<?php

namespace Tests\Feature\Reports;

use Tests\TestCase;

class ReportsPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/reports')
            ->assertOk()
            ->assertSee(__('reports.title'));
    }
}
