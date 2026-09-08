{{-- Owner: seat X1. Only the Reports module edits this include (contracts/routes.md). --}}
@role('admin|finance')
    <li><a href="{{ route('reports.index') }}">{{ __('reports.nav') }}</a></li>
@endrole
@role('admin|finance|customer_service|dispatcher')
    <li><a href="{{ route('reports.client') }}">{{ __('reports.client.nav') }}</a></li>
@endrole
