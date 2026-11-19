{{--
    Shown when an authorization request cannot be returned to the client,
    for example an unknown client_id or an unregistered redirect_uri.
    Variables: $error (OAuth error code), $description, optional $title.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Sign-in error') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6f6f4; color: #1c1c1c; margin: 0; }
        main { max-width: 26rem; margin: 10vh auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        code { font-size: .875rem; color: #555; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title ?? __('This sign-in request cannot be completed') }}</h1>
    <p>{{ $description }}</p>
    <p><code>{{ $error }}</code></p>
</main>
</body>
</html>
