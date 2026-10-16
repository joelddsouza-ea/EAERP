<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Express Autocare</title>

    <!-- Installs to the phone's home screen and opens full screen, like an app -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#c4161c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="EA Workshop">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="font-sans">
    <div id="app"></div>
</body>
</html>
