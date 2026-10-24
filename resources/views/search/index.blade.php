@extends('layouts.livewire')

@section('content')
<div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6">
    <div class="text-center sm:text-left space-y-1">
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
            Universal Search
        </h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Powered by Laravel Scout with support for Database, Algolia, Meilisearch, and Typesense engines.
        </p>
    </div>

    <!-- Livewire Universal Search Component -->
    <livewire:universal-search :q="$initialQuery" />
</div>
@endsection
