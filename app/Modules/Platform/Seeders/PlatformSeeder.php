<?php

namespace App\Modules\Platform\Seeders;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Eight roles (contracts/enums.md §1) and one demo user per role for the §7 acceptance script.
 * Demo password comes from SEED_DEMO_PASSWORD (default "password") — local development only.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Enums::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $password = env('SEED_DEMO_PASSWORD', 'password');
        $edward = Client::query()->withoutGlobalScopes()->where('code', 'EDWARD')->first();

        foreach (Enums::ROLES as $role) {
            $user = User::query()->updateOrCreate(
                ['email' => str_replace('_', '-', $role).'@erp.local'],
                [
                    'name' => __('platform.roles.'.$role),
                    'password' => $password,
                    'client_id' => $role === 'client' ? $edward?->id : null,
                    'is_active' => true,
                ],
            );
            $user->syncRoles([$role]);
        }
    }
}
