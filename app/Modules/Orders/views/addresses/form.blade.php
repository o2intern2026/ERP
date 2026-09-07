@extends('layouts.app')

@section('title', $address->exists ? __('orders.addresses.edit_title') : __('orders.addresses.create_title'))

@section('content')
    <p><a href="{{ route('orders.addresses.index') }}">← {{ __('orders.addresses.actions.back') }}</a></p>
    <h1>{{ $address->exists ? __('orders.addresses.edit_title') : __('orders.addresses.create_title') }}</h1>

    @if ($errors->any())
        <article>
            <strong>{{ __('orders.validation.heading') }}</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </article>
    @endif

    <form method="post" action="{{ $address->exists ? route('orders.addresses.update', $address) : route('orders.addresses.store') }}">
        @csrf
        @if ($address->exists) @method('PUT') @endif

        <div class="grid">
            <label>{{ __('orders.fields.client') }}
                <select name="client_id" required>
                    <option value="">{{ __('orders.actions.select') }}</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) old('client_id', $address->client_id) === $client->id)>{{ $client->code }} — {{ $client->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('orders.addresses.fields.label') }}<input name="label" value="{{ old('label', $address->label) }}" required></label>
            <label>{{ __('orders.fields.address_type') }}
                <select name="address_type" required>
                    @foreach ($addressTypes as $type)
                        <option value="{{ $type }}" @selected(old('address_type', $address->address_type ?: 'business') === $type)>{{ __('orders.address_types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="grid">
            <label>{{ __('orders.addresses.fields.contact_name') }}<input name="contact_name" value="{{ old('contact_name', $address->contact_name) }}"></label>
            <label>{{ __('orders.addresses.fields.phone') }}<input name="phone" value="{{ old('phone', $address->phone) }}"></label>
        </div>
        <label>{{ __('orders.fields.address') }}<input name="address" value="{{ old('address', $address->address) }}" required></label>
        <div class="grid">
            <label>{{ __('orders.fields.suburb') }}<input name="suburb" value="{{ old('suburb', $address->suburb) }}" required></label>
            <label>{{ __('orders.fields.state') }}
                <select name="state" required>
                    <option value="">{{ __('orders.actions.select') }}</option>
                    @foreach ($states as $state)
                        <option value="{{ $state }}" @selected(old('state', $address->state) === $state)>{{ $state }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('orders.fields.postcode') }}<input name="postcode" value="{{ old('postcode', $address->postcode) }}" required></label>
        </div>
        <label>{{ __('orders.addresses.fields.instructions') }}
            <textarea name="default_instructions">{{ old('default_instructions', $address->default_instructions) }}</textarea>
        </label>
        <button type="submit">{{ __('orders.actions.save_changes') }}</button>
    </form>
@endsection
