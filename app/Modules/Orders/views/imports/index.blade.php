@extends('layouts.app')

@section('title', __('orders.imports.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('orders.imports.title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('orders.imports.create') }}">{{ __('orders.imports.actions.new') }}</a></p>
    </header>

    @if ($imports->isEmpty())
        <p>{{ __('orders.imports.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>{{ __('orders.imports.fields.id') }}</th><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.imports.fields.source') }}</th>
                <th>{{ __('orders.imports.fields.file_name') }}</th>
                <th>{{ __('orders.imports.fields.status') }}</th><th>{{ __('orders.imports.fields.rows') }}</th>
                <th>{{ __('orders.imports.fields.failed_rows') }}</th><th>{{ __('orders.imports.fields.created_at') }}</th>
            </tr></thead>
            <tbody>@foreach ($imports as $import)<tr>
                <td><a href="{{ route('orders.imports.show', $import) }}">#{{ $import->id }}</a></td>
                <td>{{ $import->client->name }}</td>
                {{-- CHANGE_REQUESTS #123: portal submissions (客户门户) are listed next to the staff Excel imports, read-only on the page. --}}
                <td><span class="badge" data-tone="{{ $import->source === 'portal' ? 'info' : 'muted' }}">{{ __('orders.sources.'.$import->source) }}</span></td>
                <td>{{ $import->errors['context']['original_name'] ?? '—' }}</td>
                <td>{!! \App\Support\Ui\StatusBadge::render('orders.imports.statuses.', $import->status) !!}</td>
                <td>{{ $import->row_count }}</td><td>{{ $import->error_count }}</td><td>{{ $import->created_at }}</td>
            </tr>@endforeach</tbody>
        </table></div>
        {{ $imports->links() }}
    @endif
@endsection
