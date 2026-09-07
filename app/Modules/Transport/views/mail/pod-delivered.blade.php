<h1>{{ __('transport.mail.pod_title') }}</h1>
<p>{{ __('transport.mail.pod_intro', [
    'shipment' => $pod->shipment->shipment_no,
    'recipient' => $pod->recipient_name,
    'time' => $pod->delivered_at->format('Y-m-d H:i'),
]) }}</p>
<p>{{ __('transport.mail.pod_attached') }}</p>
