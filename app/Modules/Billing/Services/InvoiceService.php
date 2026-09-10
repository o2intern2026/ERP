<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Models\Payment;
use App\Modules\MasterData\Models\Client;
use App\Support\Contracts\DocumentService;
use App\Support\Enums;
use App\Support\Numbers;
use App\Support\Outbox\OutboxPublisher;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * A8a / A10: invoices reference charges that already exist (FIN-3). Drafts take charges out of the unbilled pool;
 * issuing freezes totals, snapshots the client, computes GST per line, sets due_at from payment_terms and stores the PDF.
 * Payment terms never block booking or dispatch (§0.2 rule 9); overdue is a display flag.
 */
final class InvoiceService
{
    public function __construct(private readonly DocumentService $documents, private readonly OutboxPublisher $outbox) {}

    /** Service invoice for one Job (per_job clients) — or a supplementary one after delivery. */
    public function draftForJob(int $jobId, string $type = 'service'): Invoice
    {
        $charges = $this->unbilled()->where('job_id', $jobId)->whereHas('chargeCode', fn ($q) => $q->where('category', '!=', 'storage'))->get(); // storage goes on the weekly storage invoice
        if ($charges->isEmpty()) {
            throw new InvalidArgumentException(__('billing.errors.no_unbilled_job'));
        }

        return $this->draft($charges, $type, null, null);
    }

    /**
     * Any period the client wants (a week, a fortnight, a month, a custom range), one scope (service fees, storage fees or
     * both) and one grouping (by Job or by order) — tester feedback #4. Storage-only drafts are typed `storage`,
     * everything else `service`; the grouping defaults to the client's setting.
     */
    public function draftPeriod(int $clientId, CarbonInterface $from, CarbonInterface $to, string $scope = 'service', ?string $groupBy = null): Invoice
    {
        if (! in_array($scope, Enums::INVOICE_SCOPES, true)) {
            throw new InvalidArgumentException("Unknown invoice scope: {$scope}");
        }
        $charges = $this->unbilled()->where('client_id', $clientId)->whereBetween('charge_date', [$from->toDateString(), $to->toDateString()])
            ->when($scope !== 'all', fn ($q) => $q->whereHas('chargeCode', fn ($c) => $scope === 'storage' ? $c->where('category', 'storage') : $c->where('category', '!=', 'storage')))
            ->get();
        if ($charges->isEmpty()) {
            throw new InvalidArgumentException(__('billing.errors.no_unbilled_period'));
        }

        return $this->draft($charges, $scope === 'storage' ? 'storage' : 'service', $from, $to, $groupBy);
    }

    /**
     * Lines grouped the way the invoice asks for (`group_by`): by Job (job_no · reference) or by order (order_no); lines
     * with no group land under "—". Used by the invoice page and the PDF.
     *
     * @return Collection<int, array{key:string, title:string, lines:Collection<int, InvoiceLine>}>
     */
    public function groupedLines(Invoice $invoice): Collection
    {
        $lines = $invoice->lines()->with('job')->orderBy('job_id')->orderBy('order_id')->orderBy('id')->get();
        if ($invoice->group_by === 'order') {
            $orderNos = DB::table('orders')->whereIn('id', $lines->pluck('order_id')->filter()->unique())->pluck('order_no', 'id');

            return $lines->groupBy(fn (InvoiceLine $l) => (string) ($l->order_id ?? ''))->map(fn ($group, $key) => ['key' => 'order:'.$key, 'title' => $key === '' ? '—' : (string) ($orderNos[(int) $key] ?? '#'.$key), 'lines' => $group])->values();
        }

        return $lines->groupBy(fn (InvoiceLine $l) => (string) ($l->job_id ?? ''))->map(fn ($group, $key) => ['key' => 'job:'.$key, 'title' => trim(($group->first()->job?->job_no ?? '—').' '.($group->first()->job?->reference ? '· '.$group->first()->job->reference : '')), 'lines' => $group])->values();
    }

    /** Monthly consolidated invoice for a client (monthly clients): every unbilled charge in the period, grouped by Job. */
    public function draftMonthly(int $clientId, CarbonInterface $from, CarbonInterface $to): Invoice
    {
        $charges = $this->unbilled()->where('client_id', $clientId)->whereBetween('charge_date', [$from->toDateString(), $to->toDateString()])->whereHas('chargeCode', fn ($q) => $q->where('category', '!=', 'storage'))->get();
        if ($charges->isEmpty()) {
            throw new InvalidArgumentException(__('billing.errors.no_unbilled_period'));
        }

        return $this->draft($charges, 'monthly', $from, $to);
    }

