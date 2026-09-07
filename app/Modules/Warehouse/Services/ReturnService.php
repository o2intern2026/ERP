<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\ReturnInspected;
use App\Modules\Warehouse\Events\ReturnReceived;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\ReturnReceipt;
use App\Modules\Warehouse\Models\ReturnReceiptLine;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Enums;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B13: a return is received line by line, then inspected; only inspection changes stock (§4.3 rule 7, §4.7 #16).
 * Disposition (enums.md): available → a good unit in the receiving area (put away like inbound), quarantine / damaged →
 * a held unit in the quarantine location. Status expected → received → inspected (closed is reserved for the financial
 * decision). return.received is emitted when receiving completes, return.inspected when inspection completes.
 */
final class ReturnService
{
    public function __construct(private readonly StockLedger $ledger, private readonly TaskService $tasks, private readonly OutboxPublisher $outbox) {}

    /** @param array{original_order_id:int, warehouse_id:int, return_order_id?:?int, original_shipment_id?:?int, return_shipment_id?:?int, notes?:?string} $data */
    public function open(array $data): ReturnReceipt
    {
        $order = DB::table('orders')->where('id', $data['original_order_id'])->first();
        if ($order === null) {
            throw new InvalidArgumentException('Original order not found.');
        }

        return DB::transaction(function () use ($data, $order): ReturnReceipt {
            $receipt = ReturnReceipt::query()->create([
                'receipt_no' => DocumentNumbers::next(ReturnReceipt::query()->withoutGlobalScopes(), 'receipt_no', 'RET'),
                'job_id' => $order->job_id, 'client_id' => $order->client_id, 'return_order_id' => $data['return_order_id'] ?? null, 'original_order_id' => $order->id,
                'original_shipment_id' => $data['original_shipment_id'] ?? null, 'return_shipment_id' => $data['return_shipment_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'], 'status' => 'expected', 'notes' => $data['notes'] ?? null,
            ]);

            $fulfilmentByLine = DB::table('fulfilment_lines')->join('fulfilments', 'fulfilments.id', '=', 'fulfilment_lines.fulfilment_id')->where('fulfilments.order_id', $order->id)->pluck('fulfilments.id', 'fulfilment_lines.order_line_id');
            foreach (DB::table('order_lines')->where('order_id', $order->id)->get() as $line) {
                $receipt->lines()->create([
                    'original_order_line_id' => $line->id, 'asn_line_id' => $line->asn_line_id, 'original_fulfilment_id' => $fulfilmentByLine[$line->id] ?? null,
                    'description' => $line->description_cn ?: $line->description_en, 'expected_qty' => (int) ($line->qty_shipped ?: $line->carton_qty),
                ]);
            }

            return $receipt->load('lines');
        });
    }

    public function receiveLine(ReturnReceiptLine $line, int $receivedQty, string $condition): ReturnReceiptLine
    {
        if (! in_array($condition, Enums::CONDITIONS, true)) {
            throw new InvalidArgumentException("Unknown condition: {$condition}");
        }
        $receipt = $line->receipt;
        if ($receipt->status !== 'expected') {
            throw new InvalidArgumentException('This receipt is no longer receiving.');
        }

        $line->update(['received_qty' => $receivedQty, 'condition' => $condition, 'received_at' => now()]);

        return $line->fresh();
    }

    /** Receiving done → `received`; stock is still untouched (§4.7 #16). */
    public function completeReceiving(ReturnReceipt $receipt): ReturnReceipt
    {
        if ($receipt->lines()->whereNull('received_at')->exists()) {
            throw new InvalidArgumentException('Every line must be received (0 is allowed) before inspection starts.');
        }

        return DB::transaction(function () use ($receipt): ReturnReceipt {
            $receipt->update(['status' => 'received', 'received_at' => now()]);
            $this->outbox->publish(new ReturnReceived([
                'return_receipt_id' => $receipt->id, 'return_order_id' => $receipt->return_order_id, 'original_order_id' => $receipt->original_order_id, 'job_id' => $receipt->job_id, 'client_id' => $receipt->client_id, 'warehouse_id' => $receipt->warehouse_id,
                'received_at' => $receipt->received_at->toIso8601String(),
                'lines' => $receipt->lines()->get()->map(fn ($l) => ['return_receipt_line_id' => $l->id, 'original_order_line_id' => $l->original_order_line_id, 'asn_line_id' => $l->asn_line_id, 'received_qty' => $l->received_qty])->all(),
            ], $receipt->job_id, $receipt->client_id, $receipt->receipt_no));

            return $receipt->fresh();
        });
    }

    /** Inspect one line: every received carton comes back as stock — available (good) or held (quarantine / damaged). */
    public function inspectLine(ReturnReceiptLine $line, string $disposition, ?int $userId = null): ReturnReceiptLine
    {
        if (! in_array($disposition, Enums::RETURN_DISPOSITIONS, true)) {
            throw new InvalidArgumentException("Unknown disposition: {$disposition}");
        }
        $receipt = $line->receipt;
        if ($receipt->status !== 'received') {
            throw new InvalidArgumentException('Complete receiving before inspecting.');
        }
        if ($line->inspected_at !== null) {
            throw new InvalidArgumentException('This line is already inspected.');
        }

        return DB::transaction(function () use ($line, $receipt, $disposition, $userId): ReturnReceiptLine {
            $unitId = null;
            if ($line->received_qty > 0 && $line->asn_line_id !== null) {
                $held = $disposition !== 'available';
                $location = Location::query()->where('warehouse_id', $receipt->warehouse_id)->where('type', $held ? 'quarantine' : 'receiving')->where('active', true)->orderBy('full_code')->firstOrFail();
                $unit = StockUnit::query()->create([
                    'client_id' => $receipt->client_id, 'job_id' => $receipt->job_id, 'asn_line_id' => $line->asn_line_id, 'warehouse_id' => $receipt->warehouse_id,
                    'unit_type' => 'carton', 'label_code' => sprintf('%s-L%d-RET', $receipt->receipt_no, $line->id), 'location_id' => $location->id, 'qty_on_hand' => 0,
                    'condition' => $held ? ($disposition === 'damaged' ? 'damaged' : 'quarantine') : 'good',
                    'condition_reason' => $held ? 'returned goods: '.$disposition : null, 'condition_changed_at' => $held ? now() : null,
                    'putaway_completed' => $held, 'received_at' => now(),
                ]);
                $this->ledger->record($unit, 'return', $line->received_qty, ['to_location_id' => $location->id, 'source_type' => 'return_receipt', 'source_id' => $receipt->id, 'operator_id' => $userId]);
                $unitId = $unit->id;
            }

            $line->update(['disposition' => $disposition, 'stock_unit_id' => $unitId, 'inspected_at' => now()]);

            return $line->fresh();
        });
    }

    public function completeInspection(ReturnReceipt $receipt, ?int $userId = null): ReturnReceipt
    {
        if ($receipt->lines()->whereNull('inspected_at')->exists()) {
            throw new InvalidArgumentException('Every line needs a disposition first.');
        }

        return DB::transaction(function () use ($receipt, $userId): ReturnReceipt {
            $receipt->update(['status' => 'inspected', 'inspected_at' => now(), 'inspected_by' => $userId, 'completed_at' => now()]);

            $task = $this->tasks->create('return_inspection', ['job_id' => $receipt->job_id, 'client_id' => $receipt->client_id, 'warehouse_id' => $receipt->warehouse_id, 'source_type' => 'order', 'source_id' => $receipt->original_order_id, 'order_id' => $receipt->original_order_id]);
            $this->tasks->complete($task, ['billable_qty' => (float) $receipt->lines()->sum('received_qty'), 'billable_uom' => 'carton'], $receipt->receipt_no);

            $this->outbox->publish(new ReturnInspected([
                'return_receipt_id' => $receipt->id, 'return_order_id' => $receipt->return_order_id, 'original_order_id' => $receipt->original_order_id, 'job_id' => $receipt->job_id, 'client_id' => $receipt->client_id, 'warehouse_id' => $receipt->warehouse_id,
                'inspected_at' => $receipt->inspected_at->toIso8601String(), 'inspected_by' => $userId,
                'lines' => $receipt->lines()->get()->map(fn ($l) => ['return_receipt_line_id' => $l->id, 'original_order_line_id' => $l->original_order_line_id, 'asn_line_id' => $l->asn_line_id, 'received_qty' => $l->received_qty, 'disposition' => $l->disposition, 'stock_unit_id' => $l->stock_unit_id])->all(),
            ], $receipt->job_id, $receipt->client_id, $receipt->receipt_no));

            return $receipt->fresh();
        });
    }
}
