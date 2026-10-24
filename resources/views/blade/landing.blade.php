@extends('layouts.public')

@section('title', 'Sign in — ' . ($branding->app_name ?? 'Community Hub'))
@section('description', 'Sign in to your Community Hub account to manage gate passes, visitors and community services.')

@section('content')
<div class="relative mx-auto flex min-h-[calc(100vh-12rem)] max-w-lg flex-col justify-center px-4 py-8 sm:py-12">

    <!-- Ambient Background Theme-Coordinated Glow -->
    <div class="pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[420px] w-[420px] rounded-full bg-gradient-to-tr from-primary/25 via-accent/20 to-primary/10 blur-[100px] dark:from-primary/30 dark:via-accent/20 dark:to-transparent"></div>

    <div class="relative z-10 overflow-hidden rounded-2xl border border-border/80 bg-card/90 p-6 shadow-2xl backdrop-blur-xl sm:p-9">

        <!-- Header -->
        <div class="mb-7 text-center">
            <!-- App / Community Logo -->
            <div class="mx-auto mb-4 flex justify-center">
                <div class="relative flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/15 via-background to-accent/15 p-2.5 shadow-md ring-1 ring-primary/25 backdrop-blur-md transition-all duration-300 hover:scale-105 hover:shadow-lg">
                    @if($branding?->logo_url)
                        <img src="{{ $branding->logo_url }}" alt="{{ $community->name ?? $branding->app_name ?? 'Community Hub' }} Logo" class="h-full w-full rounded-xl object-contain">
                    @else
                        <picture>
                            <source srcset="/community-hub-app-icon-128.webp" type="image/webp">
                            <img src="/community-hub-app-icon-128.png" alt="{{ $community->name ?? $branding->app_name ?? 'Community Hub' }} Logo" class="h-full w-full rounded-xl object-contain">
                        </picture>
                    @endif
                </div>
            </div>

            <div class="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/5 px-3 py-1 text-xs font-medium text-foreground/80 backdrop-blur-md mb-3">
                <span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>
                <span>{{ $community->name ?? $branding->theme_tokens['communityName'] ?? 'Community Portal Access' }}</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground">Welcome Back</h1>
            <p class="mt-1.5 text-xs sm:text-sm text-muted-foreground">Sign in to access your gated residence ecosystem</p>
        </div>

        @if ($errors->any())
            <div role="alert" class="mb-5 rounded-xl border border-destructive/40 bg-destructive/10 p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-destructive flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <p class="text-xs sm:text-sm font-semibold text-destructive">Authentication Error</p>
                </div>
                <ul class="mt-1.5 space-y-0.5 text-xs text-destructive/90 pl-6 list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('success'))
            <div role="status" class="mb-5 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-3.5 text-xs sm:text-sm text-emerald-700 dark:text-emerald-300 shadow-sm flex items-center gap-2">
                <svg class="h-4 w-4 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <!-- Form Element -->
        <form method="POST" action="{{ route('login') }}" id="liquid-login-form" class="relative space-y-4">
            @csrf

            <!-- Stage Container for Inputs -->
            <div id="liquid-stage" class="space-y-3 mx-auto w-full max-w-[380px]">
                <!-- Community Name Indicator above username -->
                <div class="flex items-center justify-between px-3 text-xs">
                    <span class="inline-flex items-center gap-2 font-bold text-primary tracking-wide uppercase text-[11px]">
                        @if($branding?->logo_url)
                            <img src="{{ $branding->logo_url }}" alt="" class="h-4 w-4 rounded object-contain flex-shrink-0">
                        @else
                            <picture class="flex-shrink-0">
                                <source srcset="/community-hub-app-icon-128.webp" type="image/webp">
                                <img src="/community-hub-app-icon-128.png" alt="" class="h-4 w-4 rounded object-contain flex-shrink-0">
                            </picture>
                        @endif
                        <span>{{ $community->name ?? $branding->theme_tokens['communityName'] ?? 'Community Hub' }}</span>
                    </span>
                    <span class="text-[11px] font-medium text-muted-foreground/80">Authorized Access</span>
                </div>

                <!-- TOP CAPSULE: Email input -->
                <div id="capsule-top" class="liquid-capsule relative z-10 w-full transition-all duration-200">
                    <div id="capsule-top-skin" class="liquid-chrome-surface relative flex h-14 w-full items-center justify-between rounded-full px-5 overflow-hidden transition-all duration-200 focus-within:ring-2 focus-within:ring-primary/50 focus-within:border-primary">
                        <!-- Soft Theme Shimmer -->
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/70 via-transparent to-stone-400/15 mix-blend-overlay"></div>
                        <div class="pointer-events-none absolute -left-1/2 top-0 h-[200%] w-[50%] -rotate-45 bg-gradient-to-r from-transparent via-primary/20 dark:via-primary/25 to-transparent chrome-shimmer"></div>

                        <!-- Email Field -->
                        <label for="email" class="sr-only">Email address</label>
                        <div id="email-field-content" class="relative z-10 flex w-full items-center gap-3">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.206" />
                                </svg>
                            </div>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                placeholder="name@community.org"
                                autocomplete="username"
                                spellcheck="false"
                                autocapitalize="none"
                                aria-label="Email address"
                                class="w-full bg-transparent text-sm font-medium text-stone-900 dark:text-stone-100 placeholder-stone-400 dark:placeholder-stone-500 outline-none"
                            >
                        </div>
                    </div>
                </div>

                <!-- BOTTOM CAPSULE: Password input -->
                <div id="capsule-bottom" class="liquid-capsule relative z-10 w-full transition-all duration-200">
                    <div id="capsule-bottom-skin" class="liquid-chrome-surface relative flex h-14 w-full items-center justify-between rounded-full px-5 overflow-hidden transition-all duration-200 focus-within:ring-2 focus-within:ring-primary/50 focus-within:border-primary">
                        <!-- Soft Theme Shimmer -->
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/70 via-transparent to-stone-400/15 mix-blend-overlay"></div>
                        <div class="pointer-events-none absolute -left-1/2 top-0 h-[200%] w-[50%] -rotate-45 bg-gradient-to-r from-transparent via-primary/20 dark:via-primary/25 to-transparent chrome-shimmer"></div>

                        <!-- Password Field -->
                        <label for="password" class="sr-only">Password</label>
                        <div id="password-field-content" class="relative z-10 flex w-full items-center gap-3">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                required
                                placeholder="••••••••"
                                autocomplete="current-password"
                                aria-label="Password"
                                class="w-full bg-transparent text-sm font-medium text-stone-900 dark:text-stone-100 placeholder-stone-400 dark:placeholder-stone-500 outline-none"
                            >
                            <button
                                type="button"
                                id="toggle-password-visibility"
                                class="flex h-7 w-7 items-center justify-center text-stone-400 hover:text-primary dark:text-stone-500 dark:hover:text-primary transition-colors"
                                aria-label="Show password"
                            >
                                <svg id="eye-icon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AUXILIARY CONTROLS (Always visible & accessible) -->
            <div id="liquid-controls" class="space-y-4 pt-1">
                <div class="flex items-center justify-between px-1 text-xs">
                    <label class="flex items-center gap-2 text-muted-foreground hover:text-foreground cursor-pointer select-none">
                        <input type="checkbox" name="remember" value="1" class="rounded border-input text-primary focus:ring-ring">
                        <span>Remember me</span>
                    </label>
                    <button type="button" data-toggle-reset aria-expanded="false" aria-controls="reset-password-drawer" class="text-xs font-semibold text-primary hover:underline hover:brightness-110 underline-offset-4">
                        Forgot Password?
                    </button>
                </div>

                <!-- Theme Preset-Driven Submission Button -->
                <button
                    type="submit"
                    id="liquid-submit-button"
                    class="group relative flex h-12 w-full items-center justify-center overflow-hidden rounded-full font-semibold text-sm tracking-wide text-primary-foreground shadow-md hover:shadow-lg hover:brightness-105 active:scale-[0.98] transition-all duration-200 bg-primary border border-primary/40"
                >
                    <!-- Ambient Sheen Overlay -->
                    <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/20 via-transparent to-black/15"></div>
                    <div class="pointer-events-none absolute -left-1/2 top-0 h-[200%] w-[50%] -rotate-45 bg-gradient-to-r from-transparent via-white/25 to-transparent group-hover:translate-x-[250%] transition-transform duration-700"></div>

                    <!-- Button Content -->
                    <span id="submit-text" class="relative z-10 flex items-center gap-2 text-primary-foreground font-semibold drop-shadow-sm">
                        <span>Access Community</span>
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </span>
                    <span id="submit-spinner" class="relative z-10 hidden items-center gap-2 text-primary-foreground font-semibold">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>Authenticating...</span>
                    </span>
                </button>
            </div>
        </form>

        <!-- Reset Password Drawer -->
        <div id="reset-password-drawer" data-reset-panel hidden aria-live="polite" class="mt-5 rounded-xl border border-border/70 bg-muted/30 p-4 text-xs sm:text-sm text-muted-foreground shadow-sm">
            <p>
                Password resets are securely handled by community administration. Contact your estate office or security dispatch, and they will issue you a verified single-use access code.
            </p>
        </div>

        {{-- Local and testing only, like users:set-passwords: the password below is
             every seeded account's, the System Admin's included. It used to be
             printed into this page's script in every environment. --}}
        @if (app()->environment('local', 'testing'))
            <!-- Demo Credentials Quick Fill Drawer -->
            <details id="credentials-drawer" data-demo-password="{{ config('auth.seed_password') }}" class="mt-6 rounded-xl border border-border/60 bg-muted/20 p-3.5 transition-all">
                <summary class="cursor-pointer text-xs sm:text-sm font-medium text-foreground flex items-center justify-between select-none">
                    <span class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                        <span>Quick-Fill Demo Credentials</span>
                    </span>
                    <span class="text-[11px] text-primary bg-primary/10 px-2 py-0.5 rounded-full font-semibold">1-Click</span>
                </summary>
                <p class="mt-2 text-[11px] text-muted-foreground">Click any role to autofill credentials instantly (default password: <code class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] font-semibold text-foreground">{{ config('auth.seed_password') }}</code>):</p>
                <div class="mt-2.5 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                    @foreach ([
                        ['Alexander Wright', 'System Admin',        'alexander.wright@communityhub.org'],
                        ['Elena Rostova',    'Admin',               'elena.rostova@communityhub.org'],
                        ['Marcus Vance',     'Homeowner',           'marcus.vance@residence.net'],
                        ['Sophia Taylor',    'Temporary Homeowner', 'sophia.taylor@residence.net'],
                        ['Security Dispatch','Security',            'dispatch@apexguard.com'],
                        ['Maria Garcia',     'Staff',               'maria.garcia@communitystaff.org'],
                    ] as [$name, $role, $accountEmail])
                        <button
                            type="button"
                            data-fill="{{ $accountEmail }}"
                            class="flex flex-col items-start rounded-lg border border-border/40 bg-card/60 px-2.5 py-1.5 text-left text-xs hover:border-primary/50 hover:bg-primary/5 transition-colors"
                        >
                            <span class="font-medium text-foreground truncate w-full">{{ $name }}</span>
                            <span class="text-[10px] text-muted-foreground">{{ $role }}</span>
                        </button>
                    @endforeach
                </div>
            </details>
        @endif

    </div>

    <!-- Security Footnote -->
    <div class="mt-6 flex flex-wrap items-center justify-center gap-4 text-center text-xs text-muted-foreground">
        <span class="flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            Encrypted Estate Network
        </span>
        <span>•</span>
        <a href="{{ route('privacy') }}" class="underline hover:text-foreground">Privacy Policy</a>
    </div>
