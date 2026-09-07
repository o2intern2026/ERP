@if ($order->fulfilments->isEmpty())
    <p>{{ __('orders.fulfilments.empty') }}</p>
@else
    @foreach ($order->fulfilments as $fulfilment)
        <article>
            <header><strong>{{ $order->order_no }}-{{ $fulfilment->seq }}</strong> · {{ __('orders.fulfilments.fields.warehouse') }} #{{ $fulfilment->warehouse_id }} · {{ __('orders.fulfilment_batch_statuses.'.$fulfilment->status) }}</header>
            <table>
                <thead><tr><th>{{ __('orders.fields.description') }}</th><th>{{ __('orders.fulfilments.fields.batch_qty') }}</th></tr></thead>
                <tbody>
                    @foreach ($fulfilment->lines as $line)
                        <tr><td>{{ $line->orderLine->description_cn ?: $line->orderLine->description_en }}</td><td>{{ $line->qty }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </article>
    @endforeach
@endif
