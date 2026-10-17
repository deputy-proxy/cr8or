<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Competitor details">
            <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div><dt class="text-sm font-medium text-gray-500">Enterprise</dt><dd>{{ $competitor->enterprise->name }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Website</dt><dd>@if ($competitor->website)<a class="text-primary-600 underline" href="{{ $competitor->website }}" target="_blank" rel="noopener noreferrer">{{ $competitor->website }}</a>@else — @endif</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Version</dt><dd>{{ $competitor->version }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Status</dt><dd>{{ ucfirst($competitor->status) }}{{ $competitor->is_current ? ' · Current' : ' · Historical' }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Effective from</dt><dd>{{ $competitor->effective_from?->toDateTimeString() ?? '—' }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Effective to</dt><dd>{{ $competitor->effective_to?->toDateTimeString() ?? '—' }}</dd></div>
                <div class="md:col-span-2"><dt class="text-sm font-medium text-gray-500">Positioning</dt><dd class="whitespace-pre-wrap">{{ $competitor->positioning ?: '—' }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Strengths</dt><dd>{{ implode(', ', $competitor->strengths ?? []) ?: '—' }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Weaknesses</dt><dd>{{ implode(', ', $competitor->weaknesses ?? []) ?: '—' }}</dd></div>
            </dl>
        </x-filament::section>

        @foreach ($this->getStructuredProfile() as $label => $values)
            <x-filament::section :heading="$label">
                <pre class="overflow-x-auto whitespace-pre-wrap text-sm">{{ json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </x-filament::section>
        @endforeach

        <x-filament::section heading="Version history">
            <ol class="space-y-3">
                @foreach ($this->getHistory() as $version)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div>
                            <div class="font-medium">v{{ $version->version }} · {{ $version->name }}</div>
                            <div class="text-sm text-gray-500">{{ ucfirst($version->status) }}{{ $version->is_current ? ' · Current' : '' }}</div>
                        </div>
                        <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Competitors\CompetitorResource::getUrl('view', ['record' => $version]) }}">View version</a>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    </div>
</x-filament-panels::page>
