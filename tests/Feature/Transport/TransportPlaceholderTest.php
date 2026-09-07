<?php

namespace Tests\Feature\Transport;

use Tests\TestCase;

class TransportPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/transport')
            ->assertOk()
            ->assertSee(__('transport.title'));
    }

    public function test_driver_page_renders_under_driver_prefix(): void
    {
        $this->get('/driver')
            ->assertOk()
            ->assertSee(__('transport.driver_title'));
    }
}
