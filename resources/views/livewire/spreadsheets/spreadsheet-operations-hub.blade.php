<div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-8 animate-fade-in font-sans">
    {{-- Header Banner / Hero Capsule --}}
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-emerald-950 to-slate-900 p-6 sm:p-8 border border-slate-800 shadow-2xl text-white">
        <div class="absolute -right-16 -top-16 w-72 h-72 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute right-32 -bottom-20 w-80 h-80 bg-teal-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 mb-3">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    Laravel Excel &bull; Maatwebsite Engine
                </div>
                <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Excel &amp; CSV Data Processing Hub</h1>
                <p class="mt-2 text-slate-300 text-sm sm:text-base max-w-2xl">
                    High-throughput spreadsheet operations: XLSX/CSV imports with row validation, styled exports, queued background processing, and memory-safe chunking for massive datasets.
                </p>
            </div>

            {{-- Telemetry Capsule --}}
            <div class="p-4 rounded-2xl bg-slate-900/90 border border-slate-800 flex flex-wrap gap-4 text-xs font-mono">
                <div>
                    <div class="text-slate-500 uppercase text-[10px]">Active Engine</div>
                    <div class="font-bold text-emerald-400 uppercase">Maatwebsite 4.0</div>
                </div>
                <div class="border-l border-slate-800 pl-4">
                    <div class="text-slate-500 uppercase text-[10px]">Spreadsheet Core</div>
                    <div class="font-bold text-teal-300">PhpSpreadsheet 5.10</div>
                </div>
                <div class="border-l border-slate-800 pl-4">
                    <div class="text-slate-500 uppercase text-[10px]">Default Chunk Size</div>
                    <div class="font-bold text-slate-200">{{ $queueEngine['excel_chunk_size'] }} Rows</div>
                </div>
                <div class="border-l border-slate-800 pl-4">
                    <div class="text-slate-500 uppercase text-[10px]">Queue Backend</div>
                    <div class="font-bold text-cyan-400 uppercase">{{ $queueEngine['default_driver'] }}</div>
                </div>
            </div>
        </div>

        {{-- Navigation Tabs --}}
        <div class="mt-8 flex flex-wrap gap-2 border-t border-slate-800/80 pt-4">
            <button wire:click="selectTab('exporter')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'exporter' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Data Exporter (XLSX &amp; CSV)
            </button>
            <button wire:click="selectTab('importer')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'importer' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Spreadsheet Importer &amp; Validation
            </button>
            <button wire:click="selectTab('large_datasets')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'large_datasets' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Queued Exports &amp; Large Datasets
            </button>
            <button wire:click="selectTab('specifications')" class="px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $activeTab === 'specifications' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                Schema Mapping &amp; Standards
            </button>
        </div>
    </div>

    {{-- Feedback Flash --}}
    @if ($feedbackMessage)
        <div class="p-4 rounded-2xl flex items-center justify-between border bg-emerald-500/10 border-emerald-500/30 text-emerald-300 text-sm font-semibold animate-fade-in">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <button wire:click="$set('feedbackMessage', null)" class="text-xs text-slate-400 hover:text-white font-mono uppercase">Dismiss</button>
        </div>
    @endif

    {{-- TAB 1: Data Exporter --}}
    @if ($activeTab === 'exporter')
        <div class="space-y-6">
            {{-- Dataset Selector Cards --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach ($exportBlueprints as $key => $bp)
                    <div wire:click="selectBlueprint('{{ $key }}')" class="cursor-pointer rounded-2xl border p-5 flex flex-col justify-between transition-all duration-200 hover:-translate-y-1 hover:shadow-xl {{ $selectedBlueprint === $key ? 'bg-emerald-950/40 border-emerald-500 shadow-emerald-950/50' : 'bg-slate-900/60 border-slate-800 hover:border-slate-700' }}">
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-3">
                                <span class="px-2.5 py-0.5 rounded-md text-[10px] font-mono font-bold uppercase tracking-wider {{ $selectedBlueprint === $key ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400' }}">
                                    {{ $bp['count'] }} Records
                                </span>
                                <span class="text-[10px] text-slate-500 font-mono">
                                    XLSX &bull; CSV
                                </span>
                            </div>

                            <h3 class="text-base font-bold text-white {{ $selectedBlueprint === $key ? 'text-emerald-400' : '' }}">
                                {{ $bp['name'] }}
                            </h3>
                            <p class="mt-1 text-xs text-slate-400 line-clamp-2">
                                {{ $bp['description'] }}
                            </p>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-400">
                            <span>Chunk Size:</span>
                            <span class="font-mono text-emerald-400">{{ $bp['chunk_size'] }} rows</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Export Configuration Console --}}
            <div class="bg-slate-900/60 p-6 rounded-3xl border border-slate-800 space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Export Dispatcher: {{ $selectedMeta['name'] ?? 'Dataset' }}</h2>
                        <p class="text-xs text-slate-400 mt-1">Configure format and output delivery channel (Direct Stream vs Queued Background Export).</p>
                    </div>

                    {{-- Format Toggle Pill --}}
                    <div class="inline-flex p-1 bg-slate-950 rounded-xl border border-slate-800">
                        <button wire:click="$set('selectedFormat', 'xlsx')" class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all {{ $selectedFormat === 'xlsx' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white' }}">
                            Excel (.XLSX)
                        </button>
                        <button wire:click="$set('selectedFormat', 'csv')" class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all {{ $selectedFormat === 'csv' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white' }}">
                            Standard CSV (.CSV)
                        </button>
                    </div>
                </div>

                {{-- Action Controls --}}
                <div class="flex flex-col sm:flex-row items-center gap-4">
                    <button wire:click="downloadExport" wire:loading.attr="disabled" class="w-full sm:w-auto flex-1 py-3 px-6 rounded-xl font-bold bg-emerald-600 hover:bg-emerald-500 text-white transition-all shadow-lg flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span>Download {{ strtoupper($selectedFormat) }} Now (Synchronous)</span>
                    </button>

                    <button wire:click="queueExportJob" wire:loading.attr="disabled" class="w-full sm:w-auto flex-1 py-3 px-6 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white border border-slate-700 transition-all flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        <span>Enqueue Asynchronous Job (Horizon / Redis)</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 2: Spreadsheet Importer --}}
    @if ($activeTab === 'importer')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 bg-slate-900/60 p-6 rounded-3xl border border-slate-800 space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-white">Resident Roster Ingestion</h2>
                    <p class="text-xs text-slate-400 mt-1">Upload CSV or XLSX spreadsheets to batch insert or synchronize resident profiles.</p>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-2">Select Spreadsheet (.CSV, .XLSX)</label>
                        <input type="file" wire:model="importFile" class="w-full text-xs text-slate-300 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-600 file:text-white hover:file:bg-emerald-500 cursor-pointer bg-slate-950 p-2 rounded-2xl border border-slate-800">
                        @error('importFile')
                            <p class="text-rose-400 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <button wire:click="processImport" wire:loading.attr="disabled" class="w-full py-3 rounded-xl font-bold bg-emerald-600 hover:bg-emerald-500 text-white transition-all shadow-lg flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Validate &amp; Ingest File
                    </button>

                    <div class="pt-4 border-t border-slate-800">
                        <button wire:click="downloadSampleCsv" class="w-full py-2.5 px-4 rounded-xl text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-all flex items-center justify-center gap-2">
                            <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Download Sample Import Template (.CSV)
                        </button>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 bg-slate-900/60 p-6 rounded-3xl border border-slate-800 space-y-6">
                <div>
                    <h3 class="text-base font-bold text-white">Import Telemetry &amp; Validation Status</h3>
                    <p class="text-xs text-slate-400 mt-1">Real-time row-by-row parsing telemetry, batch commits, and error containment.</p>
                </div>

                @if ($importSummary)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                            <div class="text-[10px] font-mono text-emerald-400 uppercase">Rows Imported</div>
                            <div class="text-2xl font-black text-white mt-1">{{ $importSummary['imported_count'] }}</div>
                        </div>
                        <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30">
                            <div class="text-[10px] font-mono text-amber-400 uppercase">Validation Failures</div>
                            <div class="text-2xl font-black text-white mt-1">{{ $importSummary['failures_count'] }}</div>
                        </div>
                        <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                            <div class="text-[10px] font-mono text-rose-400 uppercase">Fatal Errors</div>
                            <div class="text-2xl font-black text-white mt-1">{{ $importSummary['errors_count'] }}</div>
                        </div>
                    </div>

                    @if (!empty($importSummary['failures']))
                        <div class="mt-4 p-4 rounded-2xl bg-slate-950 border border-slate-800">
                            <div class="text-xs font-bold text-rose-400 mb-2">Failure Details</div>
                            <div class="space-y-2 max-h-48 overflow-y-auto text-xs font-mono">
                                @foreach ($importSummary['failures'] as $failure)
                                    <div class="p-2 rounded bg-slate-900 border border-slate-800 text-slate-300">
                                        Row {{ $failure['row'] }}: Attribute [{{ $failure['attribute'] }}] - {{ implode(', ', $failure['errors']) }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <div class="p-8 text-center bg-slate-950/60 rounded-2xl border border-slate-800/80 text-slate-500 text-xs">
                        No active import executed in this session. Select a file on the left to begin validation.
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- TAB 3: Queued Exports & Large Datasets --}}
    @if ($activeTab === 'large_datasets')
        <div class="space-y-6">
            <div class="bg-slate-900/60 p-6 rounded-3xl border border-slate-800 space-y-6">
                <div>
                    <h2 class="text-xl font-bold text-white">Large Dataset Stream &amp; Queue Architecture</h2>
                    <p class="text-xs text-slate-400 mt-1">
                        How Maatwebsite Excel handles 100,000+ rows through cursor queries, chunk reading, and Laravel Horizon background workers.
                    </p>
                </div>

                {{-- Architecture Diagram / Comparison --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="p-5 rounded-2xl bg-rose-500/5 border border-rose-500/20 space-y-3">
                        <div class="flex items-center gap-2 text-rose-400 font-bold text-sm">
                            <span class="w-2 h-2 rounded-full bg-rose-400"></span>
                            Naive In-Memory Approach (Crashes on 5k+ Rows)
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Loads all Eloquent models into a collection: <code class="text-rose-300 font-mono">User::all()</code>. Incurs massive RAM overhead (&gt;512MB), triggers PHP memory exhaust errors, and locks HTTP response workers.
                        </p>
                    </div>

                    <div class="p-5 rounded-2xl bg-emerald-500/5 border border-emerald-500/20 space-y-3">
                        <div class="flex items-center gap-2 text-emerald-400 font-bold text-sm">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            Chunked Query &amp; Queued Export (Community Hub Architecture)
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Uses <code class="text-emerald-300 font-mono">FromQuery</code> with <code class="text-emerald-300 font-mono">chunk_size=1000</code> and <code class="text-emerald-300 font-mono">ShouldQueue</code>. RAM stays constant below 35MB regardless of dataset size; processed cleanly on Horizon workers.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 4: Schema Mapping & Standards --}}
    @if ($activeTab === 'specifications')
        <div class="bg-slate-900/60 p-6 rounded-3xl border border-slate-800 space-y-6">
            <div>
                <h2 class="text-lg font-bold text-white">Supported Schemas &amp; Data Dictionaries</h2>
                <p class="text-xs text-slate-400 mt-1">
                    Standardized column formats, validation rules, and export layout definitions.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-slate-800 text-slate-400 uppercase text-[10px] tracking-wider font-mono">
                            <th class="py-3 px-4">Dataset Name</th>
                            <th class="py-3 px-4">Export Formats</th>
                            <th class="py-3 px-4">Chunk Strategy</th>
                            <th class="py-3 px-4">Header Styling</th>
                            <th class="py-3 px-4">Queued Capable</th>
                            <th class="py-3 px-4">Auto-Sizing</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-300">
                        @foreach ($exportBlueprints as $key => $bp)
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-4 font-bold text-white">
                                    {{ $bp['name'] }}
                                    <div class="text-[10px] text-slate-500 font-mono font-normal">{{ $key }}</div>
                                </td>
                                <td class="py-3 px-4 font-mono uppercase text-emerald-400">XLSX &bull; CSV</td>
                                <td class="py-3 px-4 font-mono text-slate-300">{{ $bp['chunk_size'] }} rows/chunk</td>
                                <td class="py-3 px-4 text-slate-400">Solid Slate/Teal Header with Bold Font</td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-mono bg-emerald-500/10 text-emerald-300 border border-emerald-500/30">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                        ShouldQueue Enabled
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-emerald-400 font-medium">Auto-fitted</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
