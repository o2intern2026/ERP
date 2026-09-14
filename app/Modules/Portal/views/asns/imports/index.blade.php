@extends('layouts.app')

@section('title', __('portal.inbound.list_title'))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a></p>
    <header class="grid">
        <h1>{{ __('portal.inbound.list_title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('portal.asns.imports.create') }}">{{ __('portal.inbound.upload_button') }}</a></p>
    </header>
    <p class="text-muted"><small>{{ __('portal.inbound.hint') }}</small></p>

    @if ($imports->isEmpty())
        <p>{{ __('portal.inbound.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>#</th><th>{{ __('portal.inbound.fields.submitted_at') }}</th><th>{{ __('portal.inbound.fields.file_name') }}</th>
                <th>{{ __('portal.inbound.fields.container_no') }}</th><th>{{ __('portal.inbound.fields.expected_date') }}</th>
                <th class="num">{{ __('portal.inbound.fields.rows') }}</th><th>{{ __('portal.inbound.fields.status') }}</th>
                <th>{{ __('portal.inbound.fields.orders') }}</th><th>{{ __('portal.inbound.fields.asn') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($imports as $import)
                @php($context = $import->errors['context'] ?? [])
                @php($created = $import->errors['result']['created'] ?? [])
                <tr>
                    <td><a href="{{ route('portal.asns.imports.show', $import) }}">#{{ $import->id }}</a></td>
                    <td>{{ $import->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $context['original_name'] ?? '—' }}</td>
                    <td>{{ ($context['inbound']['container_no'] ?? null) ?: '—' }}</td>
                    <td>{{ ($context['inbound']['expected_date'] ?? null) ?: '—' }}</td>
                    <td class="num">{{ $import->row_count }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('portal.inbound.statuses.', $import->status) !!}</td>
                    <td>@forelse ($created as $entry)<a href="{{ route('portal.orders.show', $entry['order_id']) }}">{{ $entry['order_no'] }}</a>@if (! $loop->last), @endif @empty — @endforelse</td>
                    <td>{{ collect($created)->flatMap(fn ($entry) => $asns[(int) $entry['order_id']] ?? [])->unique()->implode(', ') ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $imports->links() }}
    @endif
@endsection
