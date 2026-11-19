{{--
    Consent screen. Publish with:
        php artisan vendor:publish --tag=openid-provider-views

    Variables: $client (Laravel\Passport\Client), $user, $scopes
    (Laravel\Passport\Scope[]), $authToken (string), $request.
    The form must post auth_token back to the approve (POST) or deny
    (DELETE) route together with the CSRF token.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Authorize :client', ['client' => $client->name]) }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6f6f4; color: #1c1c1c; margin: 0; }
        main { max-width: 26rem; margin: 10vh auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        ul { padding-left: 1.25rem; }
        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
        button { flex: 1; padding: .6rem 1rem; border-radius: 6px; font-size: 1rem; cursor: pointer; border: 1px solid #1c1c1c; }
        .approve { background: #1c1c1c; color: #fff; }
        .deny { background: #fff; color: #1c1c1c; }
        form { flex: 1; display: flex; }
    </style>
</head>
<body>
<main>
    <h1>{{ __(':client wants to sign you in', ['client' => $client->name]) }}</h1>

    <p>{{ __('Signed in as :name.', ['name' => $user->email ?? $user->name ?? $user->getAuthIdentifier()]) }}</p>

    @if (count($scopes) > 0)
        <p>{{ __('It will be able to see:') }}</p>
        <ul>
            @foreach ($scopes as $scope)
                <li>{{ $scope->description }}</li>
            @endforeach
        </ul>
    @endif

    <div class="actions">
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" id="deny" class="deny">{{ __('Cancel') }}</button>
        </form>

        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" id="approve" class="approve">{{ __('Allow') }}</button>
        </form>
    </div>
</main>
</body>
</html>
