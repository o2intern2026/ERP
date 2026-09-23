@extends('layouts.app')

@section('title', __('orders.imports.create_title'))

@section('content')
    <h1>{{ __('orders.imports.create_title') }}</h1>
    <p>{{ __('orders.imports.hint') }}</p>
    @if ($errors->any())
        <article><strong>{{ __('orders.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif
    <form method="post" action="{{ route('orders.imports.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="grid">
            <label>{{ __('orders.fields.client') }}<select name="client_id" id="import-client" required><option value="">{{ __('orders.actions.select') }}</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) old('client_id') === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
            {{-- 2026-09-10 audit: Job options name their owner and are filtered to the selected client (same as orders::drafts.create). --}}
            <label>{{ __('orders.fields.job') }}<select name="job_id" id="import-job" required><option value="">{{ __('orders.actions.select') }}</option>@foreach ($jobs as $job)<option value="{{ $job->id }}" data-client-id="{{ $job->client_id }}" @selected((int) old('job_id') === $job->id)>{{ $job->job_no }} — {{ $job->client?->name }}</option>@endforeach</select></label>
        </div>
        <div class="grid">
            <label>{{ __('orders.fields.requested_date') }}<x-date-field name="requested_date" value="{{ old('requested_date') }}" required /></label>
            <label>{{ __('orders.fields.service_level') }}<select name="service_level" required>@foreach ($serviceLevels as $level)<option value="{{ $level }}" @selected(old('service_level', 'standard') === $level)>{{ __('orders.service_levels.'.$level) }}</option>@endforeach</select></label>
        </div>
        {{-- CHANGE_REQUESTS #143 导入选项: 按唛头 | 按收件人 grouping and the address type of rows without an explicit 地址类型. --}}
        <div class="grid">
            <label>{{ __('orders.imports.options.group_by') }}<select name="group_by">@foreach ($groupBys as $option)<option value="{{ $option }}" @selected(old('group_by', 'mark') === $option)>{{ __('orders.imports.options.group_by_options.'.$option) }}</option>@endforeach</select></label>
            <label>{{ __('orders.imports.options.address_type_default') }}<select name="address_type_default">@foreach ($addressTypeDefaults as $option)<option value="{{ $option }}" @selected(old('address_type_default', 'auto') === $option)>{{ __('orders.imports.options.address_type_defaults.'.$option) }}</option>@endforeach</select></label>
        </div>
        <p class="text-muted"><small>{{ __('orders.imports.options.group_by_hint') }} {{ __('orders.imports.options.address_type_default_hint') }}</small></p>
        <p class="text-muted"><small>{{ __('orders.imports.consolidation_hint') }}</small></p>
        <label>{{ __('orders.imports.fields.file') }}<input type="file" name="manifest" accept=".xlsx,.csv" required></label>
        <button type="submit">{{ __('orders.imports.actions.preview') }}</button>
        <a class="secondary" role="button" href="{{ route('orders.imports.index') }}">{{ __('orders.imports.actions.back') }}</a>
    </form>

    <script>
        (() => {
            const client = document.getElementById('import-client');
            const job = document.getElementById('import-job');
            const options = Array.from(job.options).slice(1);
            const filter = () => {
                options.forEach(o => { const off = o.dataset.clientId !== client.value; o.hidden = off; o.disabled = off; });
                if (job.selectedOptions[0]?.disabled) job.value = '';
            };
            client.addEventListener('change', filter);
            filter();
        })();
    </script>
@endsection
