@extends('layouts.public')

@section('title', 'Digital Guest Pass — ' . $visitor->name)

@section('content')
<div class="mx-auto max-w-xl px-4 py-8 sm:py-12 space-y-6">

    {{-- Main Digital Guest Pass Container --}}
    <div class="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-2xl transition-all">
        
        {{-- Pass Header Banner --}}
        <div class="bg-gradient-to-br from-slate-900 via-primary/95 to-slate-900 p-6 text-white text-center relative overflow-hidden">
            <div class="absolute -right-8 -top-8 w-32 h-32 rounded-full bg-white/5 pointer-events-none blur-xl"></div>
            <div class="absolute -left-8 -bottom-8 w-32 h-32 rounded-full bg-primary/20 pointer-events-none blur-xl"></div>
            
            <div class="relative z-10 space-y-2">
                <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full text-xs font-semibold bg-white/15 backdrop-blur-md border border-white/20 shadow-xs">
                    <span class="h-2 w-2 rounded-full {{ $statusColor }} {{ $visitor->status === \App\Enums\VisitorStatus::CheckedIn ? 'animate-ping' : '' }}"></span>
                    <span class="tracking-wide uppercase text-[11px]">
                        @if ($visitor->status === \App\Enums\VisitorStatus::CheckedIn)
                            Checked In • On-Site
                        @elseif ($visitor->status === \App\Enums\VisitorStatus::CheckedOut)
                            Checked Out • Completed
                        @elseif ($isExpired)
                            Pass Expired
                        @else
                            Pre-Registered Guest
                        @endif
                    </span>
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white">{{ $visitor->name }}</h1>
                
                <div class="flex items-center justify-center gap-2 text-xs text-white/80 font-medium">
                    <span>{{ $community?->name ?? 'Community Hub' }}</span>
                    <span>•</span>
                    <span class="font-mono text-white/90">Permit #{{ $visitor->gatePass?->pass_id ?? sprintf('GP-VIS-%04d', $visitor->id) }}</span>
                </div>
            </div>
        </div>

        {{-- Pass Body --}}
        <div class="p-6 space-y-6">

            {{-- Real-Time On-Site Notice (if currently inside) --}}
            @if ($visitor->status === \App\Enums\VisitorStatus::CheckedIn)
                <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-left space-y-1.5 shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            Access Granted — Currently On-Site
                        </span>
                        <span class="text-[11px] font-mono text-muted-foreground">
                            Since {{ $visitor->checked_in_at ? $visitor->checked_in_at->format('g:i A') : 'Arrival' }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Welcome to {{ $community?->name ?? 'the community' }}. When departing the premises, present this code to the outbound gate scanner or fast-lane lane.
                    </p>
                </div>
            @endif

            {{-- 1. Live Dynamic QR Code Section --}}
            <div class="text-center space-y-3">
                <div class="mx-auto w-64 h-64 p-3 bg-white rounded-2xl shadow-md border border-border/60 flex flex-col items-center justify-center transition-transform hover:scale-[1.01]">
                    <img
                        id="gate-code"
                        src="{{ $code['qr'] ?? '' }}"
                        alt="Digital Gate Pass QR Code"
                        class="w-full h-full object-contain {{ $code['available'] ? '' : 'hidden' }}"
                        width="240"
                        height="240"
                    />
                    <div id="gate-code-message" class="text-center px-4 {{ $code['available'] ? 'hidden' : '' }}">
                        <div class="w-10 h-10 mx-auto mb-2 text-muted-foreground">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                        </div>
                        <p class="text-sm font-medium text-slate-700">{{ $code['message'] }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Live rotating security credential • Refreshes automatically</span>
                </div>

                {{-- Offline Gate PIN Fallback --}}
                <div class="inline-flex items-center gap-2.5 px-4 py-2 bg-muted/60 dark:bg-muted/30 rounded-xl border border-border/80 text-xs shadow-xs">
                    <span class="text-muted-foreground font-semibold uppercase text-[10px] tracking-wider">Gatehouse PIN:</span>
                    <span id="gate-code-pin" class="font-mono font-bold text-foreground text-base tracking-widest">{{ $code['pin'] ?? ($visitor->gatePass?->offline_pin ?? 'N/A') }}</span>
                </div>
            </div>

            @if ($visitor->gatePass && $visitor->status === \App\Enums\VisitorStatus::CheckedIn && ! $visitor->is_blocked && ! $visitor->expired_at?->isPast())
                <a href="{{ route('credential.safety.show', ['token' => $visitor->share_token]) }}"
                   class="flex items-center justify-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/5 px-4 py-3 text-sm font-semibold text-rose-700 hover:bg-rose-500/10 dark:text-rose-300"
                    rel="noreferrer">
                    <span aria-hidden="true">+</span>
                    Safety Assistance
                </a>
            @endif

            {{-- 2. Core Visit Details Grid (Property, Host, Date, Arrival Window) --}}
            <div class="rounded-xl border border-border/60 bg-muted/20 p-4 space-y-3 text-left">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    {{-- Destination Property --}}
                    <div class="space-y-0.5">
                        <span class="text-[11px] font-medium text-muted-foreground flex items-center gap-1 uppercase tracking-wider">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            Destination Property
                        </span>
                        <p class="font-bold text-foreground text-sm">{{ $hostProperty }}</p>
                    </div>

                    {{-- Host Resident --}}
                    <div class="space-y-0.5">
                        <span class="text-[11px] font-medium text-muted-foreground flex items-center gap-1 uppercase tracking-wider">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Resident Host
                        </span>
                        <p class="font-semibold text-foreground text-sm">
                            {{ $visitor->homeowner_name ?? $visitor->homeowner?->name ?? 'Community Resident' }}
                        </p>
                    </div>

                    {{-- Visit Date --}}
                    <div class="space-y-0.5">
                        <span class="text-[11px] font-medium text-muted-foreground flex items-center gap-1 uppercase tracking-wider">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                            Scheduled Date
                        </span>
                        <p class="font-semibold text-foreground text-sm">
                            {{ $visitor->expected_at ? $visitor->expected_at->format('l, M j, Y') : 'Scheduled Date' }}
                        </p>
                    </div>

                    {{-- Arrival Window --}}
                    <div class="space-y-0.5">
                        <span class="text-[11px] font-medium text-muted-foreground flex items-center gap-1 uppercase tracking-wider">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Arrival Window
                        </span>
                        <div class="flex items-center gap-1.5">
                            <p class="font-bold text-foreground text-sm text-primary">{{ $arrivalWindow }}</p>
                        </div>
                    </div>

                    {{-- Vehicle / Transport Details --}}
                    <div class="col-span-1 sm:col-span-2 space-y-0.5 pt-1 border-t border-border/40 flex items-center justify-between">
                        <div>
                            <span class="text-[11px] font-medium text-muted-foreground block uppercase tracking-wider">Registered Vehicle</span>
                            <span class="font-medium text-foreground text-xs">{{ $visitor->vehicle ?: 'Pedestrian / Ride-hail' }}</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-medium text-muted-foreground block text-right uppercase tracking-wider">Pass Type</span>
                            <span class="font-medium text-foreground text-xs text-right block">{{ ucfirst($visitor->type ?? 'One-time') }} Permit</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 3-5. Parking, rules and emergency details: only what the estate (or
                 this visitor's host) actually set. These sections used to fall back
                 to invented details, a dialable 555 number and "911" among them. --}}
            @if (filled($parkingInstructions))
                <div class="rounded-xl border border-border/70 bg-card p-4 text-left space-y-2 shadow-xs">
                    <div class="flex items-center gap-2 text-foreground font-semibold text-sm">
                        <div class="w-6 h-6 rounded-md bg-blue-500/10 text-blue-500 border border-blue-500/20 flex items-center justify-center font-bold text-xs">
                            P
                        </div>
                        <span>Parking Instructions</span>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed pl-8">
                        {{ $parkingInstructions }}
                    </p>
                </div>
            @endif

            @if (! empty($communityRules))
                <div class="rounded-xl border border-border/70 bg-card p-4 text-left space-y-2.5 shadow-xs">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-foreground font-semibold text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-500"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <span>Community Rules & Courtesy</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground">Estate Standards</span>
                    </div>

                    <div class="space-y-2 pt-1 text-xs">
                        @foreach ($communityRules as $ruleItem)
                            <div class="flex items-start gap-2.5 p-2 rounded-lg bg-muted/30 border border-border/30">
                                <div class="w-1.5 h-1.5 rounded-full bg-primary mt-1.5 shrink-0"></div>
                                <div class="space-y-0.5">
                                    @if (is_array($ruleItem) && filled($ruleItem['title'] ?? null))
                                        <span class="font-semibold text-foreground text-xs">{{ $ruleItem['title'] }}:</span>
                                    @endif
                                    <span class="text-muted-foreground text-xs">{{ is_array($ruleItem) ? ($ruleItem['rule'] ?? '') : $ruleItem }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @php($securityPhone = $emergencyInfo['security_phone'] ?? $emergencyInfo['gatehouse_phone'] ?? null)
            @if (filled($securityPhone) || filled($emergencyInfo['emergency_services'] ?? null) || filled($emergencyInfo['aed_location'] ?? null) || filled($emergencyInfo['assembly_point'] ?? null))
                <div class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-4 text-left space-y-2.5 shadow-xs">
                    <div class="flex items-center gap-2 text-rose-500 font-semibold text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                        <span>Emergency & Safety Assistance</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs pt-1">
                        @if (filled($securityPhone))
                            <div class="p-2.5 rounded-lg bg-background border border-rose-500/20 space-y-1">
                                <span class="text-[10px] text-muted-foreground uppercase font-semibold block">Gatehouse Security</span>
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $securityPhone) }}" class="font-bold text-foreground hover:text-primary text-xs">{{ $securityPhone }}</a>
                            </div>
                        @endif

                        @if (filled($emergencyInfo['emergency_services'] ?? null))
                            <div class="p-2.5 rounded-lg bg-background border border-rose-500/20 space-y-1">
                                <span class="text-[10px] text-muted-foreground uppercase font-semibold block">Emergency Services</span>
                                <span class="font-bold text-foreground text-xs block">{{ $emergencyInfo['emergency_services'] }}</span>
                            </div>
                        @endif

                        @if (filled($emergencyInfo['aed_location'] ?? null) || filled($emergencyInfo['assembly_point'] ?? null))
                            <div class="p-2.5 rounded-lg bg-background border border-rose-500/20 space-y-1 sm:col-span-2">
                                @if (filled($emergencyInfo['aed_location'] ?? null))
                                    <div class="flex items-center justify-between text-[10px] text-muted-foreground">
                                        <span>First Aid & AED Defibrillator:</span>
                                        <span class="font-semibold text-foreground">{{ $emergencyInfo['aed_location'] }}</span>
                                    </div>
                                @endif
                                @if (filled($emergencyInfo['assembly_point'] ?? null))
                                    <div class="flex items-center justify-between text-[10px] text-muted-foreground pt-0.5">
                                        <span>Assembly Point:</span>
                                        <span class="font-semibold text-foreground">{{ $emergencyInfo['assembly_point'] }}</span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- 6. Action Buttons Hub --}}
            <div class="space-y-2.5 pt-2">
                <a 
                    href="https://www.google.com/maps/search/?api=1&query={{ urlencode(($community?->name ?? 'Community Hub') . ' Main Gate') }}" 
                    target="_blank" 
                    rel="noopener noreferrer"
                    class="w-full flex items-center justify-center gap-2 rounded-xl bg-primary py-3 px-4 text-sm font-semibold text-primary-foreground shadow-md hover:opacity-95 transition-all"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                    Get GPS Gate Directions
                </a>

                <div class="grid grid-cols-2 gap-2">
                    <a 
                        href="{{ route('pdf.visitor-pass', $visitor->share_token) }}" 
                        class="flex items-center justify-center gap-1.5 rounded-xl border border-border bg-card py-2.5 px-3 text-xs font-medium text-foreground hover:bg-muted transition"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        Download PDF Pass
                    </a>

                    @if ($visitor->contact)
                        <a 
                            href="tel:{{ $visitor->contact }}" 
                            class="flex items-center justify-center gap-1.5 rounded-xl border border-border bg-card py-2.5 px-3 text-xs font-medium text-foreground hover:bg-muted transition"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            Contact Host
                        </a>
                    @else
                        <button 
                            type="button" 
                            onclick="navigator.clipboard.writeText(window.location.href); alert('Pass link copied to clipboard!');"
                            class="flex items-center justify-center gap-1.5 rounded-xl border border-border bg-card py-2.5 px-3 text-xs font-medium text-foreground hover:bg-muted transition"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                            Copy Pass Link
                        </button>
                    @endif
                </div>
            </div>

            {{-- Policy Footer --}}
            <div class="rounded-xl bg-muted/40 p-3 text-[11px] text-muted-foreground text-left border border-border/40">
                <strong>Gatehouse Security Notice:</strong> All guests must display this digital credential and present government photo ID upon arrival at the gatehouse. Security officers reserve the right of inspection.
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var img = document.getElementById('gate-code');
        var message = document.getElementById('gate-code-message');
        var pinEl = document.getElementById('gate-code-pin');
        var url = @json(route('guest-pass.code', $visitor->share_token));

        function refresh() {
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (code) {
                    if (!code) { return; }
                    if (code.available) {
                        img.src = code.qr;
                        img.classList.remove('hidden');
                        message.classList.add('hidden');
                        if (code.pin && pinEl) {
                            pinEl.textContent = code.pin;
                        }
                    } else {
                        img.classList.add('hidden');
                        message.textContent = code.message;
                        message.classList.remove('hidden');
                    }
                })
                .catch(function () {});
        }

        // Live automatic refresh interval
        setInterval(refresh, {{ max(5, intdiv((int) config('gatepass.window_seconds', 30), 3)) * 1000 }});
    })();
</script>
@endsection