</div>

<!-- Liquid Surface Styling & Physics Simulation Engine -->
<style>
    /* Warm Neutral Friendly Surface (Cashmere Pearl & Mineral Silk) */
    .liquid-chrome-surface {
        background: linear-gradient(135deg,
            #ffffff 0%,
            #faf7f2 20%,
            #f2ece3 40%,
            #e8ded2 60%,
            #efe8de 80%,
            #faf8f5 100%
        );
        box-shadow:
            0 10px 25px -4px rgba(120, 105, 88, 0.12),
            0 4px 10px -2px rgba(120, 105, 88, 0.08),
            inset 0 1.5px 2px rgba(255, 255, 255, 0.95),
            inset 0 -1.5px 2px rgba(185, 170, 150, 0.25);
        border: 1px solid rgba(220, 208, 192, 0.85);
    }

    .dark .liquid-chrome-surface {
        background: linear-gradient(135deg,
            #2e2926 0%,
            #38322e 25%,
            #433c37 50%,
            #332d29 75%,
            #26221f 100%
        );
        box-shadow:
            0 12px 28px -6px rgba(0, 0, 0, 0.5),
            0 4px 12px -2px rgba(0, 0, 0, 0.3),
            inset 0 1px 1.5px rgba(245, 235, 220, 0.25),
            inset 0 -1.5px 2px rgba(0, 0, 0, 0.6);
        border: 1px solid rgba(168, 150, 130, 0.3);
    }

    /* Active focused input capsule */
    .liquid-capsule:focus-within .liquid-chrome-surface {
        background: rgba(255, 255, 255, 0.98);
        border-color: hsl(var(--primary));
        box-shadow:
            0 8px 24px -4px hsl(var(--primary) / 0.18),
            0 0 0 3px hsl(var(--primary) / 0.2),
            inset 0 1.5px 2px rgba(255, 255, 255, 0.95);
    }

    .dark .liquid-capsule:focus-within .liquid-chrome-surface {
        background: rgba(33, 29, 26, 0.98);
        border-color: hsl(var(--primary));
        box-shadow:
            0 12px 28px -6px hsl(var(--primary) / 0.3),
            0 0 0 3px hsl(var(--primary) / 0.25),
            inset 0 1px 1.5px rgba(255, 255, 255, 0.15);
    }

    .chrome-shimmer {
        animation: fluidSweep 5s ease-in-out infinite;
    }

    @keyframes fluidSweep {
        0%, 15% { transform: translateX(-150%) rotate(-45deg); }
        45%, 100% { transform: translateX(350%) rotate(-45deg); }
    }

    @media (prefers-reduced-motion: reduce) {
        .chrome-shimmer {
            animation: none;
        }
    }
