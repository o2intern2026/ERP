@extends('layouts.app')

@section('title', __('portal.inbound.list_title'))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a></p>
    <header class="grid">
        <h1>{{ __('portal.inbound.list_title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('portal.asns.imports.create') }}">{{ __('portal.inbound.upload_button') }}</a> <a role="button" class="secondary" href="{{ route('portal.asns.imports.manual.create') }}">{{ __('portal.inbound.manual.button') }}</a></p>
    </header>
    <p class="text-muted"><small>{{ __('portal.inbound.hint') }}</small></p>

    @if ($imports->isEmpty())
        <p>{{ __('portal.inbound.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>#</th><th>{{ __('portal.inbound.fields.submitted_at') }}</th><th>{{ __('portal.inbound.fields.source') }}</th>
                <th>{{ __('portal.inbound.fields.container_no') }}</th><th>{{ __('portal.inbound.fields.expected_date') }}</th>
                <th class="num">{{ __('portal.inbound.fields.rows') }}</th><th>{{ __('portal.inbound.fields.status') }}</th>
                <th>{{ __('portal.inbound.fields.orders') }}</th><th>{{ __('portal.inbound.fields.asn') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($imports as $import)
                @php($context = $import->errors['context'] ?? [])
                @php($created = $import->errors['result']['created'] ?? [])
                {{-- CHANGE_REQUESTS #128: a manual list shows 手工录入 instead of a file name, a draft links to 继续编辑, attached orders count in the 订单 column. --}}
                @php($draft = $import->status === 'draft')
                @php($attachedIds = $import->status === 'imported' ? array_map('intval', array_column($import->errors['result']['attached'] ?? [], 'order_id')) : $import->manualAttachedIds())
                <tr>
                    <td><a href="{{ $draft ? route('portal.asns.imports.manual.edit', $import) : route('portal.asns.imports.show', $import) }}">#{{ $import->id }}</a></td>
                    <td>{{ $import->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $context['original_name'] ?? ($import->isManual() ? __('portal.inbound.manual.source') : '—') }}</td>
                    <td>{{ ($context['inbound']['container_no'] ?? null) ?: '—' }}</td>
                    <td>{{ ($context['inbound']['expected_date'] ?? null) ?: '—' }}</td>
                    <td class="num">{{ $import->row_count }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('portal.inbound.statuses.', $import->status) !!}@if (is_array($context['inbound']['collection'] ?? null)) <span class="badge" data-tone="info">{{ __('portal.inbound.collection.badge') }}</span>@endif @if ($draft)<a href="{{ route('portal.asns.imports.manual.edit', $import) }}">{{ __('portal.inbound.manual.actions.continue') }}</a>@endif</td>
                    <td>
                        @forelse ($created as $entry)<a href="{{ route('portal.orders.show', $entry['order_id']) }}">{{ $entry['order_no'] }}</a>@if (! $loop->last), @endif @empty @if ($attachedIds === [])—@endif @endforelse
                        @if ($attachedIds !== [])<br><small>{{ __('portal.inbound.manual.attached_count', ['count' => count($attachedIds)]) }}: @foreach ($attachedIds as $id)@if (isset($orders[$id]))<a href="{{ route('portal.orders.show', $id) }}">{{ $orders[$id]->order_no }}</a>@if (! $loop->last), @endif @endif @endforeach</small>@endif
                    </td>
                    <td>{{ collect([...array_column($created, 'order_id'), ...$attachedIds])->flatMap(fn ($id) => $asns[(int) $id] ?? [])->unique()->implode(', ') ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $imports->links() }}
    @endif
@endsection
