@extends('layouts.public')

@section('title', 'Pre-Clearance Confirmed — ' . $visitor->name)

@section('content')
<div class="mx-auto max-w-lg px-4 py-8">
    <div class="overflow-hidden rounded-2xl border border-border bg-card shadow-xl transition-all text-center">
        {{-- Success Banner --}}
        <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 p-8 text-white relative">
            <div class="mx-auto h-16 w-16 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight">You're Pre-Cleared!</h1>
            <p class="text-xs opacity-90 mt-1">Gate access clearance approved for {{ $community?->name ?? 'Community Hub' }}</p>
        </div>

        <div class="p-6 space-y-6 text-left">
            <div class="rounded-xl bg-muted/40 p-4 border border-border/60 text-sm space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">Attendee:</span>
                    <span class="font-semibold text-foreground">{{ $visitor->name }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">Event:</span>
                    <span class="font-medium text-foreground">{{ $invite->title }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">Host:</span>
                    <span class="font-medium text-foreground">{{ $invite->host?->display_name ?? 'Resident Host' }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">Arrival:</span>
                    <span class="font-medium text-foreground">{{ $visitor->expected_at ? $visitor->expected_at->format('M j, Y — g:i A') : 'Scheduled Time' }}</span>
                </div>
                @if($visitor->vehicle)
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">Vehicle:</span>
                        <span class="font-mono font-medium text-foreground">{{ $visitor->vehicle }}</span>
                    </div>
                @endif
            </div>

            @if($smsSent)
                <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-800 dark:text-emerald-300 text-xs flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span>We sent your live pass link via SMS to <strong>{{ $visitor->contact }}</strong>.</span>
                </div>
            @endif

            <div class="space-y-3 pt-2">
                <a
                    href="{{ $visitor->guestPassUrl() }}"
                    class="w-full flex items-center justify-center gap-2 rounded-lg bg-primary py-3 px-4 text-sm font-semibold text-primary-foreground shadow-sm hover:opacity-95 transition"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h.01"/><path d="M17 7h.01"/><path d="M7 17h.01"/><path d="M17 17h.01"/></svg>
                    <span>Open Live Gate Pass & QR Code</span>
                </a>

                <a
                    href="https://www.google.com/maps/search/?api=1&query={{ urlencode(($community?->name ?? 'Community Hub') . ' Main Gate') }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="w-full flex items-center justify-center gap-2 rounded-lg border border-border bg-background py-2.5 px-4 text-sm font-medium text-foreground hover:bg-muted transition"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                    <span>Get GPS Directions to Gate</span>
                </a>
            </div>

            <p class="text-xs text-muted-foreground text-center">
                Keep your phone handy when approaching the security barrier. Show your live pass to the officer or scanner.
            </p>
        </div>
    </div>
</div>
@endsection
