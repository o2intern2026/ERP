@extends('layouts.app')

@section('title', $asn->asn_no)

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a></p>
    <header>
        <h1>{{ $asn->asn_no }}</h1>
        <p>
            {!! \App\Support\Ui\StatusBadge::render('warehouse.asn_statuses.', $asn->status) !!}
            @if ($asn->isPendingClientConfirmation())<span class="badge" data-tone="warn">{{ __('portal.asns.pending_badge') }}</span>@elseif ($asn->isClientSubmitted())<span class="badge" data-tone="ok">{{ __('portal.asns.confirmed_badge') }}</span>@endif
            · {{ __('warehouse.inbound_types.'.$asn->inbound_type) }} · {{ $asn->warehouse->code }} · {{ $asn->warehouse->name }}
        </p>
    </header>

    <article class="kv-card">
        <strong>{{ __('portal.asns.sections.header') }}</strong>
        <dl class="kv-2">
            <dt>{{ __('portal.asns.fields.containers') }}</dt>
            <dd>@forelse ($asn->containers as $c){{ $c->container_no }} · {{ __('warehouse.container_sizes.'.$c->size) }} · {{ __('warehouse.unpack_modes.'.$c->unpack_mode) }}@if (! $loop->last)<br>@endif @empty — @endforelse</dd>
            <dt>{{ __('portal.asns.fields.expected_date') }}</dt><dd>{{ $asn->expected_date?->format('Y-m-d') ?? '—' }}</dd>
            <dt>{{ __('portal.asns.fields.reference') }}</dt><dd>{{ $asn->job?->reference ?? '—' }}</dd>
            <dt>{{ __('portal.asns.fields.job_no') }}</dt><dd>{{ $asn->job?->job_no ?? '—' }}</dd>
            <dt>{{ __('portal.asns.submitted_at') }}</dt><dd>{{ $asn->created_at?->format('Y-m-d H:i') }}</dd>
            <dt>{{ __('portal.asns.confirmed_at') }}</dt><dd>{{ $asn->client_confirmed_at?->format('Y-m-d H:i') ?? __('portal.asns.pending_badge') }}</dd>
            @if ($asn->notes)<dt>{{ __('portal.asns.fields.notes') }}</dt><dd>{{ $asn->notes }}</dd>@endif
        </dl>
    </article>

    <h2>{{ __('portal.asns.sections.imports') }}</h2>
    @if ($imports->isEmpty())
        <p class="text-muted">{{ __('portal.asns.imports.none') }}</p>
    @else
        @php($latest = $imports->first())
        <p>
            {{ __('portal.asns.imports.rows', ['rows' => $latest->row_count]) }} · {{ __('portal.asns.imports.errors', ['count' => $latest->error_count]) }} · {{ __('portal.asns.imports.warnings', ['count' => count($latest->warnings ?? [])]) }}
            <small class="text-muted">{{ $latest->created_at?->format('Y-m-d H:i') }}</small>
        </p>
        @if (! empty($latest->errors) || ! empty($latest->warnings))
            <ul>
                @foreach (array_slice($latest->errors ?? [], 0, 50) as $e)<li><mark>{{ __('portal.asns.imports.row', ['row' => $e['row'] ?? '—']) }}</mark> {{ $e['column'] ?? '' }} · {{ $e['message'] ?? '' }}</li>@endforeach
                @foreach (array_slice($latest->warnings ?? [], 0, 50) as $w)<li class="text-muted">{{ __('portal.asns.imports.row', ['row' => $w['row'] ?? '—']) }} {{ $w['column'] ?? '' }} · {{ $w['message'] ?? '' }}</li>@endforeach
            </ul>
        @endif
    @endif
    @if ($canReplace)
        <form method="post" action="{{ route('portal.asns.import', $asn) }}" enctype="multipart/form-data" class="grid">
            @csrf
            <label>{{ __('portal.asns.replace') }}<input type="file" name="packing_list" accept=".xlsx,.xls,.csv" required></label>
            <button type="submit" class="secondary" style="align-self:end">{{ __('portal.asns.replace') }}</button>
        </form>
        <p class="text-muted"><small>{{ __('portal.asns.replace_hint') }}</small></p>
    @endif

    <h2>{{ __('portal.asns.sections.lines') }}</h2>
    @if ($asn->lines->isEmpty())
        <p class="text-muted">{{ __('portal.asns.imports.none') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>#</th><th>{{ __('portal.asns.fields.mark') }}</th><th>{{ __('portal.asns.fields.description') }}</th><th>{{ __('portal.asns.fields.container_no') }}</th>
                <th class="num">{{ __('portal.asns.fields.expected_cartons') }}</th><th class="num">{{ __('portal.asns.fields.received_cartons') }}</th><th class="num">{{ __('portal.asns.fields.damaged_cartons') }}</th><th>{{ __('portal.asns.fields.deliver_to') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($asn->lines as $l)
                <tr>
                    <td>{{ $loop->iteration }}</td><td>{{ $l->consignment_mark ?: '—' }}</td><td>{{ $l->description }}</td><td>{{ $l->container?->container_no ?? '—' }}</td>
                    <td class="num">{{ $l->expected_cartons }}</td><td class="num">{{ $l->received_cartons }}</td><td class="num">{{ $l->damaged_cartons }}</td>
                    <td>{{ $l->deliver_to_name ?: '—' }} <small class="text-muted">{{ trim(($l->deliver_to_suburb ?? '').' '.($l->deliver_to_state ?? '').' '.($l->deliver_to_postcode ?? '')) }}</small></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <h2>{{ __('portal.asns.sections.receipts') }}</h2>
    @if ($asn->goodsReceipts->isEmpty())
        <p class="text-muted">{{ __('portal.asns.receipts.none') }}</p>
    @else
        <ul>
            @foreach ($asn->goodsReceipts->sortBy('batch_no') as $r)
                <li>
                    {{ $r->receipt_no }} · {{ __('portal.asns.receipts.batch', ['batch' => $r->batch_no]) }} · {{ $r->received_cartons }} / {{ $r->expected_cartons }}
                    @if ($r->pdfDocument)· <a href="{{ route('portal.documents.download', $r->pdfDocument) }}">{{ __('portal.asns.receipts.download') }}</a>@else· <span class="text-muted">{{ __('portal.asns.receipts.draft') }}</span>@endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
