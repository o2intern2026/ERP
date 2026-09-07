@extends('layouts.app')

@section('title', __('orders.imports.create_title'))

@section('content')
    <h1>{{ __('orders.imports.create_title') }}</h1>
    <p>{{ __('orders.imports.hint') }}</p>
    @if ($errors->any())
        <article><strong>{{ __('orders.validation.heading') }}</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif
    <form method="post" action="{{ route('orders.imports.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="grid">
            <label>{{ __('orders.fields.client') }}<select name="client_id" required><option value="">{{ __('orders.actions.select') }}</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) old('client_id') === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
            <label>{{ __('orders.fields.job') }}<select name="job_id" required><option value="">{{ __('orders.actions.select') }}</option>@foreach ($jobs as $job)<option value="{{ $job->id }}" @selected((int) old('job_id') === $job->id)>{{ $job->job_no }}</option>@endforeach</select></label>
        </div>
        <div class="grid">
            <label>{{ __('orders.fields.requested_date') }}<input type="date" name="requested_date" value="{{ old('requested_date') }}" required></label>
            <label>{{ __('orders.fields.service_level') }}<select name="service_level" required>@foreach ($serviceLevels as $level)<option value="{{ $level }}">{{ __('orders.service_levels.'.$level) }}</option>@endforeach</select></label>
        </div>
        <label>{{ __('orders.imports.fields.file') }}<input type="file" name="manifest" accept=".xlsx,.csv" required></label>
        <button type="submit">{{ __('orders.imports.actions.preview') }}</button>
        <a class="secondary" role="button" href="{{ route('orders.imports.index') }}">{{ __('orders.imports.actions.back') }}</a>
    </form>
@endsection
