{{-- Item 3b (tester feedback): tiny vanilla-JS dropdown under #deliver-to-address listing addresses the client used before
     (JSON from $suggestUrl: address book + past orders, max 8). Choosing one fills name / phone / address / suburb / state /
     postcode / address type. Nothing appears when the typed text matches no history. $clientField (staff form) is the selector
     of the client <select> whose value is sent as client_id. CSS is inline (CHANGE_REQUESTS #80). --}}
<script>
    (() => {
        const input = document.getElementById('deliver-to-address');
        const wrap = input?.closest('.suggest-wrap');
        if (!input || !wrap) return;
        @php($jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)
        const clientSelect = @json($clientField ?? null, $jsonFlags) ? document.querySelector(@json($clientField ?? null, $jsonFlags)) : null;
        const url = @json($suggestUrl, $jsonFlags);
        const sources = { book: @json(__('orders.addresses.suggest_sources.book')), history: @json(__('orders.addresses.suggest_sources.history')) };
        const targets = { name: 'deliver-to-name', phone: 'deliver-to-phone', address: 'deliver-to-address', suburb: 'deliver-to-suburb', state: 'deliver-to-state', postcode: 'deliver-to-postcode', address_type: 'deliver-to-address-type' };
        const list = document.createElement('ul');
        list.className = 'suggest-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        wrap.appendChild(list);
        let items = [], active = -1, timer = null, controller = null;

        const hide = () => { list.hidden = true; list.innerHTML = ''; items = []; active = -1; };
        const render = () => {
            list.innerHTML = '';
            items.forEach((item, i) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                li.dataset.index = String(i);
                li.textContent = item.address + ', ' + [item.suburb, item.state, item.postcode].filter(Boolean).join(' ');
                const small = document.createElement('small');
                small.textContent = [item.name, item.phone, sources[item.source]].filter(Boolean).join(' · ');
                li.appendChild(small);
                list.appendChild(li);
            });
            list.style.top = (input.offsetTop + input.offsetHeight) + 'px';
            list.hidden = items.length === 0;
        };
        const pick = item => {
            Object.entries(targets).forEach(([key, id]) => {
                const field = document.getElementById(id);
                if (field && item[key] !== null && item[key] !== undefined && item[key] !== '') field.value = item[key];
            });
            hide();
            input.dispatchEvent(new Event('change', { bubbles: true })); // the tailgate helper re-reads the address type
        };
        const search = () => {
            const q = input.value.trim();
            const params = new URLSearchParams({ q });
            if (clientSelect) {
                if (!clientSelect.value) { hide(); return; }
                params.set('client_id', clientSelect.value);
            }
            if (q.length < 2) { hide(); return; }
            controller?.abort();
            controller = new AbortController();
            fetch(url + '?' + params.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
                .then(response => response.ok ? response.json() : [])
                .then(data => { items = Array.isArray(data) ? data : []; active = -1; render(); })
                .catch(() => {});
        };

        input.setAttribute('autocomplete', 'off');
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 250); });
        input.addEventListener('keydown', event => {
            if (list.hidden) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(items.length - 1, active + 1); render(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(0, active - 1); render(); }
            else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); pick(items[active]); }
            else if (event.key === 'Escape') hide();
        });
        list.addEventListener('mousedown', event => {
            const li = event.target.closest('li');
            if (li) { event.preventDefault(); pick(items[Number(li.dataset.index)]); }
        });
        input.addEventListener('blur', () => setTimeout(hide, 150));
        clientSelect?.addEventListener('change', hide);
    })();
</script>
