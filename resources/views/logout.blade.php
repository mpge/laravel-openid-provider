{{--
    Asks the user to confirm signing out when the logout request could not
    be verified as coming from their own client.
    Variables: $client (Laravel\Passport\Client or null), $clientName,
    $logoutRequest (single-use challenge), $cancelUrl.
    The form must post logout_request and the CSRF token to
    route('oidc.logout.confirm').
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Sign out') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6f6f4; color: #1c1c1c; margin: 0; }
        main { max-width: 26rem; margin: 10vh auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        .actions { display: flex; gap: .75rem; align-items: center; margin-top: 1.5rem; }
        button { font: inherit; padding: .55rem 1.1rem; border-radius: 6px; border: 1px solid #1c1c1c; background: #1c1c1c; color: #fff; cursor: pointer; }
        a { color: #1c1c1c; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Sign out?') }}</h1>
    @if ($clientName)
        <p>{{ __(':client asked to sign you out.', ['client' => $clientName]) }}</p>
    @endif
    <p>{{ __('Do you want to sign out of your account?') }}</p>
    <form method="POST" action="{{ route('oidc.logout.confirm') }}">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="hidden" name="logout_request" value="{{ $logoutRequest }}">
        <div class="actions">
            <button type="submit">{{ __('Sign out') }}</button>
            <a href="{{ $cancelUrl }}">{{ __('Stay signed in') }}</a>
        </div>
    </form>
</main>
</body>
</html>
