<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'ThunderChords') }}</title>
    <link rel="icon" href="/icon.svg">
    <meta name="theme-color" content="#12171f">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=Syne:600,700|DM+Sans:400,500,600|IBM+Plex+Mono:400,700" rel="stylesheet">
    <script src="/theme.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="bar">
        <a href="/" class="btn">Library</a>
        <h1 class="brand">Account</h1>
        <button type="button" class="theme-btn btn ml-auto" data-theme-toggle>Dark</button>
    </header>
    <main class="wrap">
        {{ $slot }}
    </main>
</body>
</html>
