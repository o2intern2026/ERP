<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\DraftOrderService;
use App\Modules\Orders\Services\OrderChangeService;
use App\Modules\Platform\Models\Job;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A12 / OMS-5: upload a PDF or e-mail → draft order for manual confirmation (parsing accuracy is not acceptance, the flow is). */
final class DraftOrderController extends Controller
{
    public function create(): View
    {
        $this->authorize();

        return view('orders::drafts.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'jobs' => Job::query()->where('operational_status', '!=', 'cancelled')->latest('id')->get(['id', 'job_no', 'client_id']),
            'serviceLevels' => OrderEnums::SERVICE_LEVELS,
            'recent' => OrderImport::query()->where('source', 'pdf')->with('client')->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, DraftOrderService $drafts): RedirectResponse
    {
        $this->authorize();
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'job_id' => ['nullable', 'integer', Rule::exists('jobs', 'id')->where('client_id', $request->integer('client_id'))],
            'service_level' => ['required', Rule::in(OrderEnums::SERVICE_LEVELS)],
            'document' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (! in_array(mb_strtolower($value->getClientOriginalExtension()), ['pdf', 'eml', 'txt'], true)) {
                    $fail(__('orders.drafts.errors.unsupported_file'));
                }
            }],
        ]);

        $order = $drafts->createFromUpload($data['document'], [
            'client_id' => (int) $data['client_id'],
            'job_id' => filled($data['job_id'] ?? null) ? (int) $data['job_id'] : null,
            'service_level' => $data['service_level'],
        ], $request->user()?->id);

        return redirect()->route('orders.show', $order)->with('status', __('orders.drafts.messages.created', ['order_no' => $order->order_no]));
    }

    private function authorize(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(OrderChangeService::COORDINATOR_ROLES), 403);
    }
}
