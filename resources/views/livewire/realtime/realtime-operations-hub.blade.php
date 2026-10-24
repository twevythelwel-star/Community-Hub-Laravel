<div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-8">
    {{-- Top Real-Time Infrastructure Banner --}}
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 border border-indigo-500/30 shadow-2xl p-6 sm:p-8">
        <div class="absolute -right-20 -top-20 w-72 h-72 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-20 -bottom-20 w-72 h-72 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-semibold uppercase tracking-wider mb-3">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    Laravel Reverb + Echo Active
                </div>
                <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Real-Time Operations Command</h1>
                <p class="mt-2 text-slate-300 text-sm sm:text-base max-w-2xl">
                    High-throughput WebSocket infrastructure supporting live dashboards, chat rooms, instant notifications, gate pass tracking, and security operations.
                </p>
            </div>

            {{-- Connection Details Capsule --}}
            <div class="p-4 rounded-2xl bg-slate-900/90 border border-slate-800 flex flex-wrap gap-4 text-xs font-mono">
                <div>
                    <div class="text-slate-500 uppercase text-[10px]">Broadcaster</div>
                    <div class="font-bold text-indigo-400 uppercase">{{ $echoConfig['broadcaster'] }}</div>
                </div>
                <div class="border-l border-slate-800 pl-4">
                    <div class="text-slate-500 uppercase text-[10px]">Host & Port</div>
                    <div class="font-bold text-slate-200">{{ $echoConfig['host'] }}:{{ $echoConfig['port'] }}</div>
                </div>
                <div class="border-l border-slate-800 pl-4">
                    <div class="text-slate-500 uppercase text-[10px]">Protocol</div>
                    <div class="font-bold text-cyan-400">Pusher v7 / WSS</div>
                </div>
                <div class="border-l border-slate-800 pl-4">
                    <div class="text-slate-500 uppercase text-[10px]">Client</div>
                    <div class="font-bold text-emerald-400">Laravel Echo</div>
                </div>
            </div>
        </div>

        {{-- Navigation Tabs --}}
        <div class="mt-8 flex flex-wrap gap-2 border-t border-slate-800/80 pt-4">
            <button wire:click="selectTab('telemetry')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'telemetry' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Live Dashboards & Telemetry
            </button>
            <button wire:click="selectTab('chat')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'chat' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Real-Time Chat & Rooms
            </button>
            <button wire:click="selectTab('tracking')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'tracking' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Gate Pass Status Tracking
            </button>
            <button wire:click="selectTab('operations')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'operations' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Security Command & Presence
            </button>
        </div>
    </div>

    {{-- Feedback Flash --}}
    @if ($feedbackMessage)
        <div class="p-4 rounded-2xl flex items-center justify-between border bg-emerald-500/10 border-emerald-500/30 text-emerald-300 text-sm font-semibold animate-fade-in">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <button wire:click="$set('feedbackMessage', null)" class="text-xs hover:underline opacity-80">Dismiss</button>
        </div>
    @endif

    {{-- TAB 1: Live Dashboards & Telemetry --}}
    @if ($activeTab === 'telemetry')
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Live Estate Telemetry & KPIs</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Instantly pushed via WebSocket event <code class="text-indigo-400">dashboard.telemetry-updated</code>.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="p-6 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between text-slate-500 text-xs font-semibold uppercase">
                        <span>Active Visitors</span>
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    </div>
                    <div class="mt-3 text-3xl font-black text-slate-900 dark:text-white">{{ $activeVisitorsCount }}</div>
                    <div class="mt-1 text-xs text-emerald-500 font-semibold">&uarr; Real-time in-transit count</div>
                </div>

                <div class="p-6 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between text-slate-500 text-xs font-semibold uppercase">
                        <span>Active Passes</span>
                        <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                    </div>
                    <div class="mt-3 text-3xl font-black text-slate-900 dark:text-white">{{ $activePassesCount }}</div>
                    <div class="mt-1 text-xs text-indigo-400 font-semibold">Active clearance tokens</div>
                </div>

                <div class="p-6 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between text-slate-500 text-xs font-semibold uppercase">
                        <span>Daily Scans</span>
                        <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
                    </div>
                    <div class="mt-3 text-3xl font-black text-slate-900 dark:text-white">{{ $recentScansCount }}</div>
                    <div class="mt-1 text-xs text-cyan-400 font-semibold">Processed gate checks</div>
                </div>

                <div class="p-6 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between text-slate-500 text-xs font-semibold uppercase">
                        <span>Avg Clearance</span>
                        <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                    </div>
                    <div class="mt-3 text-3xl font-black text-slate-900 dark:text-white">{{ $gateClearanceSeconds }}s</div>
                    <div class="mt-1 text-xs text-emerald-400 font-semibold">Optimal barrier throughput</div>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 2: Real-Time Chat & Rooms --}}
    @if ($activeTab === 'chat')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {{-- Room Selector Column --}}
            <div class="lg:col-span-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider">Presence Chat Rooms</h3>
                <div class="space-y-2">
                    <button wire:click="$set('selectedRoom', 'general')" class="w-full text-left p-3 rounded-xl border text-xs font-semibold transition-all {{ $selectedRoom === 'general' ? 'bg-indigo-50 dark:bg-indigo-950/60 border-indigo-500 text-indigo-400' : 'border-slate-200 dark:border-slate-800 text-slate-400 hover:bg-slate-800/40' }}">
                        <div class="font-bold text-slate-900 dark:text-white">#general-estate</div>
                        <div class="text-[11px] text-slate-500">General resident & staff chatter</div>
                    </button>
                    <button wire:click="$set('selectedRoom', 'security')" class="w-full text-left p-3 rounded-xl border text-xs font-semibold transition-all {{ $selectedRoom === 'security' ? 'bg-indigo-50 dark:bg-indigo-950/60 border-indigo-500 text-indigo-400' : 'border-slate-200 dark:border-slate-800 text-slate-400 hover:bg-slate-800/40' }}">
                        <div class="font-bold text-slate-900 dark:text-white">#gate-security</div>
                        <div class="text-[11px] text-slate-500">Main gate security coordinators</div>
                    </button>
                    <button wire:click="$set('selectedRoom', 'operations')" class="w-full text-left p-3 rounded-xl border text-xs font-semibold transition-all {{ $selectedRoom === 'operations' ? 'bg-indigo-50 dark:bg-indigo-950/60 border-indigo-500 text-indigo-400' : 'border-slate-200 dark:border-slate-800 text-slate-400 hover:bg-slate-800/40' }}">
                        <div class="font-bold text-slate-900 dark:text-white">#operations-room</div>
                        <div class="text-[11px] text-slate-500">Direct command and dispatch</div>
                    </button>
                </div>
            </div>

            {{-- Chat Stream Column --}}
            <div class="lg:col-span-8 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col justify-between min-h-[480px]">
                <div class="space-y-4 overflow-y-auto max-h-[380px] pr-2">
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                        <span class="text-sm font-bold text-slate-900 dark:text-white">Room: #{{ $selectedRoom }}</span>
                        <span class="text-xs text-emerald-400 font-mono flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            Presence Channel: chat.room.{{ $selectedRoom }}
                        </span>
                    </div>

                    @foreach ($chatMessages as $msg)
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/60 dark:border-slate-800 space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-900 dark:text-white">{{ $msg['user_name'] }}</span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">{{ $msg['user_role'] }}</span>
                                </div>
                                <span class="text-[10px] text-slate-400 font-mono">{{ $msg['sent_at'] }}</span>
                            </div>
                            <p class="text-xs text-slate-700 dark:text-slate-300">{{ $msg['message'] }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Composer Input --}}
                <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center gap-3">
                    <input
                        type="text"
                        wire:model="chatMessageInput"
                        wire:keydown.enter="sendChatMessage"
                        placeholder="Type real-time message and hit Enter..."
                        class="flex-1 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white px-4 py-2.5"
                    />
                    <button
                        wire:click="sendChatMessage"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-md transition-all"
                    >
                        Send
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 3: Gate Pass Status Tracking --}}
    @if ($activeTab === 'tracking')
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 sm:p-8 shadow-sm space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Gate Pass Access Tracking</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Streamed over private & public channels: <code class="text-indigo-400">gatehouse-stream</code> & <code class="text-indigo-400">passes.{id}</code>.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-400">
                            <th class="py-3 px-3 uppercase">Pass Code</th>
                            <th class="py-3 px-3 uppercase">Visitor Name</th>
                            <th class="py-3 px-3 uppercase">Gate Station</th>
                            <th class="py-3 px-3 uppercase">Status</th>
                            <th class="py-3 px-3 uppercase">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($recentPassEvents as $ev)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="py-3 px-3 font-mono font-bold text-indigo-400">{{ $ev['pass_code'] }}</td>
                                <td class="py-3 px-3 font-semibold text-slate-900 dark:text-white">{{ $ev['visitor'] }}</td>
                                <td class="py-3 px-3 text-slate-400">{{ $ev['gate'] }}</td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $ev['status'] === 'cleared' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-cyan-500/10 text-cyan-400' }}">
                                        {{ $ev['status'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-500">{{ $ev['time'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- TAB 4: Security Command & Presence --}}
    @if ($activeTab === 'operations')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {{-- Alert Dispatcher Form --}}
            <div class="lg:col-span-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 sm:p-8 shadow-sm space-y-4">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Dispatch Command Center Alert</h3>
                <p class="text-xs text-slate-400">Broadcasts to <code class="text-indigo-400">presence-operations-center</code>.</p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Severity</label>
                        <select wire:model="newAlertSeverity" class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                            <option value="info">Info / Operational</option>
                            <option value="warning">Warning / Sensor Breach</option>
                            <option value="critical">Critical / Emergency</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Headline</label>
                        <input type="text" wire:model="newAlertHeadline" class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Location Zone</label>
                        <input type="text" wire:model="newAlertLocation" class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>

                    <button wire:click="dispatchOperationsAlert" class="w-full py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs shadow-md transition-all">
                        Broadcast Security Alert
                    </button>
                </div>
            </div>

            {{-- Live Alerts Feed --}}
            <div class="lg:col-span-7 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 sm:p-8 shadow-sm space-y-4">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Active Operations Center Feed</h3>

                <div class="space-y-3">
                    @foreach ($activeAlerts as $alt)
                        <div class="p-4 rounded-xl border {{ $alt['severity'] === 'critical' ? 'bg-rose-500/10 border-rose-500/30 text-rose-300' : ($alt['severity'] === 'warning' ? 'bg-amber-500/10 border-amber-500/30 text-amber-300' : 'bg-blue-500/10 border-blue-500/30 text-blue-300') }} flex items-start justify-between gap-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold uppercase">{{ $alt['id'] }}</span>
                                    <span class="text-[10px] px-2 py-0.5 rounded font-black uppercase tracking-wider bg-black/20">{{ $alt['severity'] }}</span>
                                </div>
                                <div class="text-xs font-bold text-slate-900 dark:text-white">{{ $alt['headline'] }}</div>
                                <div class="text-[11px] text-slate-400">Zone: {{ $alt['location'] }}</div>
                            </div>
                            <span class="text-[10px] font-mono opacity-80">{{ $alt['time'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
