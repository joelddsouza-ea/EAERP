<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>Express Autocare</title>
    <meta name="description" content="Track your car's service, approve work, pay, book and keep your car's documents.">

    <!-- Installs to the phone's home screen as "Express Autocare" -->
    <link rel="manifest" href="/customer.webmanifest">
    <meta name="theme-color" content="#c4161c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Express Autocare">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/customer/main.ts'])
</head>
<body class="font-sans bg-gray-100">
    <div id="customer-app"></div>
</body>
</html>
