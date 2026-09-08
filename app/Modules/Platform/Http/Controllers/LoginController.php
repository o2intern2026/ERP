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
use Illuminate\Validation\ValidationException;

/** A1: session login. Inactive users cannot sign in; client users land on the portal, staff on the Job workbench. */
class LoginController extends Controller
{
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

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __($this->isPendingRegistration($credentials) ? 'platform.auth.pending' : 'platform.auth.failed')]);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(
            $request->user()->isClientUser() ? route('portal.index') : route('platform.index')
        );
    }

    /** Tester feedback #8: right password on a self-registered account that staff have not approved yet → say so instead of "wrong password". */
    private function isPendingRegistration(array $credentials): bool
    {
        $user = User::query()->withoutGlobalScopes()->where('email', $credentials['email'])->first();
        if ($user === null || $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return false;
        }

        return Client::query()->withoutGlobalScopes()->whereKey($user->client_id)->value('status') === 'pending';
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
