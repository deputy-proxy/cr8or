@php
    $points = $measurements->values();
    $count = $points->count();
    $values = $points->map(fn ($measurement): float => (float) $measurement->value);
    $minimum = $values->min() ?? 0;
    $maximum = $values->max() ?? 0;
    $range = max($maximum - $minimum, 1);
    $width = 720;
    $height = 190;
    $padding = 18;
    $firstTimestamp = $points->first()?->measured_at?->getTimestamp() ?? 0;
    $lastTimestamp = $points->last()?->measured_at?->getTimestamp() ?? 0;
    $timeRange = max($lastTimestamp - $firstTimestamp, 1);
    $coordinates = $values->map(function (float $value, int $index) use ($points, $count, $width, $height, $padding, $minimum, $range, $firstTimestamp, $timeRange): string {
        $timestamp = $points[$index]->measured_at->getTimestamp();
        $x = $count <= 1 ? $width / 2 : $padding + (($timestamp - $firstTimestamp) / $timeRange) * ($width - 2 * $padding);
        if ($count > 1 && $firstTimestamp === ($points->last()->measured_at->getTimestamp())) {
            $x = $padding + ($index / ($count - 1)) * ($width - 2 * $padding);
        }
        $y = $height - $padding - (($value - $minimum) / $range) * ($height - 2 * $padding);

        return number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
    });
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <div class="mb-4 flex items-center justify-between gap-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Historical evolution</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">Latest {{ $count }} {{ str('measurement')->plural($count) }}{{ $unit ? ' · '.$unit : '' }}</p>
        </div>
        @if ($count > 0)
            <div class="text-right">
                <p class="text-xs text-gray-500 dark:text-gray-400">Latest recorded value</p>
                <p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $points->last()->value }}</p>
            </div>
        @endif
    </div>

    @if ($count >= 2)
        <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="KPI measurement trend" class="h-52 w-full overflow-visible">
            @foreach ([0.25, 0.5, 0.75] as $fraction)
                <line x1="0" y1="{{ $height * $fraction }}" x2="{{ $width }}" y2="{{ $height * $fraction }}" stroke="currentColor" class="text-gray-200 dark:text-white/10" stroke-dasharray="3 5" />
            @endforeach
            <polyline points="{{ $coordinates->implode(' ') }}" fill="none" stroke="#16a34a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            @foreach ($coordinates as $coordinate)
                @php [$x, $y] = array_map('floatval', explode(',', $coordinate)); @endphp
                <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="#16a34a" stroke="white" stroke-width="2" />
            @endforeach
        </svg>
        <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>{{ $points->first()->measured_at->format('M j, Y') }}</span>
            <span>{{ $points->last()->measured_at->format('M j, Y') }}</span>
        </div>
    @elseif ($count === 1)
        <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">One measurement recorded. Add another to see the trend.</p>
    @else
        <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No measurements have been recorded yet.</p>
    @endif
</div>
