<div class="grid">
    <label>{{ __('masterdata.fields.contact_name') }}<input type="text" name="contact_name" value="{{ old('contact_name', $model->contact_name) }}"></label>
    <label>{{ __('masterdata.fields.contact_phone') }}<input type="text" name="contact_phone" value="{{ old('contact_phone', $model->contact_phone) }}"></label>
    <label>{{ __('masterdata.fields.contact_email') }}<input type="email" name="contact_email" value="{{ old('contact_email', $model->contact_email) }}"></label>
</div>
