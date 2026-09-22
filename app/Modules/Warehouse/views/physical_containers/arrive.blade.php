@extends('layouts.app')

@section('title', __('warehouse.physical_containers.arrive_confirm.title'))

@section('content')
    <p><a href="{{ route('warehouse.physical_containers.show', $box) }}">← {{ $box->container_no }}</a></p>
    <h1>{{ __('warehouse.physical_containers.arrive_confirm.title') }} · {{ $box->container_no }}</h1>
    <p>{{ $box->warehouse->code }} · {{ __('warehouse.container_sizes.'.$box->size) }} · {{ __('warehouse.unpack_modes.'.$box->unpack_mode) }} · {{ __('warehouse.physical_containers.gross_weight') }}: {{ $box->gross_weight_kg ?? '—' }} · {{ __('warehouse.physical_containers.cartage_by_us') }}: {{ $box->cartage_by_us ? __('platform.common.yes') : __('platform.common.no') }} · {{ __('warehouse.physical_containers.sideloader_required') }}: {{ $box->sideloader_required ? __('platform.common.yes') : __('platform.common.no') }}</p>
    {{-- Audit 2026-09-22 INBOUND-16 (CR #141): 登记到港 publishes physical_container.arrived, the event that bills cartage / sideloader to every member — show what it will raise first. --}}
    <p class="text-muted"><small>{{ __('warehouse.physical_containers.arrive_confirm.hint') }}</small></p>
    @error('arrive')<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror

    @if ($box->members->isEmpty())
        <p><mark>{{ __('warehouse.physical_containers.errors.no_members', ['no' => $box->container_no]) }}</mark></p>
    @elseif ($preview['codes'] === [])
        <p><mark>{{ __('warehouse.physical_containers.arrive_confirm.no_charges') }}</mark> <a href="{{ route('warehouse.physical_containers.edit', $box) }}">{{ __('warehouse.physical_containers.edit') }}</a></p>
    @else
        <h2>{{ __('warehouse.physical_containers.arrive_confirm.will_raise', ['codes' => implode(' + ', $preview['codes'])]) }}</h2>
        @if ($preview['over_weight'])<p><mark>{{ __('warehouse.physical_containers.arrive_confirm.over_weight') }}</mark></p>@endif
        @if ($preview['shares'] && $preview['shares']['provisional'])<p class="text-muted"><small>{{ __('warehouse.physical_containers.provisional_hint') }}</small></p>@endif
        <div class="overflow-auto"><table class="dense" id="arrive-preview">
            <thead><tr><th>{{ __('warehouse.physical_containers.member_fields.asn') }}</th><th>{{ __('warehouse.physical_containers.member_fields.client') }}</th><th>{{ __('warehouse.physical_containers.member_fields.job') }}</th><th>{{ __('warehouse.physical_containers.arrive_confirm.code') }}</th><th class="num">{{ __('warehouse.physical_containers.member_fields.share') }}</th><th class="num">{{ __('warehouse.physical_containers.arrive_confirm.amount') }}</th></tr></thead>
            <tbody>
            @foreach ($preview['lines'] as $l)
                @php($asn = $jobs->get($l['job_id']))
                <tr>
                    <td>{{ $l['asn_no'] }}</td><td>{{ $asn?->client?->name }}</td><td>{{ $asn?->job?->job_no }}</td>
                    <td><code>{{ $l['code'] }}</code></td>
                    <td class="num">{{ number_format($l['share'] * 100, 2) }} %</td>
                    <td class="num">
                        @if ($l['missing_rate'])<span class="badge" data-tone="warn">{{ __('warehouse.physical_containers.arrive_confirm.missing_rate') }}</span>
                        @elseif ($l['is_poa'] || $l['amount_cents'] === null)<span class="badge" data-tone="warn">{{ __('warehouse.physical_containers.arrive_confirm.poa') }}</span>
                        @else {{ \App\Support\Money::cents((int) $l['amount_cents'])->format() }}@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    @if ($box->members->isNotEmpty())
        <form method="post" action="{{ route('warehouse.physical_containers.arrive', $box) }}" class="inline" onsubmit="this.querySelector('button[type=submit]').disabled = true">
            @csrf
            <button type="submit">{{ __('warehouse.physical_containers.arrive_confirm.confirm') }}</button>
        </form>
    @endif
    <a href="{{ route('warehouse.physical_containers.show', $box) }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
@endsection
