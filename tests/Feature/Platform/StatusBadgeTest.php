<?php

namespace Tests\Feature\Platform;

use App\Support\Ui\StatusBadge;
use Tests\TestCase;

/** Every status shows as a coloured badge (tester feedback 2026-09-10). */
class StatusBadgeTest extends TestCase
{
    public function test_tones_follow_the_status_set_then_the_generic_word_list(): void
    {
        $this->assertSame('ok', StatusBadge::tone('orders.statuses.operational.', 'delivered'));
        $this->assertSame('danger', StatusBadge::tone('orders.statuses.operational.', 'cancelled'));
        $this->assertSame('info', StatusBadge::tone('orders.statuses.operational.', 'received'));
        $this->assertSame('warn', StatusBadge::tone('orders.statuses.operational.', 'picking'));
        $this->assertSame('ok', StatusBadge::tone('transport.statuses.', 'delivered'));
        $this->assertSame('danger', StatusBadge::tone('some.unknown_statuses.', 'failed')); // generic list
        $this->assertSame('warn', StatusBadge::tone('some.unknown_statuses.', 'whatever_else')); // default: in progress
    }

    public function test_render_escapes_the_label_and_uses_the_chinese_lang_value(): void
    {
        $html = (string) StatusBadge::render('orders.statuses.operational.', 'dispatched');
        $this->assertSame('<span class="badge" data-tone="info">'.__('orders.statuses.operational.dispatched').'</span>', $html);
        $this->assertStringContainsString('&lt;b&gt;', (string) StatusBadge::render('x.statuses.', 'weird', '<b>'));
        $this->assertStringContainsString('—', (string) StatusBadge::render('x.statuses.', null));
    }
}
