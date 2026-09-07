<nav aria-label="{{ __('masterdata.title') }}">
    <ul>
        <li><a href="{{ route('masterdata.index') }}" @if ($active === 'clients') aria-current="page" @endif>{{ __('masterdata.clients.title') }}</a></li>
        <li><a href="{{ route('masterdata.suppliers.index') }}" @if ($active === 'suppliers') aria-current="page" @endif>{{ __('masterdata.suppliers.title') }}</a></li>
        <li><a href="{{ route('masterdata.carriers.index') }}" @if ($active === 'carriers') aria-current="page" @endif>{{ __('masterdata.carriers.title') }}</a></li>
    </ul>
</nav>
