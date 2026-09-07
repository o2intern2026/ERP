<?php

namespace Tests\Feature\Transport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class TransportPlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->actingAs($this->staff('transport_operator'))->get('/transport')->assertOk()->assertSee(__('transport.title'));
    }

    public function test_driver_page_renders_under_driver_prefix(): void
    {
        $this->actingAs($this->staff('transport_operator'))->get('/driver')->assertOk()->assertSee(__('transport.driver_title'));
    }
}
