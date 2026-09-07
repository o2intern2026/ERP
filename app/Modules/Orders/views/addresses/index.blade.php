@extends('layouts.app')

@section('title', __('orders.addresses.title'))

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.actions.back') }}</a></p>
    <header class="grid">
        <h1>{{ __('orders.addresses.title') }}</h1>
        @if (auth()->user()->hasAnyRole(['admin', 'customer_service', 'dispatcher']))
            <p style="text-align:right"><a role="button" href="{{ route('orders.addresses.create') }}">{{ __('orders.addresses.actions.create') }}</a></p>
        @endif
    </header>

    <form method="get" class="grid">
        <select name="client_id" aria-label="{{ __('orders.fields.client') }}">
            <option value="">{{ __('orders.filters.all_clients') }}</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="secondary">{{ __('orders.actions.filter') }}</button>
    </form>

    @if ($addresses->isEmpty())
        <p>{{ __('orders.addresses.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table>
                <thead><tr>
                    <th>{{ __('orders.fields.client') }}</th>
                    <th>{{ __('orders.addresses.fields.label') }}</th>
                    <th>{{ __('orders.addresses.fields.contact') }}</th>
                    <th>{{ __('orders.fields.address') }}</th>
                    <th>{{ __('orders.fields.address_type') }}</th>
                    <th>{{ __('orders.addresses.fields.instructions') }}</th>
                    <th>{{ __('orders.addresses.fields.usage') }}</th>
                    <th>{{ __('orders.addresses.fields.action') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($addresses as $address)
                        <tr>
                            <td>{{ $address->client->name }}</td>
                            <td>{{ $address->label }}</td>
                            <td>{{ $address->contact_name }}@if ($address->phone)<br>{{ $address->phone }}@endif</td>
                            <td>{{ $address->address }}, {{ $address->suburb }} {{ $address->state }} {{ $address->postcode }}</td>
                            <td>{{ __('orders.address_types.'.$address->address_type) }}</td>
                            <td>{{ $address->default_instructions ?: __('orders.not_provided') }}</td>
                            <td>{{ $address->usage_count }}</td>
                            <td><a href="{{ route('orders.addresses.edit', $address) }}">{{ __('orders.addresses.actions.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $addresses->links() }}
    @endif
@endsection
