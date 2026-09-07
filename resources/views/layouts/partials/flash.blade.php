@if (session('status'))
    <article class="flash" role="status">{{ session('status') }}</article>
@endif
@if ($errors->any())
    <article class="flash flash-error" role="alert">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </article>
@endif
