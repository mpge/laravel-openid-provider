<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 22rem; margin: 10vh auto; }
        label, input, button { display: block; width: 100%; margin-bottom: .75rem; }
        input { padding: .5rem; }
        button { padding: .6rem; }
    </style>
</head>
<body>
<h1>Sign in to the demo provider</h1>
@if ($errors->any())
    <p role="alert">{{ $errors->first() }}</p>
@endif
<form method="post" action="{{ route('login') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <button id="submit" type="submit">Sign in</button>
</form>
</body>
</html>
