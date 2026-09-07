@extends('layouts.app')

@section('title', __('warehouse.scan.title'))

@section('content')
    <h1>{{ __('warehouse.scan.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.scan.hint') }}</small></p>
    <form method="get" action="{{ route('warehouse.scan.resolve') }}" class="grid">
        <input type="text" name="code" id="scan-code" class="scan" placeholder="{{ __('warehouse.scan.code') }}" autofocus autocomplete="off" required>
        <button type="submit">{{ __('warehouse.scan.go') }}</button>
        <button type="button" class="secondary" id="camera-btn">{{ __('warehouse.scan.camera') }}</button>
    </form>
    <video id="camera" playsinline hidden style="width:100%;max-width:480px;border-radius:.5rem"></video>
    <p id="camera-msg" class="text-muted" hidden>{{ __('warehouse.scan.camera_unsupported') }}</p>
@endsection

@push('scripts')
<script>
    // Phone camera scanning via the browser's native BarcodeDetector (no third-party library — ERP_PLAN §8.1 CDN rule).
    const btn = document.getElementById('camera-btn'), video = document.getElementById('camera'), msg = document.getElementById('camera-msg'), input = document.getElementById('scan-code');
    btn.addEventListener('click', async () => {
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { msg.hidden = false; return; }
        const detector = new BarcodeDetector({ formats: ['code_128', 'qr_code', 'ean_13', 'code_39'] });
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.srcObject = stream; video.hidden = false; await video.play();
        const tick = async () => {
            if (video.hidden) return;
            try {
                const codes = await detector.detect(video);
                if (codes.length) { input.value = codes[0].rawValue; stream.getTracks().forEach(t => t.stop()); video.hidden = true; input.form.submit(); return; }
            } catch (e) {}
            requestAnimationFrame(tick);
        };
        tick();
    });
</script>
@endpush
