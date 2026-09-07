<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class PlatformPlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->actingAs($this->staff('admin'))
            ->get('/jobs')
            ->assertOk()
            ->assertSee(__('platform.title'));
    }
}
