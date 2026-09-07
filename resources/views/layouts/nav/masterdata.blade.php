{{-- Owner: seat C. Only the MasterData module edits this include (contracts/routes.md). --}}
@role('admin|customer_service|finance')
    <li><a href="{{ route('masterdata.index') }}">{{ __('masterdata.nav') }}</a></li>
@endrole
