{{--
    Sign-in — §2.1.

    Carries no member data at all, not even an error message: the credential
    flow runs entirely against /api/v1/auth/*, so lockout counting and the TOTP
    challenge live in Identity rather than being duplicated here. The resulting
    access token is exchanged for a browser session at POST /app/session.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>ورود — Gold B2B</title>
    @vite('resources/js/app.js')
</head>
<body>
    <div id="login">
        <noscript>
            <p style="padding:16px">این پنل به JavaScript نیاز دارد.</p>
        </noscript>
    </div>
</body>
</html>
