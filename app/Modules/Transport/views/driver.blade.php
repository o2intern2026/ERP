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
                · {!! \App\Support\Ui\StatusBadge::render('transport.run_statuses.', $run->status) !!}
                {{-- CHANGE_REQUESTS #133: a run dated before today stays until it completes — say so, above today's runs. --}}
                @unless ($run->run_date->isToday())
                    · <span class="badge" data-tone="warn">{{ __('transport.driver.run_date', ['date' => $run->run_date->format('Y-m-d')]) }}</span>
                @endunless
            </header>

            @if ($run->stops->isEmpty())
                <p>{{ __('transport.driver.no_stops') }}</p>
            @endif

            @foreach ($run->stops as $stop)
                @php($receiver = data_get($stop->shipment->selectedQuote?->raw_response, '_quote_request.receiver', []))
                @php($sender = data_get($stop->shipment->selectedQuote?->raw_response, '_quote_request.sender', []))
                @php($deliveredPod = $stop->shipment->pods->firstWhere('delivered_at', '!=', null))
                {{-- CHANGE_REQUESTS #135 (audit TMS-09): the latest failed attempt of this stop; #135 (TMS-07): old() is scoped to the stop that was posted. --}}
                @php($failedPod = $stop->shipment->pods->whereNotNull('failure_reason')->sortByDesc('id')->first())
                @php($mine = (string) old('stop_id') === (string) $stop->id)
                <section>
                    <h2>{{ __('transport.driver.stop_number', ['sequence' => $stop->seq]) }} · {{ $stop->shipment->shipment_no }}
                        {!! \App\Support\Ui\StatusBadge::render('transport.stop_statuses.', $stop->status) !!}
                        @if ($stop->shipment->isCollection()) <span class="badge" data-tone="info">{{ __('transport.shipment_types.inbound_collection') }}</span>@endif</h2>
                    <dl>
                        <dt>{{ __('transport.driver.client') }}</dt>
                        <dd>{{ $stop->shipment->client->name }}</dd>
                        <dt>{{ __('transport.driver.eta') }}</dt>
                        <dd>{{ $stop->eta?->format('H:i') ?? __('transport.not_selected') }}</dd>
                        {{-- 我方上门提货 (CHANGE_REQUESTS #124): the driver collects at the pickup party and delivers to our warehouse. --}}
                        @if ($stop->shipment->isCollection())
                            <dt>{{ __('transport.driver.pickup_from') }}</dt>
                            <dd>{{ data_get($sender, 'name', __('transport.not_selected')) }}@if (data_get($sender, 'phone')) · {{ data_get($sender, 'phone') }}@endif<br>
                                {{ collect([data_get($sender, 'address'), data_get($sender, 'suburb'), data_get($sender, 'state'), data_get($sender, 'postcode')])->filter()->implode(', ') ?: __('transport.not_selected') }}</dd>
                        @endif
                        <dt>{{ $stop->shipment->isCollection() ? __('transport.driver.deliver_to_warehouse') : __('transport.driver.receiver') }}</dt>
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
                    @elseif ($stop->status === 'failed' || $failedPod)
                        {{-- CHANGE_REQUESTS #135 (audit TMS-09): a failed stop is closed — the driver sees what he reported instead of the two forms. --}}
                        <p role="status">
                            <strong><span class="badge" data-tone="danger">{{ __('transport.stop_statuses.failed') }}</span>
                            {{ __('transport.driver.failed_state', [
                                'reason' => $failedPod ? __('transport.driver.failure_reasons.'.$failedPod->failure_reason) : '—',
                                'time' => $failedPod?->created_at?->format('Y-m-d H:i') ?? '—',
                            ]) }}</strong>
                            @if ($failedPod?->failure_note)<br>{{ __('transport.driver.failed_note_label') }}: {{ $failedPod->failure_note }}@endif
                            @if ($failedPod && count($failedPod->photo_document_ids ?? []) > 0)<br>{{ __('transport.driver.failed_photos_label', ['count' => count($failedPod->photo_document_ids)]) }}@endif
                            <br><small class="text-muted">{{ __('transport.driver.failed_next') }}</small>
                        </p>
                    @else
                        <details @if ($mine && old('recipient_name') !== null) open @endif>
                            <summary>{{ __('transport.driver.capture_delivery') }}</summary>
                            <form method="post" enctype="multipart/form-data"
                                  action="{{ route('transport.driver.stops.deliver', $stop) }}"
                                  data-pod-form data-photo-form>
                                @csrf
                                <input type="hidden" name="stop_id" value="{{ $stop->id }}">
                                <label>
                                    {{ __('transport.driver.recipient_name') }}
                                    <input name="recipient_name" value="{{ $mine ? old('recipient_name') : '' }}" maxlength="150" required>
                                </label>
                                <label>{{ __('transport.driver.signature') }}</label>
                                <canvas width="640" height="240" data-signature-pad
                                        style="width:100%;max-width:640px;border:2px solid currentColor;touch-action:none"></canvas>
                                {{-- CHANGE_REQUESTS #135 (audit TMS-07): a rejected post (size, 419, 413) comes back with the signature redrawn from old(). --}}
                                <input type="hidden" name="signature_data" data-signature-data value="{{ $mine ? old('signature_data') : '' }}">
                                {{-- 2026-09-10 audit: `required` on a hidden input is inert, so the missing-signature message is rendered here instead. --}}
                                <p role="alert" data-signature-error hidden><strong>{{ __('transport.driver.signature_required') }}</strong></p>
                                <button type="button" class="secondary" data-signature-clear>{{ __('transport.driver.clear_signature') }}</button>
                                <label>
                                    {{ __('transport.driver.photos') }}
                                    {{-- CHANGE_REQUESTS #135 (audit TMS-07): no `capture` — the phone offers camera OR library; picks accumulate (n/5) and are
                                         downscaled to 1600 px JPEG before the post, so a 3–8 MB camera JPEG never hits the PHP / nginx body limits. --}}
                                    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required data-photos>
                                    <small data-photo-count>{{ __('transport.driver.photos_hint') }}</small>
                                </label>
                                <button type="submit">{{ __('transport.driver.submit_delivery') }}</button>
                            </form>
                        </details>

                        <details @if ($mine && old('failure_reason') !== null) open @endif>
                            <summary>{{ __('transport.driver.report_failure') }}</summary>
                            <form method="post" enctype="multipart/form-data" action="{{ route('transport.driver.stops.fail', $stop) }}" data-photo-form>
                                @csrf
                                <input type="hidden" name="stop_id" value="{{ $stop->id }}">
                                <label>
                                    {{ __('transport.driver.failure_reason') }}
                                    <select name="failure_reason" required>
                                        <option value="">{{ __('transport.driver.choose_failure_reason') }}</option>
                                        @foreach ($failureReasons as $reason)
                                            <option value="{{ $reason }}" @selected($mine && old('failure_reason') === $reason)>{{ __('transport.driver.failure_reasons.'.$reason) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                {{-- CHANGE_REQUESTS #135 (audit TMS-09): what happened (required for 其他) and a photo of the closed gate. --}}
                                <label>
                                    {{ __('transport.driver.failure_note') }}
                                    <textarea name="failure_note" maxlength="1000" rows="2">{{ $mine ? old('failure_note') : '' }}</textarea>
                                </label>
                                <label>
                                    {{ __('transport.driver.failure_photos') }}
                                    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple data-photos>
                                    <small data-photo-count>{{ __('transport.driver.photos_hint') }}</small>
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

            // CHANGE_REQUESTS #135 (audit TMS-07): the server sent the form back (size limit, expired session, 413) — redraw the signature it kept.
            if (input.value && input.value.startsWith('data:image/png;base64,')) {
                const saved = new Image();
                saved.onload = () => { context.drawImage(saved, 0, 0, canvas.width, canvas.height); signed = true; };
                saved.src = input.value;
            }

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

        // CHANGE_REQUESTS #135 (audit TMS-07): phone photos are 3–8 MB each; PHP's default post_max_size is 8 MB. Every chosen photo is
        // downscaled to a 1600 px JPEG (quality 0.8, typically 300–500 KB) right before the post — createImageBitmap → canvas → toBlob →
        // the input's files rebuilt through a DataTransfer. Vanilla JS, no library; a browser without these APIs posts the originals.
        (function () {
            const MAX_EDGE = 1600, QUALITY = 0.8, MAX_PHOTOS = 5;
            const countText = @json(__('transport.driver.photos_selected'));
            const canCompress = 'createImageBitmap' in window && 'DataTransfer' in window && typeof HTMLCanvasElement.prototype.toBlob === 'function';

            const shrink = async (file) => {
                if (!canCompress || !/^image\//.test(file.type)) return file;
                try {
                    const bitmap = await createImageBitmap(file);
                    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
                    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
                    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    if (bitmap.close) bitmap.close();
                    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
                    if (!blob || blob.size >= file.size) return file;
                    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
                } catch (error) {
                    return file;
                }
            };

            // Picks accumulate into the same list (two from the camera, then three from the library) up to 5, with an n/5 count.
            document.querySelectorAll('[data-photos]').forEach((input) => {
                const counter = input.parentElement.querySelector('[data-photo-count]');
                const kept = [];
                input.addEventListener('change', () => {
                    if (!('DataTransfer' in window)) return;
                    Array.from(input.files).forEach((file) => { if (kept.length < MAX_PHOTOS) kept.push(file); });
                    const transfer = new DataTransfer();
                    kept.forEach((file) => transfer.items.add(file));
                    input.files = transfer.files;
                    if (counter) counter.textContent = countText.replace(':count', String(kept.length));
                });
            });

            document.querySelectorAll('form[data-photo-form]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    if (event.defaultPrevented || form.dataset.compressed === '1') return;
                    const input = form.querySelector('[data-photos]');
                    if (!input || !input.files.length || !canCompress) return;
                    event.preventDefault();
                    const button = form.querySelector('button[type="submit"]');
                    if (button) button.disabled = true;
                    const transfer = new DataTransfer();
                    for (const file of Array.from(input.files)) transfer.items.add(await shrink(file));
                    input.files = transfer.files;
                    form.dataset.compressed = '1';
                    form.submit();
                });
            });
        })();
    </script>
@endsection
