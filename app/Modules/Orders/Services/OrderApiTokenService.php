<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\OrderApiToken;
use Illuminate\Support\Str;

/** A4b: issue / verify / revoke client API tokens. The plain token is returned once at issue time and never stored. */
final class OrderApiTokenService
{
    /** @return array{token: OrderApiToken, plain: string} */
    public function issue(int $clientId, string $name, ?int $createdBy): array
    {
        $plain = 'oak_'.Str::random(40);
        $token = OrderApiToken::query()->create([
            'client_id' => $clientId,
            'name' => $name,
            'token_hash' => self::hash($plain),
            'created_by' => $createdBy,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    /** The active token matching this bearer string, or null; touches last_used_at. */
    public function authenticate(?string $bearer): ?OrderApiToken
    {
        if ($bearer === null || trim($bearer) === '') {
            return null;
        }
        $token = OrderApiToken::query()->where('token_hash', self::hash(trim($bearer)))->whereNull('revoked_at')->first();
        $token?->forceFill(['last_used_at' => now()])->save();

        return $token;
    }

    public function revoke(OrderApiToken $token): void
    {
        if ($token->revoked_at === null) {
            $token->update(['revoked_at' => now()]);
        }
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
