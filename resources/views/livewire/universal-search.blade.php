<div class="w-full max-w-4xl mx-auto space-y-4">
    <!-- Header & Driver Telemetry -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-900/90 backdrop-blur-md p-4 rounded-2xl border border-slate-800 shadow-xl">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </span>
                <h2 class="text-lg font-bold text-white tracking-tight">Universal Search Hub</h2>
                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 border border-slate-700 font-mono">Laravel Scout</span>
            </div>
            <p class="text-xs text-slate-400 mt-1">Cross-entity unified search across residents, gate passes, warnings, transactions, and visitors.</p>
        </div>

        <!-- Active Engine Driver Badge -->
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Driver: <strong class="uppercase font-mono">{{ $driver }}</strong></span>
            </span>
        </div>
    </div>

    <!-- Search Input Command Bar -->
    <div class="relative bg-slate-900 border border-slate-800 rounded-2xl p-2 shadow-2xl focus-within:border-indigo-500/60 focus-within:ring-2 focus-within:ring-indigo-500/20 transition-all">
        <div class="relative flex items-center">
            <div class="absolute left-4 text-slate-400 pointer-events-none">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>

            <input
                wire:model.live.debounce.250ms="query"
                type="text"
                placeholder="Search across all modules (e.g. John, GP-1002, lot 42, refund)..."
                class="w-full bg-transparent pl-12 pr-24 py-3.5 text-sm sm:text-base text-white placeholder-slate-500 focus:outline-none"
                autofocus
            />

            <!-- Clear / Key Shortcut helper -->
            <div class="absolute right-3 flex items-center gap-1.5">
                @if(!empty($query))
                    <button
                        wire:click="clearQuery"
                        type="button"
                        class="px-2 py-1 text-xs text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-md transition"
                        title="Clear search"
                    >
                        Clear
                    </button>
                @endif
                <kbd class="hidden sm:inline-block px-2 py-1 text-[11px] font-mono font-medium text-slate-400 bg-slate-800/80 rounded border border-slate-700">ESC</kbd>
            </div>
        </div>

        <!-- Filter Pill Chips -->
        <div class="flex items-center gap-1.5 pt-2 px-2 overflow-x-auto border-t border-slate-800/70 text-xs">
            <button
                wire:click="setCategory('all')"
                type="button"
                class="px-3 py-1 rounded-lg font-medium transition {{ $selectedCategory === 'all' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' }}"
            >
                All Entities
            </button>
            <button
                wire:click="setCategory('users')"
                type="button"
                class="px-3 py-1 rounded-lg font-medium transition {{ $selectedCategory === 'users' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' }}"
            >
                Residents ({{ count($searchData['results']['users'] ?? []) }})
            </button>
            <button
                wire:click="setCategory('gate_passes')"
                type="button"
                class="px-3 py-1 rounded-lg font-medium transition {{ $selectedCategory === 'gate_passes' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' }}"
            >
                Gate Passes ({{ count($searchData['results']['gate_passes'] ?? []) }})
            </button>
            <button
                wire:click="setCategory('warnings')"
                type="button"
                class="px-3 py-1 rounded-lg font-medium transition {{ $selectedCategory === 'warnings' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' }}"
            >
                Warnings ({{ count($searchData['results']['warnings'] ?? []) }})
            </button>
            <button
                wire:click="setCategory('transactions')"
                type="button"
                class="px-3 py-1 rounded-lg font-medium transition {{ $selectedCategory === 'transactions' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' }}"
            >
                Transactions ({{ count($searchData['results']['transactions'] ?? []) }})
            </button>
            <button
                wire:click="setCategory('visitors')"
                type="button"
                class="px-3 py-1 rounded-lg font-medium transition {{ $selectedCategory === 'visitors' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' }}"
            >
                Visitors ({{ count($searchData['results']['visitors'] ?? []) }})
            </button>
        </div>
    </div>

    <!-- Search Results View Area -->
    <div class="bg-slate-900/90 backdrop-blur-md rounded-2xl border border-slate-800 shadow-xl overflow-hidden min-h-[300px]">
        @if(empty($query))
            <!-- Empty Query State -->
            <div class="py-16 text-center px-4">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 mb-3">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-white">Start typing to search</h3>
                <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                    Search seamlessly across residents, gate credentials, community security warnings, ledger transactions, and visitor logs via Laravel Scout.
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <span class="text-xs px-2.5 py-1 rounded-lg bg-slate-800 text-slate-300 border border-slate-700/60">Supported engines: Database, Meilisearch, Algolia, Typesense</span>
                </div>
            </div>
        @elseif($searchData['total'] === 0)
            <!-- No Matches State -->
            <div class="py-16 text-center px-4">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-white">No results matched "<span class="text-indigo-400">{{ $query }}</span>"</h3>
                <p class="text-xs text-slate-400 mt-1">Try refining your search keyword or switching the category filter.</p>
            </div>
        @else
            <!-- Results List -->
            <div class="p-3 border-b border-slate-800/80 flex items-center justify-between text-xs text-slate-400 bg-slate-900/60">
                <span>Found <strong class="text-white">{{ $searchData['total'] }}</strong> matching records</span>
                <span class="font-mono text-[11px]">Indexed via Scout ({{ $driver }})</span>
            </div>

            <div class="divide-y divide-slate-800/60">
                @foreach($searchData['flattened'] as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="group block p-4 hover:bg-slate-800/40 transition-colors focus:outline-none focus:bg-slate-800/60"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <!-- Category Icon -->
                                <div class="mt-0.5 inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-800 text-slate-300 border border-slate-700/60 group-hover:border-indigo-500/40 group-hover:text-indigo-400 transition">
                                    @if($item['icon'] === 'user')
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    @elseif($item['icon'] === 'shield')
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    @elseif($item['icon'] === 'alert-triangle')
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    @elseif($item['icon'] === 'credit-card')
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                    @else
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                    @endif
                                </div>

                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-mono font-medium text-slate-400 uppercase tracking-wider">{{ $item['category'] }}</span>
                                    </div>
                                    <h4 class="text-sm font-semibold text-white group-hover:text-indigo-300 transition-colors mt-0.5">{{ $item['title'] }}</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $item['subtitle'] }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 text-[11px] font-medium rounded-full bg-slate-800 text-slate-300 border border-slate-700">
                                    {{ $item['badge'] }}
                                </span>
                                <svg class="w-4 h-4 text-slate-500 group-hover:text-white group-hover:translate-x-0.5 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
