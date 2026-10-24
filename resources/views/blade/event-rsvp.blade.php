@extends('layouts.public')

@section('title', 'Guest Pre-Registration — ' . $invite->title)

@section('content')
<div class="mx-auto max-w-lg px-4 py-8">
    <div class="overflow-hidden rounded-2xl border border-border bg-card shadow-xl transition-all">
        {{-- Header Banner --}}
        <div class="bg-gradient-to-r from-primary/95 to-primary p-6 text-primary-foreground text-center relative">
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-white/20 backdrop-blur-sm mb-2">
                <span>Guest Pre-Clearance</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $invite->title }}</h1>
            <p class="text-xs opacity-90 mt-1">Hosted by {{ $invite->host?->display_name ?? 'Community Resident' }}</p>
        </div>

        <div class="p-6 space-y-6">
            {{-- Event Details --}}
            <div class="rounded-xl bg-muted/40 p-4 border border-border/60 text-sm space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">Community:</span>
                    <span class="font-medium text-foreground">{{ $community?->name ?? 'Community Hub' }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">Date & Arrival Time:</span>
                    <span class="font-semibold text-foreground">{{ $invite->expected_at->format('l, M j, Y — g:i A') }}</span>
                </div>
                @if($invite->notes)
                    <div class="pt-2 border-t border-border/50 text-xs text-muted-foreground">
                        <span class="font-medium text-foreground">Host Note:</span> {{ $invite->notes }}
                    </div>
                @endif
            </div>

            @if($isClosed)
                <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/30 text-destructive text-center space-y-1">
                    <p class="font-semibold text-sm">Invitation Closed</p>
                    <p class="text-xs">This RSVP invitation has expired or been closed by the host.</p>
                </div>
            @else
                {{-- Form --}}
                <form method="POST" action="{{ route('rsvp.submit', $invite->token) }}" class="space-y-4">
                    @csrf

                    @if($errors->has('general'))
                        <div class="p-3 rounded-lg bg-destructive/10 text-destructive text-xs font-medium">
                            {{ $errors->first('general') }}
                        </div>
                    @endif

                    <div>
                        <label for="name" class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                            Your Full Name <span class="text-destructive">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="{{ old('name') }}"
                            placeholder="e.g. Johnathan Smith"
                            required
                            class="w-full rounded-lg border border-input bg-background px-3.5 py-2.5 text-sm text-foreground shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-primary @error('name') border-destructive @enderror"
                        />
                        @error('name')
                            <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact" class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                            Mobile Phone (for Gate Pass SMS) <span class="text-destructive">*</span>
                        </label>
                        <input
                            type="text"
                            id="contact"
                            name="contact"
                            value="{{ old('contact') }}"
                            placeholder="e.g. +18765550199"
                            required
                            class="w-full rounded-lg border border-input bg-background px-3.5 py-2.5 text-sm text-foreground shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-primary @error('contact') border-destructive @enderror"
                        />
                        <p class="text-[11px] text-muted-foreground mt-1">We will send your dynamic QR gate pass directly to your phone.</p>
                        @error('contact')
                            <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="vehicle" class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                            Vehicle License Plate <span class="text-muted-foreground font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="vehicle"
                            name="vehicle"
                            value="{{ old('vehicle') }}"
                            placeholder="e.g. 8745-AB or Rideshare"
                            class="w-full rounded-lg border border-input bg-background px-3.5 py-2.5 text-sm text-foreground uppercase shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-primary"
                        />
                        <p class="text-[11px] text-muted-foreground mt-1">Allows security fast-lane clearance at the gatehouse.</p>
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-primary py-3 px-4 text-sm font-semibold text-primary-foreground shadow-sm hover:opacity-95 transition flex items-center justify-center gap-2"
                    >
                        <span>Pre-Clear & Get Gate Pass</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </button>
                </form>
            @endif

            <div class="rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground text-left">
                <strong>Gate Security Notice:</strong> Pre-clearing ensures rapid entry without gatehouse hold-up. Please have your digital pass ready on your phone upon arrival.
            </div>
        </div>
    </div>
</div>
@endsection