</style>

<script>
    (function () {
        var form          = document.getElementById('liquid-login-form');
        var emailInput    = document.getElementById('email');
        var passwordInput = document.getElementById('password');
        var submitButton  = document.getElementById('liquid-submit-button');
        var submitText    = document.getElementById('submit-text');
        var submitSpinner = document.getElementById('submit-spinner');
        var togglePassBtn = document.getElementById('toggle-password-visibility');

        // Toggle password visibility with screen-reader feedback
        if (togglePassBtn && passwordInput) {
            togglePassBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    togglePassBtn.setAttribute('aria-label', 'Hide password');
                    togglePassBtn.innerHTML = '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" /></svg>';
                } else {
                    passwordInput.type = 'password';
                    togglePassBtn.setAttribute('aria-label', 'Show password');
                    togglePassBtn.innerHTML = '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>';
                }
            });
        }

        // Quick-Fill Demo credentials
        document.querySelectorAll('[data-fill]').forEach(function (button) {
            button.addEventListener('click', function () {
                var fillEmail = button.dataset.fill;
                var defaultPassword = document.getElementById('credentials-drawer')?.dataset.demoPassword ?? '';
                if (emailInput && passwordInput) {
                    emailInput.value = fillEmail;
                    passwordInput.value = defaultPassword;
                    emailInput.focus();
                }
            });
        });

        // Reset Password panel toggle with accessible state tracking
        var toggleReset = document.querySelector('[data-toggle-reset]');
        var panelReset  = document.querySelector('[data-reset-panel]');
        if (toggleReset && panelReset) {
            toggleReset.addEventListener('click', function () {
                var willShow = panelReset.hidden;
                panelReset.hidden = !willShow;
                toggleReset.setAttribute('aria-expanded', String(willShow));
            });
        }

        // Form submission: Set splash trigger flag and display spinner
        if (form) {
            form.addEventListener('submit', function () {
                try {
                    sessionStorage.setItem('play_community_splash', 'true');
                } catch (e) {}

                if (submitButton) {
                    submitButton.disabled = true;
                    if (submitText) submitText.classList.add('hidden');
                    if (submitSpinner) {
                        submitSpinner.classList.remove('hidden');
                        submitSpinner.classList.add('flex');
                    }
                }
            });
        }
    })();
</script>
@endsection
