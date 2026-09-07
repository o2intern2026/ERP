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
                <th>{{ __('orders.imports.fields.id') }}</th><th>{{ __('orders.fields.client') }}</th>
                <th>{{ __('orders.imports.fields.status') }}</th><th>{{ __('orders.imports.fields.rows') }}</th>
                <th>{{ __('orders.imports.fields.failed_rows') }}</th><th>{{ __('orders.imports.fields.created_at') }}</th>
            </tr></thead>
            <tbody>@foreach ($imports as $import)<tr>
                <td><a href="{{ route('orders.imports.show', $import) }}">#{{ $import->id }}</a></td>
                <td>{{ $import->client->name }}</td><td>{{ __('orders.imports.statuses.'.$import->status) }}</td>
                <td>{{ $import->row_count }}</td><td>{{ $import->error_count }}</td><td>{{ $import->created_at }}</td>
            </tr>@endforeach</tbody>
        </table></div>
        {{ $imports->links() }}
    @endif
@endsection
