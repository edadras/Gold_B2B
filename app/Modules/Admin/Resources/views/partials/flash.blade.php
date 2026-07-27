@if (session('status'))
    <div class="flash">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="errors">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
