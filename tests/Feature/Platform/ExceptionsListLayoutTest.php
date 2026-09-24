<?php

namespace Tests\Feature\Platform;

use App\Support\Contracts\ExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** CHANGE_REQUESTS #154: a long exception message wraps inside a capped column instead of pushing 标记解决 off screen. */
class ExceptionsListLayoutTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_the_message_and_action_cells_wrap_and_the_stylesheet_stays_within_its_cap(): void
    {
        $client = $this->client();
        app(ExceptionService::class)->raise('discrepancy', 'warehouse', ['client_id' => $client->id, 'message' => str_repeat('唛头 CW1001 的第 3 行实收 5 箱少于预报 6 箱，原因：短装；', 6)]);

        $page = $this->actingAs($this->staff('admin'))->get(route('platform.exceptions.index'))->assertOk()
            ->assertSee('<td class="wrap">', false)->assertSee('<td class="wrap" style="min-width:17rem">', false)->assertSee(__('platform.exceptions.resolve'));
        $this->assertDoesNotMatchRegularExpression('/platform\.exceptions\./', $page->getContent());

        $css = file(public_path('css/app.css'));
        $this->assertLessThanOrEqual(100, count($css), 'AGENTS.md: one app.css of at most 100 lines');
        $this->assertStringContainsString('table.dense td.wrap { white-space: normal;', implode('', $css));
    }
}
