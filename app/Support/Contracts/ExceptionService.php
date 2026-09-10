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

    /** Resolves every open exception of $type on $orderId (system-driven, e.g. a stock shortage that a later putaway filled). Returns how many were closed. Additive, CHANGE_REQUESTS #106. */
    public function resolveOpen(string $type, int $orderId, ?int $resolvedBy = null, ?string $note = null): int;

    /**
     * Is there an unresolved hold of this type for the client (and, when given, this order or a client-wide hold)?
     * Transport / Orders call this before booking or dispatch: only an active `financial` hold blocks them (§3.4 OMS-11).
     */
    public function hasActiveHold(string $holdType, ?int $clientId = null, ?int $orderId = null): bool;

    /** Exception Centre (A28): take ownership; null owner releases it back to the queue. */
    public function assign(int $exceptionId, ?int $ownerId): void;

    /** Exception Centre (A28): mark work started (open → in_progress). */
    public function start(int $exceptionId, int $userId): void;
}
