<?php

namespace Tests\Feature\MasterData;

use Tests\TestCase;

class MasterDataPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/admin/clients')
            ->assertOk()
            ->assertSee(__('masterdata.title'));
    }
}
