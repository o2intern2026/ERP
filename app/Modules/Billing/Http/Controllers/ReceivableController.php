<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Contracts\View\View;

/** FIN-8: outstanding balance per client from issued invoices, payments and issued credit notes. */
class ReceivableController extends Controller
{
    public function index(): View
    {
        $open = Invoice::query()->with(['client', 'creditNotes'])->whereIn('status', ['issued', 'part_paid'])->orderBy('due_at')->get();

        return view('billing::receivables.index', [
            'byClient' => $open->groupBy('client_id')->map(fn ($invoices) => ['client' => $invoices->first()->client, 'count' => $invoices->count(), 'outstanding_cents' => $invoices->sum(fn (Invoice $i) => $i->outstandingCents()), 'overdue_cents' => $invoices->where('is_overdue', true)->sum(fn (Invoice $i) => $i->outstandingCents())])->sortByDesc('outstanding_cents'),
            'invoices' => $open,
        ]);
    }
}