    /** Weekly storage invoice: the storage / rental / pickface charges of one client for one week. */
    public function draftStorageWeek(int $clientId, CarbonInterface $anyDayInWeek): Invoice
    {
        $from = $anyDayInWeek->copy()->startOfWeek(CarbonInterface::MONDAY);
        $to = $from->copy()->endOfWeek(CarbonInterface::SUNDAY);
        $charges = $this->unbilled()->where('client_id', $clientId)->whereHas('chargeCode', fn ($q) => $q->where('category', 'storage'))->whereBetween('charge_date', [$from->toDateString(), $to->toDateString()])->get();
        if ($charges->isEmpty()) {
            throw new InvalidArgumentException(__('billing.errors.no_unbilled_storage_week'));
        }

        return $this->draft($charges, 'storage', $from, $to);
    }

    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException(__('billing.errors.already_issued', ['no' => $invoice->invoice_no, 'status' => __('billing.invoices.statuses.'.$invoice->status)]));
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $client = Client::query()->withoutGlobalScopes()->findOrFail($invoice->client_id);
            $lines = $invoice->lines()->get();
            $subtotal = (int) $lines->sum('amount_cents');
            $gst = (int) $lines->sum('gst_cents');
            $now = now();

            $invoice->update([
                'invoice_no' => Numbers::next(Invoice::query()->withoutGlobalScopes()->where('status', '!=', 'draft'), 'invoice_no', 'INV', 'Ym'),
                'bill_to_name' => $client->name,
                'bill_to_address' => trim(implode(', ', array_filter([$client->address, $client->suburb, $client->state, $client->postcode]))) ?: null,
                'bill_to_abn' => $client->abn,
                'status' => 'issued',
                'issued_at' => $now,
                'due_at' => $client->dueDateFor($now)->toDateString(),
                'subtotal_cents' => $subtotal,
                'gst_cents' => $gst,
                'total_cents' => $subtotal + $gst,
            ]);

