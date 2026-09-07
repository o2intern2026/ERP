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

    public function resolve(int $exceptionId, int $resolvedBy, ?string $note = null): void;
}
