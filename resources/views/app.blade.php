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

    <meta name="theme-color" content="#0071be">

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full font-sans">
    @inertia
</body>
</html>
