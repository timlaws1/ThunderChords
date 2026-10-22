<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', config('app.name', 'ThunderChords'))</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/icon.svg">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#12171f">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=Syne:600,700|DM+Sans:400,500,600|IBM+Plex+Mono:400,700" rel="stylesheet">
<script src="/theme.js"></script>
@vite(['resources/css/app.css', $pageScript ?? 'resources/js/pages/library.js'])
</head>
<body @if(!empty($loginBody)) class="login" @endif>
@yield('body')
</body>
</html>
