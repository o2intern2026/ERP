@extends('layouts.app')

@section('title', __('warehouse.scan.title'))

@section('content')
    <h1>{{ __('warehouse.scan.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.scan.hint') }}</small></p>
    <form method="get" action="{{ route('warehouse.scan.resolve') }}" class="grid">
        <input type="text" name="code" id="scan-code" class="scan" placeholder="{{ __('warehouse.scan.code') }}" autofocus autocomplete="off" required>
        <button type="submit">{{ __('warehouse.scan.go') }}</button>
        <button type="button" class="secondary" id="camera-btn" data-open="{{ __('warehouse.scan.camera') }}" data-close="{{ __('warehouse.scan.camera_close') }}">{{ __('warehouse.scan.camera') }}</button>
    </form>
    {{-- CHANGE_REQUESTS #150: html5-qrcode draws into this box (iPhone Safari has no BarcodeDetector); the <video> is the native fallback. --}}
    <div id="camera-reader" hidden style="width:100%;max-width:480px;border-radius:.5rem;overflow:hidden"></div>
    <video id="camera" playsinline hidden style="width:100%;max-width:480px;border-radius:.5rem"></video>
    <p id="camera-msg" class="text-muted" hidden data-unsupported="{{ __('warehouse.scan.camera_unsupported') }}" data-https="{{ __('warehouse.scan.camera_https') }}" data-error="{{ __('warehouse.scan.camera_error') }}"></p>
@endsection

@push('scripts')
{{-- CHANGE_REQUESTS #150: html5-qrcode is one of the two CDN libraries the plan allows (ERP_PLAN §8.1, contracts/dependencies.md); pinned. --}}
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    // Phone camera scanning: html5-qrcode (works in iPhone Safari and Android Chrome over HTTPS); the browser's native BarcodeDetector
    // only when the library did not load. A camera needs a secure origin, so an http page gets the HTTPS hint instead of a silent failure.
    (() => {
        const btn = document.getElementById('camera-btn'), reader = document.getElementById('camera-reader'), video = document.getElementById('camera');
        const msg = document.getElementById('camera-msg'), input = document.getElementById('scan-code');
        const say = (key) => { msg.textContent = msg.dataset[key]; msg.hidden = false; };
        let scanner = null, stream = null;
        const stop = async () => {
            if (scanner) { try { await scanner.stop(); scanner.clear(); } catch (e) {} scanner = null; }
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
            reader.hidden = true; video.hidden = true; btn.textContent = btn.dataset.open;
        };
        const found = async (text) => { input.value = text; await stop(); input.form.submit(); };
        btn.addEventListener('click', async () => {
            if (scanner || stream) { await stop(); return; }
            msg.hidden = true;
            if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('https'); return; }
            if (window.Html5Qrcode) {
                reader.hidden = false;
                scanner = new Html5Qrcode('camera-reader', { formatsToSupport: [Html5QrcodeSupportedFormats.CODE_128, Html5QrcodeSupportedFormats.QR_CODE, Html5QrcodeSupportedFormats.EAN_13, Html5QrcodeSupportedFormats.CODE_39], verbose: false });
                try {
                    await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 160 } }, (text) => { found(text); }, () => {});
                    btn.textContent = btn.dataset.close;
                } catch (e) {
                    scanner = null; reader.hidden = true; say('error');
                }
                return;
            }
            if (!('BarcodeDetector' in window)) { say('unsupported'); return; }
            const detector = new BarcodeDetector({ formats: ['code_128', 'qr_code', 'ean_13', 'code_39'] });
            try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }); } catch (e) { say('error'); return; }
            video.srcObject = stream; video.hidden = false; btn.textContent = btn.dataset.close; await video.play();
            const tick = async () => {
                if (video.hidden) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes.length) { await found(codes[0].rawValue); return; }
                } catch (e) {}
                requestAnimationFrame(tick);
            };
            tick();
        });
    })();
</script>
@endpush
