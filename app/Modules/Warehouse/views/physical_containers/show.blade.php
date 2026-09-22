@extends('layouts.app')

@section('title', $box->container_no)

@section('content')
    <p><a href="{{ route('warehouse.physical_containers.index') }}">← {{ __('warehouse.physical_containers.title') }}</a></p>
    <header>
        <h1>{{ $box->container_no }}
            {!! \App\Support\Ui\StatusBadge::render('warehouse.physical_containers.consolidations.', $box->consolidation) !!}
            {!! \App\Support\Ui\StatusBadge::render('warehouse.physical_containers.statuses.', $box->status) !!}
            @if ($box->sideloader_required)<span class="badge" data-tone="warn">{{ __('warehouse.physical_containers.sideloader_short') }}</span>@endif
            @if ($box->allocation_stale)<span class="badge" data-tone="danger" title="{{ __('warehouse.physical_containers.stale_hint') }}">{{ __('warehouse.physical_containers.stale_badge') }}</span>@endif
            @if ($shares && $shares['provisional'] && $box->hasEmitted())<span class="badge" data-tone="warn" title="{{ __('warehouse.physical_containers.provisional_hint') }}">{{ __('warehouse.physical_containers.provisional_badge') }}</span>@endif
        </h1>
        <p>{{ $box->warehouse->code }} · {{ __('warehouse.container_sizes.'.$box->size) }} · {{ __('warehouse.unpack_modes.'.$box->unpack_mode) }} · {{ __('warehouse.physical_containers.gross_weight') }}: {{ $box->gross_weight_kg ?? '—' }} · {{ __('warehouse.physical_containers.eta_date') }}: {{ $box->eta_date?->format('Y-m-d') ?? '—' }} · {{ __('warehouse.physical_containers.arrived_at') }}: {{ $box->arrived_at?->format('Y-m-d H:i') ?? '—' }}</p>
    </header>

    <article class="kv-card">
        <dl class="kv-2">
            <dt>{{ __('warehouse.physical_containers.allocation_basis') }}</dt><dd>{{ __('warehouse.physical_containers.bases.'.$box->allocation_basis) }} @if ($shares && $shares['basis'] !== $box->allocation_basis)<small class="text-muted">→ {{ __('warehouse.physical_containers.bases_short.'.($shares['basis'] === 'cartons_received' && $shares['provisional'] ? 'cartons_expected' : $shares['basis'])) }}</small>@elseif ($shares && $shares['provisional'])<small class="text-muted">→ {{ __('warehouse.physical_containers.bases_short.cartons_expected') }}</small>@endif</dd>
            <dt>{{ __('warehouse.physical_containers.cartage_by_us') }}</dt><dd>{{ $box->cartage_by_us ? __('platform.common.yes') : __('platform.common.no') }}</dd>
            <dt>{{ __('warehouse.physical_containers.sideloader_required') }}</dt><dd>{{ $box->sideloader_required ? __('platform.common.yes') : __('platform.common.no') }}</dd>
            <dt>{{ __('warehouse.physical_containers.devanning_task') }}</dt><dd>@if ($box->devanningTask){{ $box->devanningTask->task_no }} {!! \App\Support\Ui\StatusBadge::render('warehouse.task_statuses.', $box->devanningTask->status) !!} · <a href="{{ route('warehouse.tasks.index', ['task_type' => 'devanning']) }}">{{ __('warehouse.tasks.title') }}</a>@else — @endif</dd>
            <dt>{{ __('warehouse.physical_containers.allocation_version') }}</dt><dd>{{ $box->allocation_version > 0 ? $box->allocation_version : '—' }}</dd>
            @if ($box->notes)<dt>{{ __('warehouse.physical_containers.notes') }}</dt><dd>{{ $box->notes }}</dd>@endif
            <dt>{{ __('warehouse.physical_containers.created_by') }}</dt><dd>{{ $box->createdBy?->name ?? '—' }} · {{ $box->created_at?->format('Y-m-d H:i') }}</dd>
        </dl>
    </article>
    <p class="text-muted"><small>{{ __('warehouse.physical_containers.basis_hint') }}</small></p>
    @foreach (['link', 'arrive', 'recompute', 'edit', 'physical_container_id', 'task_type'] as $bag)
        @error($bag)<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
    @endforeach

    <div class="grid">
        {{-- Audit 2026-09-22 INBOUND-16 (CR #141): 登记到港 goes through a confirmation page that lists the cartage / sideloader lines; the header is editable until then; an empty box can be deleted. --}}
        @if ($box->arrived_at === null && $box->members->isNotEmpty())
            <a role="button" class="secondary" href="{{ route('warehouse.physical_containers.arrive_confirm', $box) }}">{{ __('warehouse.physical_containers.arrive') }}</a>
        @endif
        @if ($box->arrived_at === null && ! $box->hasEmitted())
            <a role="button" class="secondary outline" href="{{ route('warehouse.physical_containers.edit', $box) }}">{{ __('warehouse.physical_containers.edit') }}</a>
        @endif
        @if ($box->members->isEmpty() && ! $box->hasEmitted() && $box->arrived_at === null && $box->devanningTask === null)
            <form method="post" action="{{ route('warehouse.physical_containers.destroy', $box) }}" class="inline" onsubmit="return confirm('{{ __('warehouse.physical_containers.delete_confirm') }}')">@csrf @method('DELETE')<button type="submit" class="secondary outline">{{ __('warehouse.physical_containers.delete') }}</button></form>
        @endif
        @role('admin|warehouse_supervisor')
            @if ($box->devanningTask === null && $box->members->isNotEmpty())
                <form method="post" action="{{ route('warehouse.tasks.store') }}" class="inline">@csrf<input type="hidden" name="physical_container_id" value="{{ $box->id }}"><input type="hidden" name="task_type" value="devanning"><button type="submit">{{ __('warehouse.physical_containers.register_devanning') }}</button></form>
            @endif
        @endrole
        @if ($box->hasEmitted())
            <form method="post" action="{{ route('warehouse.physical_containers.recompute', $box) }}" class="inline" onsubmit="return confirm('{{ __('warehouse.physical_containers.recompute_confirm') }}')">@csrf<button type="submit" class="secondary outline">{{ __('warehouse.physical_containers.recompute') }}</button></form>
        @endif
    </div>
    <p class="text-muted"><small>{{ __('warehouse.physical_containers.devanning_hint') }}</small></p>

    <h2>{{ __('warehouse.physical_containers.members') }} <small class="text-muted">{{ $box->members->count() }}</small></h2>
    @if ($box->members->isEmpty())
        <p class="text-muted">{{ __('warehouse.physical_containers.errors.no_members', ['no' => $box->container_no]) }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>{{ __('warehouse.physical_containers.member_fields.asn') }}</th><th>{{ __('warehouse.physical_containers.member_fields.client') }}</th><th>{{ __('warehouse.physical_containers.member_fields.job') }}</th>
                <th>{{ __('warehouse.physical_containers.member_fields.size') }}</th><th>{{ __('warehouse.physical_containers.member_fields.unpack_mode') }}</th>
                <th class="num">{{ __('warehouse.physical_containers.member_fields.lines') }}</th><th class="num">{{ __('warehouse.physical_containers.member_fields.expected') }}</th><th class="num">{{ __('warehouse.physical_containers.member_fields.received') }}</th>
                <th class="num">{{ __('warehouse.physical_containers.member_fields.cbm') }}</th><th class="num">{{ __('warehouse.physical_containers.member_fields.pallets') }}</th><th>{{ __('warehouse.physical_containers.member_fields.status') }}</th>
                <th class="num">{{ __('warehouse.physical_containers.member_fields.basis_qty') }}</th><th class="num">{{ __('warehouse.physical_containers.member_fields.share') }}</th><th>{{ __('platform.common.actions') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($box->members as $m)
                @php($s = $sharesByContainer->get($m->id))
                <tr>
                    <td><a href="{{ route('warehouse.asns.show', $m->asn_id) }}">{{ $m->asn->asn_no }}</a> <small class="text-muted">{{ $m->container_no }}</small></td>
                    <td>{{ $m->asn->client?->name }}</td><td><a href="{{ route('platform.jobs.show', $m->job_id) }}">{{ $m->asn->job?->job_no }}</a></td>
                    <td>{{ __('warehouse.container_sizes.'.$m->size) }} @if ($m->size !== $box->size)<mark>≠</mark>@endif</td><td>{{ __('warehouse.unpack_modes.'.$m->unpack_mode) }}</td>
                    <td class="num">{{ $s['line_count'] ?? $m->line_count }}</td><td class="num">{{ $s['cartons_expected'] ?? '—' }}</td><td class="num">{{ $s['cartons_received'] ?? '—' }}</td>
                    <td class="num">{{ $s ? rtrim(rtrim(number_format($s['cbm'], 4), '0'), '.') : '—' }}</td><td class="num">{{ $s['pallets'] ?? '—' }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.asn_statuses.', $m->asn->status) !!}</td>
                    <td class="num">{{ $s ? rtrim(rtrim(number_format($s['basis_qty'], 3), '0'), '.') : '—' }}</td>
                    <td class="num">{{ $s ? number_format($s['share'] * 100, 2).' %' : '—' }} @if ($m->devanning_share !== null && $s && abs((float) $m->devanning_share - $s['share']) > 0.00005)<br><small class="text-muted">{{ __('warehouse.physical_containers.share_emitted', ['share' => number_format((float) $m->devanning_share * 100, 2)]) }}</small>@endif</td>
                    <td>
                        @if ($box->isDevanned())
                            <small class="text-muted">{{ __('warehouse.physical_containers.unlink_locked') }}</small>
                        @else
                            <form method="post" action="{{ route('warehouse.physical_containers.unlink', [$box, $m]) }}" class="inline">@csrf<button type="submit" class="secondary outline" style="padding:.15rem .6rem">{{ __('warehouse.physical_containers.unlink') }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
            @if ($shares)
                <tfoot><tr>
                    <td colspan="5"><strong>{{ __('warehouse.physical_containers.totals') }}</strong> · {{ __('warehouse.physical_containers.bases_short.'.($shares['basis'] === 'cartons_received' && $shares['provisional'] ? 'cartons_expected' : $shares['basis'])) }}</td>
                    <td class="num"><strong>{{ $shares['totals']['line_count'] }}</strong></td><td class="num">{{ $shares['totals']['cartons_expected'] }}</td><td class="num">{{ $shares['totals']['cartons_received'] }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($shares['totals']['cbm'], 4), '0'), '.') }}</td><td class="num">{{ $shares['totals']['pallets'] }}</td><td></td>
                    <td class="num">{{ rtrim(rtrim(number_format($shares['basis_total'], 3), '0'), '.') }}</td><td class="num">100 %</td><td></td>
                </tr></tfoot>
            @endif
        </table></div>
    @endif

    @unless ($box->isDevanned())
        <article class="kv-card" id="link">
            <strong>{{ __('warehouse.physical_containers.link_title') }}</strong>
            <p class="text-muted" style="margin:.3rem 0"><small>{{ __('warehouse.physical_containers.link_hint') }}</small></p>
            <form method="get" action="{{ route('warehouse.physical_containers.show', $box) }}#link" class="grid">
                <input type="search" name="q" class="scan" placeholder="{{ __('warehouse.physical_containers.link_search') }}" value="{{ $search }}">
                <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
            </form>
            @if ($candidates->isEmpty())
                <p class="text-muted">{{ __('warehouse.physical_containers.no_candidates') }}</p>
            @else
                <form method="post" action="{{ route('warehouse.physical_containers.link', $box) }}" id="link-form">
                    @csrf
                    <div class="overflow-auto"><table class="dense">
                        <thead><tr><th><input type="checkbox" id="link-all" aria-label="{{ __('warehouse.asns.import_orders_select_all') }}" title="{{ __('warehouse.asns.import_orders_select_all') }}"></th><th>{{ __('warehouse.physical_containers.container_no') }}</th><th>{{ __('warehouse.physical_containers.member_fields.asn') }}</th><th>{{ __('warehouse.physical_containers.member_fields.client') }}</th><th>{{ __('warehouse.physical_containers.member_fields.size') }}</th><th>{{ __('warehouse.physical_containers.member_fields.unpack_mode') }}</th><th class="num">{{ __('warehouse.physical_containers.member_fields.lines') }}</th><th>{{ __('warehouse.physical_containers.member_fields.status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($candidates as $c)
                            <tr>
                                <td><input type="checkbox" name="container_ids[]" value="{{ $c->id }}" aria-label="{{ $c->container_no }}" @checked($c->container_no === $box->container_no && $search === '')></td>
                                <td>{{ $c->container_no }} @if ($c->container_no !== $box->container_no)<mark>≠</mark>@endif</td>
                                <td><a href="{{ route('warehouse.asns.show', $c->asn_id) }}">{{ $c->asn->asn_no }}</a></td><td>{{ $c->asn->client?->name }}</td>
                                <td>{{ __('warehouse.container_sizes.'.$c->size) }}</td><td>{{ __('warehouse.unpack_modes.'.$c->unpack_mode) }}</td><td class="num">{{ $c->line_count }}</td>
                                <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.asn_statuses.', $c->asn->status) !!}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <button type="submit" style="width:auto">{{ __('warehouse.physical_containers.link_button') }}</button>
                </form>
                <script>
                    (function () {
                        var all = document.getElementById('link-all');
                        var boxes = Array.prototype.slice.call(document.querySelectorAll('#link-form input[name="container_ids[]"]'));
                        all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); });
                    })();
                </script>
            @endif
        </article>
    @endunless

    @if ($tasks->isNotEmpty())
        <h3>{{ __('warehouse.physical_containers.box_tasks') }}</h3>
        <table class="dense"><thead><tr><th>{{ __('warehouse.tasks.task_no') }}</th><th>{{ __('warehouse.tasks.type') }}</th><th>{{ __('warehouse.tasks.status') }}</th><th>{{ __('warehouse.tasks.notes') }}</th></tr></thead>
        <tbody>@foreach ($tasks as $t)<tr><td>{{ $t->task_no }}</td><td>{{ __('warehouse.task_types.'.$t->task_type) }}</td><td>{!! \App\Support\Ui\StatusBadge::render('warehouse.task_statuses.', $t->status) !!}</td><td>{{ $t->notes }}</td></tr>@endforeach</tbody></table>
    @endif

    @role('admin|finance|customer_service|dispatcher')
        <h3>{{ __('warehouse.physical_containers.charges_title') }}</h3>
        @if ($charges->isEmpty())
            <p class="text-muted">{{ __('warehouse.physical_containers.charges_none') }}</p>
        @else
            <table class="dense">
                <thead><tr><th>{{ __('billing.charges.job') }}</th><th>{{ __('billing.charges.code') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th class="num">{{ __('billing.charges.amount') }}</th><th>{{ __('billing.charges.status') }}</th><th>{{ __('warehouse.physical_containers.allocation_version') }}</th></tr></thead>
                <tbody>
                @foreach ($charges as $jobId => $rows)
                    @foreach ($rows as $c)
                        <tr><td>@if ($loop->first)<a href="{{ route('platform.jobs.show', $jobId) }}">{{ $c->job?->job_no }}</a>@endif</td><td><code>{{ $c->chargeCode->code }}</code> <small class="text-muted">{{ $c->chargeCode->customer_description }}</small></td><td class="num">{{ rtrim(rtrim(number_format($c->qty, 4), '0'), '.') }}</td><td class="num">{{ \App\Support\Money::cents((int) round($c->amount_cents))->format() }}</td><td>{!! \App\Support\Ui\StatusBadge::render('billing.charge_statuses.', $c->status) !!}</td><td>{{ $c->activity_version }}</td></tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        @endif
    @endrole
@endsection
