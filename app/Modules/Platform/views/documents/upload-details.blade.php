{{--
    CR #137 (audit ADMIN-10): a small "上传单据" <details> for a record page — the Job page (Platform) and the order page (Orders
    includes it with one line: @include('platform::documents.upload-details', ['documentNo' => $order->order_no])).
    Posts the shared documents.store with the number pre-filled and returns to the page; lists what is already attached to that
    number. Renders nothing for roles that may not upload (DocumentController::EDITOR_ROLES) — they see the Job page's document panel.
--}}
@if (auth()->user()->hasAnyRole(\App\Modules\Platform\Http\Controllers\DocumentController::EDITOR_ROLES))
    @php($target = app(\App\Modules\Platform\Services\DocumentNumberResolver::class)->resolve($documentNo))
    @php($attached = $target ? \App\Modules\Platform\Models\Document::query()->where('related_type', $target['related_type'])->where('related_id', $target['related_id'])->orderByDesc('id')->limit(10)->get() : collect())
    <details class="erp-upload"{{ $errors->has('document_no') || $errors->has('file') || $errors->has('type') || $errors->has('client_visible') ? ' open' : '' }}>
        <summary role="button" class="secondary outline">{{ __('platform.documents.upload_for', ['no' => $documentNo]) }} <small class="text-muted">{{ $attached->count() }}</small></summary>
        @if ($attached->isNotEmpty())
            <ul>
                @foreach ($attached as $d)
                    <li><a href="{{ route('platform.documents.download', $d) }}">{{ $d->original_name ?? basename((string) $d->storage_path) }}</a> · {{ __('platform.documents.types.'.$d->type) }} · <small class="text-muted">{{ $d->client_visible ? __('platform.documents.visible') : __('platform.documents.hidden') }} · {{ $d->created_at->format('d/m H:i') }}</small></li>
                @endforeach
            </ul>
        @endif
        <form method="post" action="{{ route('platform.documents.store') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="document_no" value="{{ $documentNo }}">
            <input type="hidden" name="back" value="1">
            <div class="grid">
                <label>{{ __('platform.documents.file') }}<input type="file" name="file" required></label>
                <label>{{ __('platform.documents.type') }}<select name="type">@foreach (\App\Support\Enums::DOCUMENT_TYPES as $t)<option value="{{ $t }}" @selected(old('type', $defaultType ?? 'packing_list') === $t)>{{ __('platform.documents.types.'.$t) }}</option>@endforeach</select></label>
                <label><input type="hidden" name="client_visible" value="0"><input type="checkbox" name="client_visible" value="1" @checked(old('client_visible'))> {{ __('platform.documents.visible') }}</label>
            </div>
            <button type="submit" class="secondary">{{ __('platform.documents.upload') }}</button>
        </form>
    </details>
@endif
