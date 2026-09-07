<?php

namespace App\Support\Tenancy;

/**
 * Use on every model that carries client_id (orders, asns, stock_units, charges, …).
 * Registers ClientGlobalScope so client-role users can only ever read their own rows.
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope(new ClientGlobalScope);
    }
}
