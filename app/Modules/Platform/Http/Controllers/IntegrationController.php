<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A31: Integration Monitor — outbox events by status, failed-event queue, manual retry. Admin only. */
class IntegrationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::OUTBOX_STATUSES)]]);

        $counts = OutboxEvent::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $events = OutboxEvent::query()
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('platform::integration.index', [
            'events' => $events,
            'filters' => $filters,
            'counts' => collect(Enums::OUTBOX_STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function retry(OutboxEvent $event, OutboxDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->retry($event->id);

        return back()->with('status', __('platform.integration.retried'));
    }
}
