<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ورود کارکنان — گلد B2B</title>
    <link rel="stylesheet" href="{{ route('admin.assets.css') }}">
</head>
<body>
<div class="login-shell">
    <div class="login-card">
        <h1>ورود کارکنان پلتفرم</h1>

        @include('admin::partials.flash')

        <form method="POST" action="{{ route('admin.login.submit') }}">
            @csrf
            <div class="field">
                <label for="mobile">موبایل</label>
                <input id="mobile" name="mobile" type="text" value="{{ old('mobile') }}" required autofocus>
            </div>
            <div class="field">
                <label for="password">گذرواژه</label>
                <input id="password" name="password" type="password" required>
            </div>
            <div class="field">
                <label for="code">کد دومرحله‌ای (در صورت فعال بودن)</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code">
            </div>
            <button type="submit" class="primary">ورود</button>
        </form>
    </div>
</div>
</body>
</html>
