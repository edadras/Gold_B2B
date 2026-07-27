{{--
    The panel's only layout.

    One layout, a handful of partials and one stylesheet — that is the whole
    presentation layer. Kept deliberately small so that swapping it for Filament
    later means deleting this directory, not unpicking it.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'پنل مدیریت') — گلد B2B</title>
    <link rel="stylesheet" href="{{ route('admin.assets.css') }}">
</head>
<body>
<div class="shell">
    @include('admin::partials.sidebar')

    <div class="main">
        <div class="topbar">
            <h1>@yield('title', 'پنل مدیریت')</h1>
            <div class="who">
                {{ now()->format('Y-m-d H:i') }}
                @auth
                    ·
                    <form method="POST" action="{{ route('admin.logout') }}" style="display:inline">
                        @csrf
                        <button type="submit">خروج</button>
                    </form>
                @endauth
            </div>
        </div>

        @include('admin::partials.flash')

        @yield('content')
    </div>
</div>
</body>
</html>
