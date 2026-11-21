<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="application-name" content="AUSHVAN">
    <meta name="apple-mobile-web-app-title" content="AUSHVAN">
    <meta name="description" content="AUSHVAN — Medical &amp; General Store Management Software. Every Store. Better Managed.">
    <meta name="author" content="TriMora Technologies">
    <meta name="theme-color" content="#171c33">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="/icon.svg">
    <link rel="apple-touch-icon" href="/icon.svg">
    <title>{{ config('app.name') }} — Medical &amp; General Store Management</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
