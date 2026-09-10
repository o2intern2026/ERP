@extends('layouts.app')

@section('title', __('transport.driver_title'))

@section('content')
    <h1>{{ __('transport.driver_title') }}</h1>
    <p>{{ __('transport.driver.today', ['date' => today()->format('Y-m-d')]) }}</p>

    @if ($errors->any())
        <article aria-label="{{ __('transport.driver.errors') }}">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </article>
    @endif

    @if ($runs->isEmpty())
        <p>{{ __('transport.driver.no_runs') }}</p>
    @endif

    @foreach ($runs as $run)
        <article>
            <header>
                <strong>{{ $run->run_no }}</strong>
                · {{ $run->vehicle }}
                · {{ __('transport.run_statuses.'.$run->status) }}
            </header>

            @if ($run->stops->isEmpty())
                <p>{{ __('transport.driver.no_stops') }}</p>
            @endif

            @foreach ($run->stops as $stop)
                @php($receiver = data_get($stop->shipment->selectedQuote?->raw_response, '_quote_request.receiver', []))
                @php($deliveredPod = $stop->shipment->pods->firstWhere('delivered_at', '!=', null))
                <section>
                    <h2>{{ __('transport.driver.stop_number', ['sequence' => $stop->seq]) }} · {{ $stop->shipment->shipment_no }}</h2>
                    <dl>
                        <dt>{{ __('transport.driver.client') }}</dt>
                        <dd>{{ $stop->shipment->client->name }}</dd>
                        <dt>{{ __('transport.driver.eta') }}</dt>
                        <dd>{{ $stop->eta?->format('H:i') ?? __('transport.not_selected') }}</dd>
                        <dt>{{ __('transport.driver.receiver') }}</dt>
                        <dd>{{ data_get($receiver, 'name', __('transport.not_selected')) }}</dd>
                        <dt>{{ __('transport.driver.address') }}</dt>
                        <dd>
                            {{ collect([
                                data_get($receiver, 'address'),
                                data_get($receiver, 'suburb'),
                                data_get($receiver, 'state'),
                                data_get($receiver, 'postcode'),
                            ])->filter()->implode(', ') ?: __('transport.not_selected') }}
                        </dd>
                    </dl>

                    @if ($stop->shipment->tailgate_required)
                        <p role="alert"><strong>⚠ {{ __('transport.driver.tailgate_required') }}</strong></p>
                    @endif

                    @if ($deliveredPod)
                        <p><strong>{{ __('transport.driver.pod_saved', [
                            'recipient' => $deliveredPod->recipient_name,
                            'time' => $deliveredPod->delivered_at->format('H:i'),
                        ]) }}</strong></p>
                    @else
                        <details>
                            <summary>{{ __('transport.driver.capture_delivery') }}</summary>
                            <form method="post" enctype="multipart/form-data"
                                  action="{{ route('transport.driver.stops.deliver', $stop) }}"
                                  data-pod-form>
                                @csrf
                                <label>
                                    {{ __('transport.driver.recipient_name') }}
                                    <input name="recipient_name" value="{{ old('recipient_name') }}" maxlength="150" required>
                                </label>
                                <label>{{ __('transport.driver.signature') }}</label>
                                <canvas width="640" height="240" data-signature-pad
                                        style="width:100%;max-width:640px;border:2px solid currentColor;touch-action:none"></canvas>
                                <input type="hidden" name="signature_data" data-signature-data>
                                {{-- 2026-09-10 audit: `required` on a hidden input is inert, so the missing-signature message is rendered here instead. --}}
                                <p role="alert" data-signature-error hidden><strong>{{ __('transport.driver.signature_required') }}</strong></p>
                                <button type="button" class="secondary" data-signature-clear>{{ __('transport.driver.clear_signature') }}</button>
                                <label>
                                    {{ __('transport.driver.photos') }}
                                    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp"
                                           capture="environment" multiple required>
                                </label>
                                <button type="submit">{{ __('transport.driver.submit_delivery') }}</button>
                            </form>
                        </details>

                        <details>
                            <summary>{{ __('transport.driver.report_failure') }}</summary>
                            <form method="post" action="{{ route('transport.driver.stops.fail', $stop) }}">
                                @csrf
                                <label>
                                    {{ __('transport.driver.failure_reason') }}
                                    <select name="failure_reason" required>
                                        <option value="">{{ __('transport.driver.choose_failure_reason') }}</option>
                                        @foreach ($failureReasons as $reason)
                                            <option value="{{ $reason }}">{{ __('transport.driver.failure_reasons.'.$reason) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button type="submit" class="secondary">{{ __('transport.driver.submit_failure') }}</button>
                            </form>
                        </details>
                    @endif
                </section>
            @endforeach
        </article>
    @endforeach

    <script>
        document.querySelectorAll('[data-pod-form]').forEach((form) => {
            const canvas = form.querySelector('[data-signature-pad]');
            const input = form.querySelector('[data-signature-data]');
            const clear = form.querySelector('[data-signature-clear]');
            const signatureError = form.querySelector('[data-signature-error]');
            const context = canvas.getContext('2d');
            let drawing = false;
            let signed = false;

            const point = (event) => {
                const bounds = canvas.getBoundingClientRect();
                return {
                    x: (event.clientX - bounds.left) * canvas.width / bounds.width,
                    y: (event.clientY - bounds.top) * canvas.height / bounds.height,
                };
            };

            canvas.addEventListener('pointerdown', (event) => {
                drawing = true;
                signed = true;
                signatureError.hidden = true;
                canvas.setPointerCapture(event.pointerId);
                const start = point(event);
                context.beginPath();
                context.moveTo(start.x, start.y);
            });
            canvas.addEventListener('pointermove', (event) => {
                if (!drawing) return;
                const next = point(event);
                context.lineWidth = 3;
                context.lineCap = 'round';
                context.lineTo(next.x, next.y);
                context.stroke();
            });
            canvas.addEventListener('pointerup', () => drawing = false);
            canvas.addEventListener('pointercancel', () => drawing = false);
            clear.addEventListener('click', () => {
                context.clearRect(0, 0, canvas.width, canvas.height);
                input.value = '';
                signed = false;
            });
            form.addEventListener('submit', (event) => {
                if (!signed) {
                    signatureError.hidden = false;
                    canvas.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    event.preventDefault();
                    return;
                }

                signatureError.hidden = true;
                input.value = canvas.toDataURL('image/png');
            });
        });
    </script>
@endsection
