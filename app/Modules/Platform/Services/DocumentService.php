<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Document;
use App\Support\Contracts\DocumentService as DocumentServiceContract;
use App\Support\Enums;
use InvalidArgumentException;

/** The only writer of the shared `documents` table (A29 data side; the Document Centre pages land in M6). */
final class DocumentService implements DocumentServiceContract
{
    public function attach(string $type, string $relatedType, int $relatedId, string $storagePath, array $attributes = []): int
    {
        if (! in_array($type, Enums::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException("Unknown document type: {$type}");
        }

        $document = Document::query()->create([
            'type' => $type,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'job_id' => $attributes['job_id'] ?? null,
            'client_id' => $attributes['client_id'] ?? null,
            'client_visible' => (bool) ($attributes['client_visible'] ?? false),
            'storage_path' => $storagePath,
            'original_name' => $attributes['original_name'] ?? null,
            'mime' => $attributes['mime'] ?? null,
            'size_bytes' => $attributes['size_bytes'] ?? null,
            'uploaded_by' => $attributes['uploaded_by'] ?? auth()->id(),
        ]);

        return $document->id;
    }
}
