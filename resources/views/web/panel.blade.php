{{--
    The panel shell — doc §1.2.

    Deliberately thin. The server renders no member data into the document: the
    only payload is the bootstrap blob, and everything in it is derived from the
    authenticated user (see PanelBootstrap). Business data arrives over
    /api/v1, where the API's own tenancy and permission checks apply per request.

    The blob goes in a `<script type="application/json">` rather than an inline
    assignment. A JSON script block is not executed, so a display name
    containing `</script>` or a quote cannot break out of it — and `@json`
    escapes the closing-tag sequence on top of that.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — Gold B2B</title>
    @vite('resources/js/app.js')
</head>
<body>
    {{-- JSON_UNESCAPED_UNICODE: the payload is Persian, and \uXXXX escaping
         roughly triples it for no benefit inside a UTF-8 document. --}}
    <script id="panel-bootstrap" type="application/json">@json($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

    <div id="panel" data-screen="{{ $screen->value }}">
        {{-- Rendered before the bundle parses, so a slow connection shows the
             screen's name rather than a blank page. --}}
        <noscript>
            <p style="padding:16px">این پنل به JavaScript نیاز دارد.</p>
        </noscript>
    </div>
</body>
</html>
