<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A1: user management — one role per user; client-role users must belong to a client. Admin only. */
class UserController extends Controller
{
    public function index(): View
    {
        return view('platform::users.index', [
            'users' => User::query()->with('roles', 'client')->orderBy('name')->paginate(50),
        ]);
    }

    public function create(): View
    {
        return $this->form(new User);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'client_id' => $data['role'] === 'client' ? $data['client_id'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $user->syncRoles([$data['role']]);

        return redirect()->route('platform.users.index')->with('status', __('platform.users.created'));
    }

    public function edit(User $user): View
    {
        return $this->form($user);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'client_id' => $data['role'] === 'client' ? $data['client_id'] : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles([$data['role']]);

        return redirect()->route('platform.users.index')->with('status', __('platform.users.saved'));
    }

    private function form(User $user): View
    {
        return view('platform::users.form', [
            'user' => $user,
            'roles' => Enums::ROLES,
            'clients' => Client::query()->orderBy('name')->get(['id', 'code', 'name']),
            'currentRole' => $user->exists ? $user->roles->first()?->name : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in(Enums::ROLES)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id'), 'required_if:role,client'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
