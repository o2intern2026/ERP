<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * CR #137 (audit ADMIN-13): 修改密码 for the signed-in user — staff and client users alike. Current password, new password
 * twice (Password::min(8)). The admin-set password on /admin/users stays the way an account is opened or reset; this page is
 * how its owner stops sharing it with the admin.
 */
class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('platform::auth.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();

        return redirect()->route('platform.password.edit')->with('status', __('platform.password.saved'));
    }
}
