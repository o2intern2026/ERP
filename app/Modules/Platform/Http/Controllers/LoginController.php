<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\MasterData\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A1: session login. Inactive users cannot sign in; client users land on the portal, staff on the Job workbench — except the
 * driver, who lands on /driver (CR #137, audit TMS-15: the driver executes, CR #130). CR #137 (audit ADMIN-13): five wrong
 * attempts per minute per email + IP, then a Chinese refusal. CR #137 (audit GAP-03): a deactivated login, or a login whose
 * client was deactivated, is told so instead of "wrong password".
 */
class LoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function show(): View
    {
        return view('platform::auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = self::throttleKey($credentials['email'], $request->ip());
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => __('platform.auth.throttled', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('platform.auth.'.$this->refusalFor($credentials))]);
        }

        // GAP-03: users of a client deactivated before this fix may still carry is_active = true — the client's status decides.
        if ($request->user()->isClientUser() && ! self::clientIsActive($request->user())) {
            Auth::logout();
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('platform.auth.inactive')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(self::landingUrl($request->user()));
    }

    /** "/" — the same landing as after login (guests go on to /jobs, which sends them to /login). */
    public function home(Request $request): RedirectResponse
    {
        $user = $request->user();

        return redirect()->to($user === null ? route('platform.index') : self::landingUrl($user));
    }

    /** Client users → portal; a user whose only staff role is transport_operator → the driver page; every other staff role → the Job workbench. */
    public static function landingUrl(User $user): string
    {
        if ($user->isClientUser()) {
            return route('portal.index');
        }
        if (self::isDriverOnly($user)) {
            return route('transport.driver');
        }

        return route('platform.index');
    }

    public static function isDriverOnly(User $user): bool
    {
        $roles = $user->getRoleNames();

        return $roles->contains('transport_operator') && $roles->diff(['transport_operator'])->isEmpty();
    }

    public static function throttleKey(string $email, ?string $ip): string
    {
        return 'login:'.mb_strtolower(trim($email)).'|'.($ip ?? '');
    }

    public static function clientIsActive(User $user): bool
    {
        return Client::query()->withoutGlobalScopes()->whereKey($user->client_id)->value('status') === 'active';
    }

    /**
     * Why the right-looking credentials were refused (tester feedback #8 / CR #137 GAP-03): a self-registered account staff have
     * not approved yet → `pending`; a deactivated login or a deactivated client → `inactive`; anything else → `failed`.
     */
    private function refusalFor(array $credentials): string
    {
        $user = User::query()->withoutGlobalScopes()->where('email', $credentials['email'])->first();
        if ($user === null || $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return 'failed';
        }
        if ($user->client_id !== null) {
            $status = Client::query()->withoutGlobalScopes()->whereKey($user->client_id)->value('status');
            if ($status === 'pending') {
                return 'pending';
            }
        }

        return 'inactive';
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
