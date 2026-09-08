<?php

namespace App\Modules\Orders\Services;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Support\Contracts\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A12 / OMS-5: an uploaded PDF or e-mail becomes a draft order (`source = pdf`, `operational_status = received`) that a
 * person checks, edits and confirms — the same OrderCreationService path as every other source. The file is kept through
 * DocumentService and the import is logged in order_imports (source pdf) with what was read.
 */
final class DraftOrderService
{
    public function __construct(
        private readonly DraftOrderTextExtractor $extractor,
        private readonly DraftOrderParser $parser,
        private readonly OrderCreationService $orders,
        private readonly DocumentService $documents,
    ) {}

    /** @param array{client_id:int, job_id?:?int, service_level?:string} $context */
    public function createFromUpload(UploadedFile $file, array $context, ?int $actorId): Order
    {
        $client = Client::query()->findOrFail((int) $context['client_id']);
        $path = $file->store('imports/orders/drafts', 'local');
        $text = $this->extractor->extract(Storage::disk('local')->path($path), $file->getClientOriginalExtension());
        $parsed = $this->parser->parse($text);

        $placeholder = __('orders.drafts.placeholder');
        $lines = $parsed['lines'] !== []
            ? array_map(fn (array $line) => ['description_cn' => $line['description'], 'package_type' => 'carton', 'carton_qty' => $line['carton_qty'], 'actual_weight_kg' => $line['actual_weight_kg']], $parsed['lines'])
            : [['description_cn' => $placeholder, 'package_type' => 'carton', 'carton_qty' => 1]];

        return DB::transaction(function () use ($client, $context, $actorId, $file, $path, $text, $parsed, $lines, $placeholder): Order {
            $order = $this->orders->create([
                'client_id' => $client->id,
                'job_id' => $context['job_id'] ?? null,
                'order_type' => 'from_stock',
                'external_ref' => $this->uniqueRef($client->id, $parsed['external_ref']),
                'consignment_mark' => $parsed['consignment_mark'],
                'fba_reference' => $parsed['fba_reference'],
                'deliver_to_name' => $parsed['deliver_to_name'] ?? $placeholder,
                'deliver_to_phone' => $parsed['deliver_to_phone'],
                'deliver_to_address' => $parsed['deliver_to_address'] ?? $placeholder,
                'deliver_to_suburb' => $parsed['deliver_to_suburb'] ?? $placeholder,
                'deliver_to_state' => $parsed['deliver_to_state'] ?? ($client->state ?: 'VIC'),
                'deliver_to_postcode' => $parsed['deliver_to_postcode'] ?? '0000',
                'deliver_to_address_type' => $parsed['fba_reference'] !== null ? 'fba' : 'business',
                'requested_date' => $parsed['requested_date'] ?? today()->addDay()->toDateString(),
                'service_level' => $context['service_level'] ?? 'standard',
                'lines' => $lines,
            ], $actorId, 'pdf');

            $documentId = $this->documents->attach('packing_list', 'order', $order->id, $path, [
                'job_id' => $order->job_id, 'client_id' => $client->id, 'client_visible' => false,
                'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size_bytes' => $file->getSize(), 'uploaded_by' => $actorId,
            ]);

            OrderImport::query()->create([
                'client_id' => $client->id, 'source' => 'pdf', 'document_id' => $documentId, 'status' => 'imported',
                'row_count' => count($parsed['lines']), 'error_count' => 0, 'created_by' => $actorId,
                'errors' => ['order_id' => $order->id, 'order_no' => $order->order_no, 'matched' => $parsed['matched'], 'text' => mb_substr($text, 0, 4000)],
            ]);

            app(OrderStatusService::class)->note($order, $actorId, __('orders.drafts.timeline.created', [
                'file' => $file->getClientOriginalName(),
                'fields' => $parsed['matched'] !== [] ? implode(', ', array_map(fn ($f) => __('orders.drafts.fields.'.$f), $parsed['matched'])) : __('orders.drafts.nothing_matched'),
            ]));

            return $order->refresh()->load('lines', 'events');
        });
    }

    /** orders.external_ref is unique per client: a re-uploaded PO keeps its number with a suffix rather than failing. */
    private function uniqueRef(int $clientId, ?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        $candidate = $ref;
        $n = 1;
        while (Order::query()->withoutGlobalScopes()->where('client_id', $clientId)->where('external_ref', $candidate)->exists()) {
            $candidate = $ref.'-'.(++$n);
        }

        return $candidate;
    }
}
