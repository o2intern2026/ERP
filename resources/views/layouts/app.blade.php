<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    {{-- Frozen zone: Pico.css (classless, CDN) + one app.css ≤ 100 lines, no build step (ERP_PLAN §8.1). --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{-- Frozen zone: the ONE script behind x-date-field (resources/views/components/date-field.blade.php). The visible text reads dd/mm/yyyy in
         every browser, the hidden NAMED input carries the ISO value the server validates, the off-screen native input is only the calendar
         picker. Delegated on document, so rows cloned from a <template> by row-add JS work too; a prefill script sets the hidden input's
         value and dispatches change (bubbles) to refresh the text. Runs in <head> so it is armed before any page script. --}}
    <script>
    (function () {
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        // dd/mm/yyyy typed (d/m/yyyy, dd-mm-yyyy, dd.mm.yyyy and ddmmyyyy tolerated) → 'yyyy-mm-dd', or '' when incomplete or not a real date.
        function parse(text) {
            var t = String(text || '').trim(), m = /^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/.exec(t) || /^(\d{2})(\d{2})(\d{4})$/.exec(t);
            if (!m) { return ''; }
            var d = Number(m[1]), mo = Number(m[2]), y = Number(m[3]), probe = new Date(Date.UTC(y, mo - 1, d));
            return probe.getUTCFullYear() === y && probe.getUTCMonth() === mo - 1 && probe.getUTCDate() === d ? y + '-' + pad(mo) + '-' + pad(d) : '';
        }
        function format(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
        function parts(field) {
            return { text: field.querySelector('.date-text'), hidden: field.querySelector('input[type="hidden"]'), native: field.querySelector('.date-native'), time: field.querySelector('.date-time') };
        }
        // hidden = ISO date, plus 'T' + HH:MM when the field has a time box and it is filled (the datetime-local value the controller validates).
        function store(field, iso) {
            var p = parts(field), clock = p.time && p.time.value ? p.time.value.slice(0, 5) : '';
            p.hidden.value = iso ? (clock ? iso + 'T' + clock : iso) : '';
        }
        function fromText(field, normalise) {
            var p = parts(field), typed = p.text.value.trim(), iso = parse(typed);
            store(field, iso);
            var outside = iso !== '' && ((p.native.min && iso < p.native.min) || (p.native.max && iso > p.native.max));
            var bad = typed !== '' && (iso === '' || outside);
            if (bad) { p.text.setAttribute('aria-invalid', 'true'); } else { p.text.removeAttribute('aria-invalid'); }
            p.text.setCustomValidity(bad ? (field.dataset.invalid || 'dd/mm/yyyy') : '');
            if (normalise && iso !== '') { p.text.value = format(iso); }
        }
        function fromHidden(field) {
            var p = parts(field), v = p.hidden.value || '', clock = /T(\d{2}:\d{2})/.exec(v);
            p.text.value = format(v.slice(0, 10));
            if (p.time) { p.time.value = clock ? clock[1] : ''; }
            p.text.removeAttribute('aria-invalid');
            p.text.setCustomValidity('');
        }
        function fieldOf(target) { return target && target.closest ? target.closest('.date-field') : null; }
        document.addEventListener('input', function (event) {
            var field = fieldOf(event.target);
            if (!field) { return; }
            if (event.target.classList.contains('date-text')) { fromText(field, false); }
            else if (event.target.classList.contains('date-time')) { store(field, parts(field).hidden.value.slice(0, 10)); }
        });
        document.addEventListener('change', function (event) {
            var target = event.target, field = fieldOf(target);
            if (!field) { return; }
            if (target.classList.contains('date-text')) { fromText(field, true); }
            else if (target.classList.contains('date-native')) { if (target.value) { store(field, target.value); fromHidden(field); } }
            else if (target.classList.contains('date-time')) { store(field, parts(field).hidden.value.slice(0, 10)); }
            else if (target.type === 'hidden') { fromHidden(field); }
        });
        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('.date-field > .date-pick') : null;
            if (!button) { return; }
            var field = fieldOf(button), p = parts(field);
            if (p.text.disabled || p.text.readOnly) { return; }
            p.native.value = p.hidden.value.slice(0, 10);
            try { if (typeof p.native.showPicker === 'function') { p.native.showPicker(); } else { p.native.click(); } }
            catch (error) { try { p.native.focus(); p.native.click(); } catch (ignored) {} }
        });
        // A hidden value set before this ran, or a text restored by the browser (reload / back), is reconciled once; agreeing pairs are left as rendered.
        function reconcile() {
            Array.prototype.forEach.call(document.querySelectorAll('.date-field'), function (field) {
                var p = parts(field);
                if (!p.text || !p.hidden || !p.native) { return; }
                var typed = p.text.value.trim();
                if (typed === '' && p.hidden.value) { fromHidden(field); }
                else if (typed !== '' && parse(typed) !== p.hidden.value.slice(0, 10)) { fromText(field, true); }
            });
        }
        document.addEventListener('DOMContentLoaded', reconcile);
        window.addEventListener('pageshow', function (event) { if (event.persisted) { reconcile(); } });
    })();
    </script>
</head>
<body>
    @include('layouts.nav')
    <main class="container">
        @include('layouts.partials.health')
        @include('layouts.partials.flash')
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
