@php
    $branding = $branding ?? \App\Models\BrandingSetting::current();
    $community = $community ?? \App\Models\Community::default();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', ($community?->name ? $community->name . ' — ' : '') . ($branding->app_name ?? 'Community Hub'))</title>
    <meta name="description" content="@yield('description', 'Secure gated-community management — digital gate passes, visitor clearance and community services.')">

    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/community-hub-app-icon.png" type="image/png">
    <meta name="theme-color" content="#0F172A">

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

    {{-- Public pages need only the stylesheet: no React bundle is shipped. --}}
    @vite(['resources/css/app.css'])
    @livewireStyles

    {{-- Only a chosen preset overrides app.css, whose dark mode has its own brighter primary. --}}
    @php
        $presetColors = $branding?->hasThemePreset() ? $branding->getThemePresetColors() : null;
    @endphp
    @if($presetColors || $branding?->primary_color || $branding?->accent_color || $branding?->background_color)
        <style>
            :root, .dark {
                @if($presetColors)
                    --primary: {{ $presetColors['primary'] }};
                    --primary-foreground: {{ $presetColors['primary_foreground'] }};
                    --accent: {{ $presetColors['accent'] }};
                    --accent-foreground: {{ $presetColors['accent_foreground'] }};
                    --ring: {{ $presetColors['primary'] }};
                @endif
                @if($branding?->primary_color) --brand-primary: {{ $branding->primary_color }}; @endif
                @if($branding?->accent_color) --brand-accent: {{ $branding->accent_color }}; @endif
                @if($branding?->background_color) --brand-background: {{ $branding->background_color }}; @endif
            }
        </style>
    @endif
</head>
<body class="min-h-screen bg-background font-sans antialiased text-foreground">

    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-4 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground">
        Skip to content
    </a>

    <header class="border-b border-border/60">
        <nav class="mx-auto flex max-w-7xl items-center justify-between p-4 sm:px-6 lg:px-8" aria-label="Global">
            <a href="{{ route('landing') }}" class="flex items-center gap-2.5 font-semibold tracking-tight">
                @if($branding?->logo_url)
                    <img src="{{ $branding->logo_url }}" alt="" class="h-8 w-8 rounded-lg object-contain shadow-sm" width="32" height="32">
                @else
                    <picture>
                        <source srcset="/community-hub-app-icon-128.webp" type="image/webp">
                        <img src="/community-hub-app-icon-128.png" alt="" class="h-8 w-8 rounded-lg object-contain shadow-sm" width="32" height="32">
                    </picture>
                @endif
                <div class="flex flex-col">
                    <span class="text-sm font-bold leading-tight">{{ $branding->app_name ?? 'Community Hub' }}</span>
                    @if($community?->name)
                        <span class="text-[11px] font-medium text-muted-foreground leading-tight">{{ $community->name }}</span>
                    @endif
                </div>
            </a>

            <div class="flex items-center gap-3 text-sm">
                <a href="{{ route('privacy') }}" class="text-muted-foreground hover:text-foreground">Privacy</a>
                @auth
                    <a href="{{ route('dashboard.index') }}" class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground">Sign in</a>
                @endauth
            </div>
        </nav>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="mt-16 border-t border-border/60">
        <div class="mx-auto max-w-6xl space-y-4 px-4 py-8 text-sm text-muted-foreground">
            <nav aria-label="Policies" class="flex flex-wrap gap-x-5 gap-y-2">
                <a href="{{ route('privacy') }}" class="hover:text-foreground">Privacy Policy</a>
                <a href="{{ route('cookies') }}" class="hover:text-foreground">Cookie Policy</a>
                <a href="{{ route('terms') }}" class="hover:text-foreground">Terms of Service</a>
                <a href="{{ route('refunds') }}" class="hover:text-foreground">Refund Policy</a>
            </nav>

            {{--
                Business identity. Required on a site that takes money, and the
                thing a resident needs in order to know who they are dealing
                with. Values come from config/legal.php.
            --}}
            <address class="not-italic leading-relaxed">
                {{ config('legal.entity.name') }}
                @if(config('legal.entity.registration_number'))
                    &middot; Reg. {{ config('legal.entity.registration_number') }}
                @endif
                <br>
                {{ config('legal.entity.registered_address') }}
                <br>
                <a href="mailto:{{ config('legal.contact.general_email') }}" class="hover:text-foreground">{{ config('legal.contact.general_email') }}</a>
                &middot; {{ config('legal.contact.phone') }}
            </address>

            <p>&copy; {{ date('Y') }} {{ $branding->app_name ?? 'Community Hub' }}. All rights reserved.</p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
