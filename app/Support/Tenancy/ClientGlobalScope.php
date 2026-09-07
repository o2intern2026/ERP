<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Eloquent global scope applied by BelongsToClient: `where client_id = current client` when a client is set. */
final class ClientGlobalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $clientId = ClientScope::currentClientId();

        if ($clientId !== null) {
            $builder->where($model->qualifyColumn('client_id'), $clientId);
        }
    }
}
