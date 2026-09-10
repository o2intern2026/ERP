@extends('layouts.app')

@section('title', __('orders.drafts.title'))

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.actions.back') }}</a></p>
    <h1>{{ __('orders.drafts.title') }}</h1>
    <p class="text-muted"><small>{{ __('orders.drafts.hint') }}</small></p>
    @if ($errors->any())
        <article><strong>{{ __('orders.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <form method="post" action="{{ route('orders.drafts.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="grid">
            <label>{{ __('orders.fields.client') }}<select name="client_id" id="draft-client" required><option value="">{{ __('orders.actions.select') }}</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) old('client_id') === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
            <label>{{ __('orders.fields.job') }}<select name="job_id" id="draft-job"><option value="">{{ __('orders.pickup.new_job') }}</option>@foreach ($jobs as $job)<option value="{{ $job->id }}" data-client-id="{{ $job->client_id }}" @selected((int) old('job_id') === $job->id)>{{ $job->job_no }}</option>@endforeach</select></label>
            <label>{{ __('orders.fields.service_level') }}<select name="service_level" required>@foreach ($serviceLevels as $level)<option value="{{ $level }}" @selected(old('service_level', 'standard') === $level)>{{ __('orders.service_levels.'.$level) }}</option>@endforeach</select></label>
        </div>
        <label>{{ __('orders.drafts.file') }}<input type="file" name="document" accept=".pdf,.eml,.txt" required></label>
        <button type="submit">{{ __('orders.drafts.submit') }}</button>
    </form>

    <h2>{{ __('orders.drafts.recent') }}</h2>
    @if ($recent->isEmpty())
        <p class="text-muted">{{ __('orders.drafts.none') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('orders.fields.order_no') }}</th><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.drafts.matched') }}</th><th>{{ __('orders.imports.fields.created_at') }}</th></tr></thead>
            <tbody>
                @foreach ($recent as $import)
                    <tr>
                        <td>@if ($import->errors['order_id'] ?? null)<a href="{{ route('orders.show', $import->errors['order_id']) }}">{{ $import->errors['order_no'] ?? $import->errors['order_id'] }}</a>@endif</td>
                        <td>{{ $import->client->name }}</td>
                        <td>{{ collect($import->errors['matched'] ?? [])->map(fn ($f) => __('orders.drafts.fields.'.$f))->implode(', ') ?: __('orders.drafts.nothing_matched') }}</td>
                        <td>{{ $import->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <script>
        (() => {
            const client = document.getElementById('draft-client');
            const job = document.getElementById('draft-job');
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
