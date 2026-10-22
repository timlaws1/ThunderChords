<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'ThunderChords') }}</title>
    <link rel="icon" href="/icon.svg">
    <meta name="theme-color" content="#f5f7fb">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=Inter:400,500,600,700,800|IBM+Plex+Mono:400,700" rel="stylesheet">
    <script src="/theme.js"></script>
    @vite(['resources/css/app.css'])
</head>
<body class="login">
    <div class="login-card">
        {{ $slot }}
    </div>
</body>
</html>
