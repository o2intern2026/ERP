<div class="overflow-auto">
    <table>
        <thead><tr>
            <th>{{ __('orders.fields.description') }}</th>
            <th>{{ __('orders.fulfilments.fields.asn_line') }}</th>
            <th>{{ __('orders.fulfilments.fields.ordered') }}</th>
            <th>{{ __('orders.fulfilments.fields.allocated') }}</th>
            <th>{{ __('orders.fulfilments.fields.available') }}</th>
            <th>{{ __('orders.fulfilments.fields.backordered') }}</th>
            <th>{{ __('orders.fulfilments.fields.stock_result') }}</th>
        </tr></thead>
        <tbody>
            @foreach ($availability as $row)
                <tr>
                    <td>{{ $row['line']->description_cn ?: $row['line']->description_en }}</td>
                    <td>{{ $row['line']->asn_line_id ?? __('orders.not_provided') }}</td>
                    <td>{{ $row['line']->carton_qty }}</td>
                    <td>{{ $row['allocated_qty'] }}</td>
                    <td>{{ $row['qty_available'] ?? __('orders.not_provided') }}</td>
                    <td>{{ $row['line']->qty_backordered }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('orders.fulfilments.availability_statuses.', $row['status']) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
