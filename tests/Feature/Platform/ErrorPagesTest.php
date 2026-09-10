<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Services\ApprovalService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * i18n/zh sweep (A1, CHANGE_REQUESTS #107): the framework error pages and uncaught business refusals render in Chinese —
 * never Laravel's English defaults ("Not Found", "Page Expired", "Server Error", "CSRF token mismatch.").
 */
class ErrorPagesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_unknown_urls_render_the_chinese_404_page_for_guests_and_staff(): void
    {
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee(__('platform.errors.not_found.title'))
            ->assertSee(__('platform.errors.home'))
            ->assertDontSee('Not Found');

        $this->actingAs($this->staff('customer_service'))->get('/jobs/999999')->assertNotFound()
            ->assertSee(__('platform.errors.not_found.title'))
            ->assertDontSee('No query results');
    }

    public function test_a_chinese_abort_reason_is_shown_and_english_framework_text_is_not(): void
    {
        config(['erp.allow_signup' => false]);

        $this->get('/register')->assertNotFound()->assertSee(__('platform.auth.signup_closed'));
    }

    public function test_expired_forms_render_the_chinese_419_page_asking_to_retry(): void
    {
        Route::middleware('web')->get('/_test/expired', fn () => abort(419, 'CSRF token mismatch.'));

        $this->get('/_test/expired')->assertStatus(419)
            ->assertSee(__('platform.errors.page_expired.title'))
            ->assertSee(__('platform.errors.page_expired.hint'))
            ->assertSee(__('platform.errors.retry'))
            ->assertDontSee('Page Expired')
            ->assertDontSee('CSRF token mismatch');
    }

    public function test_429_500_and_503_render_in_chinese(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_test/throttled', fn () => abort(429, 'Too Many Attempts.', ['Retry-After' => 42]));
        Route::middleware('web')->get('/_test/boom', fn () => throw new \RuntimeException('database has gone away'));
        Route::middleware('web')->get('/_test/down', fn () => abort(503));

        $this->get('/_test/throttled')->assertStatus(429)
            ->assertSee(__('platform.errors.too_many_requests.title'))
            ->assertSee(__('platform.errors.too_many_requests.wait', ['seconds' => 42]))
            ->assertDontSee('Too Many');

        $this->get('/_test/boom')->assertStatus(500)
            ->assertSee(__('platform.errors.server_error.title'))
            ->assertDontSee('Server Error')
            ->assertDontSee('database has gone away');

        $this->get('/_test/down')->assertStatus(503)
            ->assertSee(__('platform.errors.unavailable.title'))
            ->assertDontSee('Service Unavailable');
    }

    public function test_an_uncaught_rule_violation_on_a_form_post_goes_back_with_the_chinese_message(): void
    {
        config(['app.debug' => false]);
        Route::middleware(['web', 'auth'])->post('/_test/refuse', fn () => throw new RuleViolation('Only pending approvals can be cancelled.', 'platform.approvals.errors.not_pending'));
        Route::middleware(['web', 'auth'])->get('/_test/refuse', fn () => throw new RuleViolation('Only pending approvals can be cancelled.', 'platform.approvals.errors.not_pending'));

        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->from('/jobs')->post('/_test/refuse')
            ->assertRedirect('/jobs')
            ->assertSessionHasErrors(['rule' => __('platform.approvals.errors.not_pending')]);
        $this->actingAs($cs)->get('/jobs')->assertOk()
            ->assertSee(__('platform.approvals.errors.not_pending'))
            ->assertDontSee('Only pending approvals');

        // A GET that trips a rule is a programming error: the (Chinese) 500 page, not a redirect loop.
        $this->actingAs($cs)->get('/_test/refuse')->assertStatus(500)->assertSee(__('platform.errors.server_error.title'));
        $this->actingAs($cs)->postJson('/_test/refuse')->assertStatus(500);
    }

    public function test_a_platform_service_refusal_renders_in_chinese_on_the_page(): void
    {
        $requester = $this->staff('finance');
        $approval = app(ApprovalService::class)->request('credit_note', 'credit_note', 12, $requester, ['client_id' => $this->client()->id, 'request_note' => 'damaged goods']);

        $this->actingAs($requester)->from('/admin/approvals')->post(route('platform.approvals.approve', $approval))->assertRedirect('/admin/approvals');
        $this->actingAs($requester)->get('/admin/approvals')->assertOk()
            ->assertSee(__('platform.approvals.errors.self_decide'))
            ->assertDontSee('second person');
    }
}
