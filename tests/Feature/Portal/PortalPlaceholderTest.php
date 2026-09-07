<?php

namespace Tests\Feature\Portal;

use Tests\TestCase;

class PortalPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/portal')
            ->assertOk()
            ->assertSee(__('portal.title'));
    }
}
