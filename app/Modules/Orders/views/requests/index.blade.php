@extends('layouts.app')

@section('title', __('orders.requests.title'))

@section('content')
    <h1>{{ __('orders.requests.title') }}</h1>
    <p class="text-muted"><small>{{ __('orders.requests.hint') }}</small></p>

    <h2>{{ __('orders.requests.cancels_title') }} <span class="badge" data-tone="{{ $cancels->isEmpty() ? 'muted' : 'warn' }}">{{ $cancels->count() }}</span></h2>
    @if ($cancels->isEmpty())
        <p class="text-muted">{{ __('orders.requests.cancels_empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense" id="cancel-requests">
            <thead><tr><th>{{ __('orders.requests.submitted') }}</th><th>{{ __('orders.fields.order_no') }}</th><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.requests.order_status') }}</th><th>{{ __('orders.requests.reason') }}</th><th>{{ __('orders.requests.by') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($cancels as $e)
                <tr>
                    <td>{{ $e->created_at->format('m-d H:i') }}<br><small class="text-muted">{{ $e->created_at->diffForHumans() }}</small></td>
                    <td>@if ($e->order)<a href="{{ route('orders.show', $e->order) }}#cancel-request">{{ $e->order->order_no }}</a>@else #{{ $e->order_id }}@endif</td>
                    <td>{{ $e->order?->client?->name }}</td>
                    <td>@if ($e->order)<span class="badge" data-tone="warn">{{ __('orders.statuses.'.$e->order->operational_status) }}</span>@endif</td>
                    <td style="white-space:normal;min-width:16rem">{{ $e->message }}</td>
                    <td>{{ $e->creator?->name ?? '—' }}</td>
                    <td style="white-space:normal;min-width:18rem">
                        @if ($e->order && (($canExecute[$e->id] ?? false) || $canDecide))
                            @if ($canExecute[$e->id] ?? false)
                                <form method="post" action="{{ route('orders.cancel', $e->order) }}" class="inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('orders.changes.confirm_cancel') }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="{{ \Illuminate\Support\Str::limit($e->message, 200, '') }}">
                                    <button type="submit" class="contrast" style="width:auto;padding:.2rem .7rem;margin:0">{{ __('orders.cancel_request.execute') }}</button>
                                </form>
                            @elseif ($canDecide)
                                <small class="text-muted">{{ __('orders.requests.needs_supervisor') }}</small>
                            @endif
                            @if ($canDecide)
                            <form method="post" action="{{ route('orders.cancel_request.reject', $e->order) }}" class="inline" style="margin-top:.3rem">
                                @csrf
                                <input type="text" name="reason" placeholder="{{ __('orders.cancel_request.reject_reason') }}" required style="width:12rem;display:inline-block;margin:0 .3rem 0 0;padding:.2rem .5rem">
                                <button type="submit" class="secondary" style="width:auto;padding:.2rem .7rem;margin:0">{{ __('orders.cancel_request.reject') }}</button>
                            </form>
                            @endif
                        @elseif ($e->order)
                            <a href="{{ route('orders.show', $e->order) }}#cancel-request">{{ __('orders.requests.open_order') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <h2>{{ __('orders.requests.returns_title') }} <span class="badge" data-tone="{{ $returns->isEmpty() ? 'muted' : 'warn' }}">{{ $returns->count() }}</span></h2>
    <p class="text-muted"><small>{{ __('orders.requests.returns_hint') }}</small></p>
    @if ($returns->isEmpty())
        <p class="text-muted">{{ __('orders.requests.returns_empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense" id="return-requests">
            <thead><tr><th>{{ __('orders.requests.submitted') }}</th><th>{{ __('orders.requests.return_order') }}</th><th>{{ __('orders.requests.original_order') }}</th><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.requests.cartons') }}</th><th>{{ __('orders.requests.reason') }}</th><th>{{ __('orders.requests.order_status') }}</th></tr></thead>
            <tbody>
            @foreach ($returns as $r)
                <tr>
                    <td>{{ $r->created_at->format('m-d H:i') }}<br><small class="text-muted">{{ $r->created_at->diffForHumans() }}</small></td>
                    <td><a href="{{ route('orders.show', $r) }}">{{ $r->order_no }}</a></td>
                    <td>@if ($r->originalOrder)<a href="{{ route('orders.show', $r->originalOrder) }}">{{ $r->originalOrder->order_no }}</a>@endif</td>
                    <td>{{ $r->client?->name }}</td>
                    <td class="num">{{ $r->lines->sum('carton_qty') }}</td>
                    <td style="white-space:normal;min-width:16rem">{{ $r->request_note ?? '—' }}</td>
                    <td><span class="badge" data-tone="warn">{{ __('orders.statuses.'.$r->operational_status) }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <h2>{{ __('orders.requests.history_title') }}</h2>
    @if ($history['cancels']->isEmpty() && $history['returns']->isEmpty())
        <p class="text-muted">{{ __('orders.requests.history_empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense" id="request-history">
            <thead><tr><th>{{ __('orders.requests.decided_at') }}</th><th>{{ __('orders.requests.kind') }}</th><th>{{ __('orders.fields.order_no') }}</th><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.requests.outcome') }}</th><th>{{ __('orders.requests.by') }}</th></tr></thead>
            <tbody>
            @foreach ($history['cancels'] as $e)
                <tr>
                    <td>{{ $e->resolved_at?->format('m-d H:i') }}</td>
                    <td>{{ __('orders.requests.kind_cancel') }}</td>
                    <td>@if ($e->order)<a href="{{ route('orders.show', $e->order) }}">{{ $e->order->order_no }}</a>@else #{{ $e->order_id }}@endif</td>
                    <td>{{ $e->order?->client?->name }}</td>
                    <td style="white-space:normal;min-width:16rem">@if ($e->order?->operational_status === 'cancelled')<span class="badge" data-tone="ok">{{ __('orders.requests.outcome_cancelled') }}</span>@else<span class="badge" data-tone="danger">{{ __('orders.requests.outcome_rejected') }}</span>@endif <small>{{ $e->message }}</small></td>
                    <td>{{ $e->resolver?->name ?? __('orders.timeline.system') }}</td>
                </tr>
            @endforeach
            @foreach ($history['returns'] as $r)
                <tr>
                    <td>{{ $r->updated_at->format('m-d H:i') }}</td>
                    <td>{{ __('orders.requests.kind_return') }}</td>
                    <td><a href="{{ route('orders.show', $r) }}">{{ $r->order_no }}</a>@if ($r->originalOrder) <small class="text-muted">← {{ $r->originalOrder->order_no }}</small>@endif</td>
                    <td>{{ $r->client?->name }}</td>
                    <td><span class="badge" data-tone="ok">{{ __('orders.statuses.'.$r->operational_status) }}</span></td>
                    <td>—</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
@endsection
