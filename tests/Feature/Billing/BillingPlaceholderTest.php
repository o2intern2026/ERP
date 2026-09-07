<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

class BillingPlaceholderTest extends TestCase
{
    public function test_placeholder_page_renders_with_zh_strings(): void
    {
        $this->get('/billing')
            ->assertOk()
            ->assertSee(__('billing.title'));
    }
}
