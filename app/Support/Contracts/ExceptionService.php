<?php

namespace App\Support\Contracts;

/**
 * Provided by Platform (seat C, M1). contracts/services.md §6.
 * The only writer of the shared `exceptions` table (holds are exceptions with type = hold).
 */
interface ExceptionService
{
    /**
     * @param  string  $type  contracts/enums.md exceptions.type
     * @param  string  $sourceModule  contracts/enums.md exceptions.source_module
     * @param  array{job_id?:int, client_id?:int, source_type?:string, source_id?:int, order_id?:int, hold_type?:string, message?:string, owner_id?:int, created_by?:int}  $attributes
     * @return int exception id
     */
    public function raise(string $type, string $sourceModule, array $attributes = []): int;

    /** Resolving a hold releases it (released_by/at, release_reason = note). */
    public function resolve(int $exceptionId, int $resolvedBy, ?string $note = null): void;

    /**
     * Is there an unresolved hold of this type for the client (and, when given, this order or a client-wide hold)?
     * Transport / Orders call this before booking or dispatch: only an active `financial` hold blocks them (§3.4 OMS-11).
     */
    public function hasActiveHold(string $holdType, ?int $clientId = null, ?int $orderId = null): bool;
}
