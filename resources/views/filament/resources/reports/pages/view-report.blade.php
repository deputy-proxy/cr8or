<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Report details">
            <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div><dt class="text-sm font-medium text-gray-500">Enterprise</dt><dd>{{ $report->enterprise->name }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Type</dt><dd>{{ $report->report_type }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Generated</dt><dd>{{ $report->generated_at?->toDateTimeString() ?? '—' }}</dd></div>
                <div><dt class="text-sm font-medium text-gray-500">Methodology</dt><dd>{{ $report->methodology_version }}</dd></div>
                <div class="md:col-span-2"><dt class="text-sm font-medium text-gray-500">Source fingerprint</dt><dd class="break-all font-mono text-sm">{{ $report->snapshot?->source_fingerprint ?? '—' }}</dd></div>
            </dl>
        </x-filament::section>

        @if ($report->result_payload)
            <x-filament::section heading="Executive summary">
                <p class="whitespace-pre-wrap">{{ data_get($report->result_payload, 'executive_summary', 'No executive summary is available.') }}</p>
            </x-filament::section>

            @foreach (['competitors' => 'Competitors', 'comparisons' => 'Comparisons', 'profile_coverage' => 'Profile coverage', 'findings' => 'Findings', 'risks' => 'Risks', 'recommendations' => 'Recommendations', 'limitations' => 'Limitations', 'evidence' => 'Evidence'] as $field => $label)
                @if (data_get($report->result_payload, $field) !== null)
                    <x-filament::section :heading="$label" collapsible>
                        <pre class="overflow-x-auto whitespace-pre-wrap text-sm">{{ json_encode(data_get($report->result_payload, $field), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </x-filament::section>
                @endif
            @endforeach
        @endif

        @if ($report->snapshot)
            <x-filament::section heading="Immutable input snapshot" collapsible collapsed>
                <pre class="overflow-x-auto whitespace-pre-wrap text-sm">{{ json_encode($report->snapshot->source_records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>