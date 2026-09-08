{{-- 入库单 batches of one ASN (预报单) + the one-line roll-up. Used by asns/show and receipts/show. Needs $asn, $batches (with lines_count), $rollup. --}}
@php($rollupSign = $rollup['variance'] > 0 ? '+' : '')
<p class="text-muted"><small>{{ __('warehouse.asns.rollup', ['expected' => $rollup['expected'], 'received' => $rollup['received'], 'damaged' => $rollup['damaged'], 'variance' => $rollupSign.$rollup['variance'], 'done' => $rollup['received_lines'], 'total' => $rollup['total_lines']]) }}</small></p>
@if ($batches->isEmpty())
    <p class="text-muted">{{ __('warehouse.receipts.none_yet') }}</p>
@else
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('warehouse.receipts.receipt_no') }}</th><th>{{ __('warehouse.receipts.status') }}</th><th class="num">{{ __('warehouse.receipts.lines') }}</th><th class="num">{{ __('warehouse.receipts.received') }} / {{ __('warehouse.receipts.damaged') }}</th><th>{{ __('warehouse.receipts.opened_at') }}</th><th>{{ __('warehouse.receipts.completed_at') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
        <tbody>
        @foreach ($batches as $b)
            <tr>
                <td><a href="{{ route('warehouse.receipts.show', $b) }}">{{ $b->receipt_no }}</a></td>
                <td><span class="badge" data-tone="{{ $b->isOpen() ? 'warn' : 'ok' }}">{{ __('warehouse.receipt_statuses.'.$b->status) }}</span></td>
                <td class="num">{{ $b->lines_count }}</td>
                <td class="num">@if ($b->isOpen()){{ $b->lines->sum('received_cartons') }} / {{ $b->lines->sum('damaged_cartons') }}@else{{ $b->received_cartons }} / {{ $b->damaged_cartons }}@endif</td>
                <td>{{ $b->opened_at?->format('Y-m-d H:i') }}</td>
                <td>{{ $b->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td>
                    <a href="{{ route('warehouse.receipts.show', $b) }}">{{ __('warehouse.receipts.view') }}</a>
                    · <a href="{{ route('warehouse.receipts.pdf', $b) }}" target="_blank">PDF</a>
                    @role('admin|warehouse_supervisor|warehouse_operator')
                        @if ($b->isOpen() && $b->lines_count > 0)
                            · <form method="post" action="{{ route('warehouse.receipts.complete', $b) }}" class="inline" onsubmit="this.querySelector('button[type=submit]').disabled = true">@csrf<button type="submit" class="secondary">{{ __('warehouse.receipts.complete') }}</button></form>
                        @endif
                    @endrole
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
@endif
