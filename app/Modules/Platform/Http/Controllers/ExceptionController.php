<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Warehouse\Models\AsnLine;
use App\Support\Contracts\ExceptionService;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/** A28 Exception Centre: one list for every module's exceptions; take, start, resolve (holds are released on resolve). */
class ExceptionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Enums::EXCEPTION_STATUSES)], 'type' => ['nullable', Rule::in(Enums::EXCEPTION_TYPES)],
            'source_module' => ['nullable', Rule::in(Enums::SOURCE_MODULES)], 'client_id' => ['nullable', 'integer'], 'owner' => ['nullable', 'in:me,unassigned'],
        ]);

        $exceptions = ExceptionRecord::query()->with(['client', 'job', 'owner', 'creator'])
            ->when(($filters['status'] ?? 'open_or_in_progress') === 'open_or_in_progress', fn ($q) => $q->where('status', '!=', 'resolved'), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['source_module'] ?? null, fn ($q, $v) => $q->where('source_module', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when(($filters['owner'] ?? null) === 'me', fn ($q) => $q->where('owner_id', auth()->id()))
            ->when(($filters['owner'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('owner_id'))
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'resolved')")->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('platform::exceptions.index', [
            'exceptions' => $exceptions, 'filters' => $filters,
            'counts' => ExceptionRecord::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->where('is_active', true)->whereDoesntHave('roles', fn ($q) => $q->where('name', 'client'))->orderBy('name')->get(['id', 'name']),
            'types' => Enums::EXCEPTION_TYPES, 'modules' => Enums::SOURCE_MODULES, 'statuses' => Enums::EXCEPTION_STATUSES,
            'sourceUrl' => fn (ExceptionRecord $e) => self::sourceUrl($e),
        ]);
    }

    public function assign(Request $request, ExceptionRecord $exception, ExceptionService $service): RedirectResponse
    {
        $data = $request->validate(['owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')]]);
        $service->assign($exception->id, isset($data['owner_id']) ? (int) $data['owner_id'] : auth()->id());

        return back()->with('status', __('platform.exceptions.assigned'));
    }

    public function start(ExceptionRecord $exception, ExceptionService $service): RedirectResponse
    {
        $service->start($exception->id, auth()->id());

        return back()->with('status', __('platform.exceptions.started'));
    }

    public function resolve(Request $request, ExceptionRecord $exception, ExceptionService $service): RedirectResponse
    {
        $data = $request->validate(['note' => [$exception->isHold() ? 'required' : 'nullable', 'string', 'max:500']]);
        $service->resolve($exception->id, auth()->id(), $data['note'] ?? null);

        return back()->with('status', __('platform.exceptions.resolved'));
    }

    /**
     * Where the exception came from — each module's detail page when it exists in this build AND the current user's role may open it
     * (audit 2026-09-10: the centre is open to every staff role, the warehouse pages and the integration monitor are not).
     */
    public static function sourceUrl(ExceptionRecord $e, ?User $user = null): ?string
    {
        $user ??= auth()->user();
        $can = fn (string $route, array $roles): bool => Route::has($route) && ($user === null || $user->hasAnyRole($roles));
        $warehouse = ['admin', 'warehouse_supervisor', 'warehouse_operator', 'dispatcher', 'customer_service', 'finance']; // Warehouse read group (routes.php)

        return match ($e->source_type) {
            'asn_line' => $can('warehouse.asns.show', $warehouse) && $e->source_id ? url('/warehouse/asns/'.AsnLine::query()->whereKey($e->source_id)->value('asn_id')).'#line-'.$e->source_id : null,
            'asn' => $can('warehouse.asns.show', $warehouse) ? route('warehouse.asns.show', $e->source_id) : null,
            'stock_unit' => $can('warehouse.stock.show', $warehouse) ? route('warehouse.stock.show', $e->source_id) : null,
            'stocktake' => $can('warehouse.stocktakes.show', $warehouse) ? route('warehouse.stocktakes.show', $e->source_id) : null,
            'outbox_event' => $can('platform.integration.index', ['admin']) ? route('platform.integration.index') : null,
            'order' => Route::has('orders.show') ? route('orders.show', $e->source_id) : null,
            'shipment' => Route::has('transport.shipments.show') ? route('transport.shipments.show', $e->source_id) : null,
            default => $e->job_id ? route('platform.jobs.show', $e->job_id) : null,
        };
    }
}
