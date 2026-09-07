<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\CreditNoteLine;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Platform\Services\ApprovalService;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** A8b: the only way to reduce an issued invoice; issuing needs a second person's approval (PLT-7). */
final class CreditNoteService
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /** @param list<array{invoice_line_id?:int, description?:string, amount_cents:int}> $lines ex-GST amounts */
    public function draft(Invoice $invoice, array $lines, string $reason, User $by): CreditNote
    {
        if (! in_array($invoice->status, ['issued', 'part_paid', 'paid'], true)) {
            throw new InvalidArgumentException('Credit notes apply to issued invoices only.');
        }
        $total = (int) array_sum(array_column($lines, 'amount_cents'));
        if ($total <= 0) {
            throw new InvalidArgumentException('A credit note needs a positive amount.');
        }

        return DB::transaction(function () use ($invoice, $lines, $reason, $by, $total): CreditNote {
            $note = CreditNote::query()->create([
                'credit_note_no' => 'DCN-'.now()->format('ymdHis').'-'.$invoice->id,
                'invoice_id' => $invoice->id, 'job_id' => $invoice->jobs()->count() === 1 ? $invoice->jobs()->first()->id : null, 'client_id' => $invoice->client_id,
                'reason' => $reason, 'amount_cents' => 0, 'gst_cents' => 0, 'status' => 'draft', 'created_by' => $by->id,
            ]);
            $gstTotal = 0;
            foreach ($lines as $line) {
                $invoiceLine = isset($line['invoice_line_id']) ? $invoice->lines()->whereKey($line['invoice_line_id'])->first() : null;
                $gst = ($invoiceLine?->tax_treatment ?? 'gst_10') === 'gst_10' ? (int) round($line['amount_cents'] * 0.10) : 0;
                CreditNoteLine::query()->create(['credit_note_id' => $note->id, 'invoice_line_id' => $invoiceLine?->id, 'charge_id' => $invoiceLine?->charge_id, 'description' => $line['description'] ?? $invoiceLine?->description ?? $reason, 'amount_cents' => (int) $line['amount_cents'], 'gst_cents' => $gst]);
                $gstTotal += $gst;
            }
            $note->update(['amount_cents' => $total, 'gst_cents' => $gstTotal]);

            $this->approvals->request('credit_note', 'credit_note', $note->id, $by, ['client_id' => $invoice->client_id, 'job_id' => $note->job_id, 'request_note' => "{$invoice->invoice_no}: {$reason} (".number_format(($total + $gstTotal) / 100, 2).' incl. GST)', 'payload' => ['amount_cents' => $total, 'gst_cents' => $gstTotal]]);

            return $note->fresh();
        });
    }

    public function issue(CreditNote $note, User $by): CreditNote
    {
        if ($note->status === 'issued') {
            return $note;
        }
        if (! $this->approvals->isApproved('credit_note', 'credit_note', $note->id)) {
            throw new InvalidArgumentException('This credit note has not been approved by a second person yet.');
        }

        return DB::transaction(function () use ($note, $by): CreditNote {
            $note->update(['credit_note_no' => Numbers::next(CreditNote::query()->withoutGlobalScopes()->where('status', 'issued'), 'credit_note_no', 'CN', 'Ym'), 'status' => 'issued', 'approved_by' => $by->id, 'issued_at' => now()]);
            $invoice = $note->invoice;
            if ($invoice->status !== 'paid' && $invoice->paid_amount_cents >= $invoice->total_cents - (int) $invoice->creditNotes()->where('status', 'issued')->sum('amount_cents')) {
                $invoice->update(['status' => 'paid', 'paid_at' => now(), 'is_overdue' => false]);
            }

            return $note->fresh();
        });
    }
}