            Charge::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('charge_id')->filter())->update(['status' => 'invoiced']);

            $invoice->refresh();
            $path = 'invoices/'.$now->format('Y/m').'/'.$invoice->invoice_no.'.pdf';
            Storage::disk('local')->put($path, $this->pdf($invoice));
            $documentId = $this->documents->attach('invoice', 'invoice', $invoice->id, $path, ['client_id' => $invoice->client_id, 'client_visible' => true, 'original_name' => $invoice->invoice_no.'.pdf', 'mime' => 'application/pdf', 'size_bytes' => Storage::disk('local')->size($path)]);
            $invoice->update(['pdf_document_id' => $documentId]);

            $chargeRows = Charge::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('charge_id')->filter())->get(['id', 'job_id', 'source_type', 'source_id']);
            $orderIds = $chargeRows->where('source_type', 'order')->pluck('source_id')
                ->merge(DB::table('shipments')->whereIn('id', $chargeRows->where('source_type', 'shipment')->pluck('source_id'))->pluck('order_id'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $this->outbox->publish(new InvoiceIssued([
                'invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no, 'invoice_type' => $invoice->invoice_type, 'client_id' => $invoice->client_id,
                'job_ids' => $lines->pluck('job_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(), 'order_ids' => $orderIds,
                'subtotal_cents' => $subtotal, 'gst_cents' => $gst, 'total_cents' => $subtotal + $gst, 'issued_at' => $now->toIso8601String(), 'due_date' => $invoice->due_at?->toDateString(),
            ], jobId: $lines->pluck('job_id')->filter()->count() === 1 ? (int) $lines->first()->job_id : null, clientId: $invoice->client_id, correlationId: $invoice->invoice_no));

            return $invoice->fresh();
        });
    }

    /** Deleting a draft returns its charges to the unbilled pool. Issued invoices are only ever reduced by credit notes. */
    public function discardDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException(__('billing.errors.only_drafts_discard'));
        }
        DB::transaction(function () use ($invoice): void {
            Charge::query()->withoutGlobalScopes()->whereIn('id', $invoice->lines()->pluck('charge_id')->filter())->update(['invoice_line_id' => null]);
            $invoice->lines()->delete();
            $invoice->jobs()->detach();
            $invoice->delete();
        });
    }

    public function recordPayment(Invoice $invoice, int $amountCents, CarbonInterface $paidAt, string $method, ?string $reference = null): Payment
    {
        if (! in_array($invoice->status, ['issued', 'part_paid'], true)) {
            throw new InvalidArgumentException(__('billing.errors.payment_issued_only'));
        }
        if ($amountCents <= 0) {
            throw new InvalidArgumentException(__('billing.errors.payment_positive'));
        }

        return DB::transaction(function () use ($invoice, $amountCents, $paidAt, $method, $reference): Payment {
            $payment = Payment::query()->create(['invoice_id' => $invoice->id, 'amount_cents' => $amountCents, 'paid_at' => $paidAt->toDateString(), 'method' => $method, 'reference' => $reference, 'recorded_by' => auth()->id(), 'created_at' => now()]);
            $paid = $invoice->paid_amount_cents + $amountCents;
            $settled = $paid >= $invoice->total_cents - (int) $invoice->creditNotes()->where('status', 'issued')->sum('amount_cents');
            $invoice->update(['paid_amount_cents' => $paid, 'status' => $settled ? 'paid' : 'part_paid', 'paid_at' => $settled ? now() : null, 'is_overdue' => $settled ? false : $invoice->is_overdue]);

            return $payment;
        });
    }

    /** Client's open balance across issued invoices, net of payments and issued credit notes (FIN-8). */
    public function outstandingCents(int $clientId): int
    {
        return Invoice::query()->withoutGlobalScopes()->where('client_id', $clientId)->whereIn('status', ['issued', 'part_paid'])->get()->sum(fn (Invoice $i) => $i->outstandingCents());
    }

    public function flagOverdue(): int
    {
        return Invoice::query()->withoutGlobalScopes()->whereIn('status', ['issued', 'part_paid'])->whereDate('due_at', '<', today())->where('is_overdue', false)->update(['is_overdue' => true]);
    }

    /**
     * Which order each charge belongs to, from its source record (Orders / Warehouse / Transport tables read-only):
     * order → itself, shipment → shipments.order_id, task → warehouse_tasks.order_id, asn / snapshot → none.
     *
     * @return array<int, int> charge id → order id
     */
    private function orderIdsFor(Collection $charges): array
    {
        $byType = $charges->groupBy('source_type');
        $shipments = DB::table('shipments')->whereIn('id', $byType->get('shipment', collect())->pluck('source_id')->filter())->pluck('order_id', 'id');
        $tasks = DB::table('warehouse_tasks')->whereIn('id', $byType->get('task', collect())->pluck('source_id')->filter())->pluck('order_id', 'id');
        $map = [];
        foreach ($charges as $charge) {
            $orderId = match ($charge->source_type) {
                'order' => $charge->source_id,
                'shipment' => $shipments[$charge->source_id] ?? null,
                'task' => $tasks[$charge->source_id] ?? null,
                default => null,
            };
            if ($orderId) {
                $map[$charge->id] = (int) $orderId;
            }
        }

        return $map;
    }

    public function pdf(Invoice $invoice): string
    {
        return Pdf::loadView('billing::invoices.pdf', ['invoice' => $invoice->load(['lines.charge.chargeCode', 'client']), 'groups' => $this->groupedLines($invoice)])->setPaper('a4')->output();
    }

    private function unbilled()
    {
        return Charge::query()->withoutGlobalScopes()->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id');
    }

    private function draft(Collection $charges, string $type, ?CarbonInterface $from, ?CarbonInterface $to, ?string $groupBy = null): Invoice
    {
        return DB::transaction(function () use ($charges, $type, $from, $to, $groupBy): Invoice {
            $client = Client::query()->withoutGlobalScopes()->findOrFail($charges->first()->client_id);
            $groupBy = in_array($groupBy, Enums::INVOICE_GROUPINGS, true) ? $groupBy : ($client->invoice_grouping ?: 'job');
            $orderIds = $this->orderIdsFor($charges);
            $invoice = Invoice::query()->create([
                'invoice_no' => 'DR-'.strtoupper(base_convert((string) (int) floor(microtime(true) * 1000), 10, 36)).'-'.$client->id, // unique per millisecond, ≤ 16 chars; the real number is assigned at issue
                'client_id' => $client->id, 'invoice_type' => $type, 'group_by' => $groupBy,
                'period_from' => $from?->toDateString(), 'period_to' => $to?->toDateString(),
                'bill_to_name' => $client->name, 'bill_to_abn' => $client->abn, 'status' => 'draft', 'created_by' => auth()->id(),
            ]);

            foreach ($charges->load('chargeCode') as $charge) {
                $gst = $charge->gstCents();
                $line = InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id, 'charge_id' => $charge->id, 'job_id' => $charge->job_id, 'order_id' => $orderIds[$charge->id] ?? null,
                    'charge_code' => $charge->chargeCode->code, 'description' => $charge->chargeCode->customer_description,
                    'qty' => $charge->qty, 'uom' => $charge->uom, 'amount_cents' => $charge->amount_cents, 'tax_treatment' => $charge->tax_treatment, 'gst_cents' => $gst,
                ]);
                $charge->update(['invoice_line_id' => $line->id]);
            }
            $invoice->jobs()->sync($charges->pluck('job_id')->unique()->all());
            $invoice->update(['subtotal_cents' => (int) $charges->sum('amount_cents'), 'gst_cents' => (int) $invoice->lines()->sum('gst_cents')]);
            $invoice->update(['total_cents' => $invoice->subtotal_cents + $invoice->gst_cents]);

            return $invoice->fresh();
        });
    }
}
