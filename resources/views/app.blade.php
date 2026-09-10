<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php($organisation = app(\App\Support\Settings\Organisation::class)->forFrontend())

    <title inertia>{{ $organisation['short_name'] ?: config('app.name', 'Octa ERP') }}</title>

    {{-- The favicon follows the uploaded square mark, falling back to the shipped one. --}}
    @if ($organisation['icon_url'])
        <link rel="icon" href="{{ $organisation['icon_url'] }}">
    @else
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @endif

    {{--
        The shop floor installs to a tablet's home screen; the desk does not. Two different
        products in two different places — a manifest offered on every screen would have an
        accountant's browser prompting to install a machine terminal.

        The colours are the floor's own (`.floor-scope`, slate-950), so the splash screen and
        the address bar match the application behind them instead of flashing brand azure in a
        dark weaving shed.
    --}}
    @if (request()->is('floor', 'floor/*'))
        <link rel="manifest" href="/floor/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/floor-apple-touch.png">
        <meta name="theme-color" content="#020617">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Floor">
    @else
        <meta name="theme-color" content="#0071be">
    @endif

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full font-sans">
    @inertia
</body>
</html>
