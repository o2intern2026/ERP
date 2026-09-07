<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Seeders\BillingSeeder;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Test helpers for every seat: users with a role, client users, demo clients (contracts/enums.md §1–§2). */
trait CreatesUsers
{
    protected function ensureRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Enums::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /** A staff user (default admin). Factory password is "password". */
    protected function staff(string $role = 'admin', array $attributes = []): User
    {
        $this->ensureRoles();

        $user = User::factory()->create($attributes);
        $user->syncRoles([$role]);

        return $user;
    }

    /** A client-role user bound to $client (created when omitted). */
    protected function clientUser(?Client $client = null, array $attributes = []): User
    {
        $this->ensureRoles();
        $client ??= $this->client();

        $user = User::factory()->create($attributes + ['client_id' => $client->id]);
        $user->syncRoles(['client']);

        return $user;
    }

    protected function client(array $attributes = []): Client
    {
        if (! RateCard::query()->where('is_standard', true)->exists()) {
            $this->seed(BillingSeeder::class); // real Edward rates for every module's tests
        }
        $standardCardId = RateCard::query()->where('is_standard', true)->where('status', 'active')->value('id');

        return Client::query()->withoutGlobalScopes()->create($attributes + [
            'standard_rate_card_id' => $standardCardId,
            'code' => 'C-'.Str::upper(Str::random(6)),
            'name' => 'Client '.Str::random(4),
            'leg_type' => 'both',
            'status' => 'active',
            'payment_terms' => 'eom',
            'invoice_mode' => 'per_job',
            'default_markup_percent' => 20,
        ]);
    }
}
