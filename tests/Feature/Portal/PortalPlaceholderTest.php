<?php

namespace Tests\Feature\Portal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class PortalPlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_for_client_users_and_staff(): void
    {
        $this->actingAs($this->clientUser())->get('/portal')->assertOk()->assertSee(__('portal.title'));
        $this->actingAs($this->staff('customer_service'))->get('/portal')->assertOk();
    }
}
