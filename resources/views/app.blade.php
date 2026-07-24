<!DOCTYPE html>
<html lang="en" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }} Bidding Engine</title>
    <meta name="description" content="Score, draft, and track Upwork proposals from one dashboard.">
    {{-- Drives the sign-in page's dev quick-login buttons. See config/skillleo.php. --}}
    <meta name="dev-quick-login" content="{{ config('skillleo.dev_quick_login') ? '1' : '0' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full flex flex-col bg-bg text-text-primary">
    <div id="app"></div>
</body>
</html>
