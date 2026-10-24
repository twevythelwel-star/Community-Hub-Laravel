{{--
    Error page for the public, Blade-rendered side of the app.

    The dashboard renders its errors through Inertia so the sidebar survives
    (see bootstrap/app.php). Public pages ship no React bundle, so they get
    this instead — same shell, same branding, and always a way onward.
--}}
@extends('layouts.public')

@section('title', $code.' — '.($branding->app_name ?? 'Community Hub'))

@section('content')
    <main id="content" class="mx-auto flex min-h-[70vh] w-full max-w-2xl items-center px-4 py-16">
        <div class="w-full rounded-2xl border border-border bg-card p-8 text-center shadow-sm sm:p-12">
            <p class="text-sm font-semibold tracking-[0.2em] text-muted-foreground">{{ $code }}</p>

            <h1 class="mt-3 font-headline text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                {{ $title }}
            </h1>

            <p class="mx-auto mt-3 max-w-md text-sm text-muted-foreground">
                {{ $description }}
            </p>

            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('landing') }}"
                   class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-95 sm:w-auto">
                    Go to sign in
                </a>

                <a href="{{ route('privacy') }}"
                   class="inline-flex w-full items-center justify-center rounded-lg border border-border bg-background px-5 py-2.5 text-sm font-medium text-foreground transition hover:bg-muted sm:w-auto">
                    Privacy policy
                </a>
            </div>
        </div>
    </main>
@endsection
