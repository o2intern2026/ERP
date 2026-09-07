<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Modules\Platform\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A29: the one place that decides who may download a document (AGENTS.md: client visibility is enforced server side).
 * Staff download anything; a client-role user only documents flagged client_visible for their own client.
 * Portal (X1) calls respond() from its own /portal route; Platform's document centre calls it from /admin.
 */
final class DocumentDownloader
{
    public const DISK = 'local';

    public function canDownload(Document $document, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if (! $user->isClientUser()) {
            return true;
        }

        return $document->client_visible && $document->client_id !== null && (int) $document->client_id === (int) $user->client_id;
    }

    public function respond(Document $document, ?User $user): StreamedResponse
    {
        abort_unless($this->canDownload($document, $user), 403);
        abort_unless(Storage::disk(self::DISK)->exists($document->storage_path), 404);

        return Storage::disk(self::DISK)->download($document->storage_path, $document->original_name ?: basename($document->storage_path), array_filter(['Content-Type' => $document->mime]));
    }
}
