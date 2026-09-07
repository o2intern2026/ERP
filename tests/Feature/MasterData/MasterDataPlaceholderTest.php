<?php

namespace Tests\Feature\MasterData;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class MasterDataPlaceholderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->actingAs($this->staff('admin'))
            ->get('/admin/clients')
            ->assertOk()
            ->assertSee(__('masterdata.title'));
    }
}
