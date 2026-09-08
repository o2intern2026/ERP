<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Invoice;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\JobService;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A27: Job workbench skeleton — list, create, and the one-page view other modules add panels to later. */
class JobController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Enums::JOB_OPERATIONAL_STATUSES)],
            'client_id' => ['nullable', 'integer'],
        ]);

        $jobs = Job::query()->with('client')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('operational_status', $s))
            ->when($filters['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('platform::jobs.index', [
            'jobs' => $jobs,
            'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => Enums::JOB_OPERATIONAL_STATUSES,
        ]);
    }

    public function create(): View
    {
        return view('platform::jobs.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'types' => Enums::JOB_TYPES,
        ]);
    }

    public function store(Request $request, JobService $jobs): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'job_type' => ['required', Rule::in(Enums::JOB_TYPES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $job = $jobs->create((int) $data['client_id'], $data['job_type'], [
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('platform.jobs.show', $job['job_id'])
            ->with('status', __('platform.jobs.created', ['job_no' => $job['job_no']]));
    }

    public function show(Job $job, JobService $jobs): View
    {
        // §2.5 #6: everything hanging off the Job on one page — read-only views of the other modules' tables (client scope applies).
        $panels = [
            'asns' => Asn::query()->where('job_id', $job->id)->withCount('lines')->orderBy('id')->get(),
            'stock' => StockUnit::query()->where('job_id', $job->id)->selectRaw('COUNT(*) AS units, COALESCE(SUM(qty_on_hand), 0) AS on_hand, COALESCE(SUM(qty_reserved), 0) AS reserved')->first(),
            'orders' => Order::query()->where('job_id', $job->id)->orderBy('id')->get(['id', 'order_no', 'operational_status', 'billing_status']),
            'shipments' => Shipment::query()->where('job_id', $job->id)->orderBy('id')->get(['id', 'shipment_no', 'status', 'tracking_number', 'order_id']),
            'documents' => Document::query()->where('job_id', $job->id)->orderByDesc('id')->limit(20)->get(),
            'invoices' => Invoice::query()->whereHas('lines', fn ($q) => $q->where('job_id', $job->id))->orderBy('id')->get(['id', 'invoice_no', 'invoice_type', 'status', 'total_cents']),
        ];

        return view('platform::jobs.show', [
            'job' => $job->load('client', 'creator'),
            'summary' => $jobs->summarize($job->id),
            'panels' => $panels,
        ]);
    }
}
