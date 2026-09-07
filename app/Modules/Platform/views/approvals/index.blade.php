@extends('layouts.app')

@section('title', __('platform.approvals.title'))

@section('content')
    <h1>{{ __('platform.approvals.title') }}</h1>
    <p>{{ __('platform.approvals.counts', ['pending' => $counts['pending'] ?? 0, 'approved' => $counts['approved'] ?? 0, 'rejected' => $counts['rejected'] ?? 0]) }} <small class="text-muted">· {{ __('platform.approvals.rule') }}</small></p>
    <form method="get" class="grid">
        <select name="status">@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? 'pending') === $s)>{{ __('platform.approvals.statuses.'.$s) }}</option>@endforeach</select>
        <select name="type"><option value="">{{ __('platform.approvals.type') }}: {{ __('platform.jobs.all') }}</option>@foreach ($types as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ __('platform.approvals.types.'.$t) }}</option>@endforeach</select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($approvals->isEmpty())
        <p class="text-muted">{{ __('platform.approvals.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('platform.approvals.type') }}</th><th>{{ __('platform.approvals.subject') }}</th><th>{{ __('platform.approvals.requester') }}</th><th>{{ __('platform.approvals.request_note') }}</th><th>{{ __('platform.approvals.status') }}</th><th>{{ __('platform.approvals.decider') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($approvals as $a)
                <tr>
                    <td>{{ $a->id }}</td>
                    <td>{{ __('platform.approvals.types.'.$a->type) }}</td>
                    <td>{{ $a->subject_type }} #{{ $a->subject_id }} @if ($a->client)<br><small class="text-muted">{{ $a->client->name }}</small>@endif</td>
                    <td>{{ $a->requester->name }}<br><small class="text-muted">{{ $a->created_at->format('m-d H:i') }}</small></td>
                    <td>{{ $a->request_note }}</td>
                    <td><span class="badge" data-tone="{{ ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'danger', 'cancelled' => 'muted'][$a->status] }}">{{ __('platform.approvals.statuses.'.$a->status) }}</span></td>
                    <td>{{ $a->decider?->name }} <small class="text-muted">{{ $a->decision_note }}</small></td>
                    <td>
                        @if ($a->isPending())
                            @role('admin|finance')
                                <form method="post" action="{{ route('platform.approvals.approve', $a) }}" class="inline">@csrf<input type="text" name="note" placeholder="{{ __('platform.approvals.decision_note') }}" style="width:12rem"><button type="submit">{{ __('platform.approvals.approve') }}</button></form>
                                <form method="post" action="{{ route('platform.approvals.reject', $a) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('platform.approvals.reject') }}</button></form>
                            @endrole
                            @if ($a->requested_by === auth()->id())
                                <form method="post" action="{{ route('platform.approvals.cancel', $a) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('platform.approvals.cancel') }}</button></form>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $approvals->links() }}
    @endif
@endsection
