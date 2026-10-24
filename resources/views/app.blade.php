<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="@if(($theme ?? null) === 'dark') dark @endif">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Community Hub') }}</title>

    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/community-hub-app-icon.png" type="image/png">
    <meta name="theme-color" content="#0F172A">

    {{--
        Applies the stored theme before first paint so a dark-mode user never
        sees a white flash. The original relied on a useEffect in
        src/hooks/use-dark-mode.ts, which ran after hydration.
    --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (stored === 'dark' || (stored !== 'light' && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    @routes
    @viteReactRefresh
    @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="min-h-screen bg-background font-sans antialiased">
    @inertia
</body>
</html>
