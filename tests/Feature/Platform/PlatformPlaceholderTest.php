<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

class PlatformPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/jobs')
            ->assertOk()
            ->assertSee(__('platform.title'));
    }
}
