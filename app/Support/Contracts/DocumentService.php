<?php

namespace App\Support\Contracts;

/**
 * Provided by Platform (seat C, M1 skeleton, M6/A29 full). contracts/services.md §7.
 * The only writer of the shared `documents` table; portal visibility is decided here, never in views.
 */
interface DocumentService
{
    /**
     * @param  string  $type  contracts/enums.md documents.type
     * @param  string  $relatedType  e.g. 'shipment', 'order', 'asn', 'invoice'
     * @param  array{job_id?:int, client_id?:int, client_visible?:bool, original_name?:string, mime?:string, size_bytes?:int, uploaded_by?:int}  $attributes
     * @return int document id
     */
    public function attach(string $type, string $relatedType, int $relatedId, string $storagePath, array $attributes = []): int;
}
