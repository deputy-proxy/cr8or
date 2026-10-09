<x-filament-panels::page>
    <x-filament::section
        heading="Work Overview"
        description="A consolidated view of work records across the organizations you can access."
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($overview as $item)
                <a
                    href="{{ $item['url'] }}"
                    class="group block rounded-sm border border-gray-200 p-5 transition hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5"
                >
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="text-sm font-medium text-gray-600 group-hover:text-primary-600 dark:text-gray-300 dark:group-hover:text-primary-400">
                            {{ $item['label'] }}
                        </h2>
                        <x-filament::icon
                            icon="heroicon-o-arrow-up-right"
                            class="h-4 w-4 text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400"
                        />
                    </div>
                    <p class="mt-3 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ number_format($item['count']) }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">View records</p>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
