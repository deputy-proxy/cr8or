<x-layouts::app title="Connect social destinations">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">Connect social destinations</h1>
            <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                Authorization succeeded. Select which discovered destinations to attach to a CR8OR channel.
            </p>
        </div>

        @if (session('error'))
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                {{ session('error') }}
            </div>
        @endif

        @if (count($destinations) === 0)
            <div class="rounded-lg border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                No destinations were returned for this network. The authorization is saved, but no publishable destination was added.
            </div>
        @elseif ($channels->isEmpty())
            <div class="rounded-lg border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                No eligible channels exist in this organization. Create a channel first, then reconnect or select destinations.
            </div>
        @else
            <form method="POST" action="{{ route('social.authorization.attach', ['attempt' => $attempt->id]) }}" class="flex flex-col gap-5">
                @csrf

                <div class="flex flex-col gap-2">
                    <label for="channel_id" class="text-sm font-medium">CR8OR channel</label>
                    @if ($attempt->channel_id !== null)
                        <input type="hidden" name="channel_id" value="{{ $attempt->channel_id }}">
                        <div class="rounded-lg border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-700">
                            {{ $channels->firstWhere('id', $attempt->channel_id)?->name ?? 'Selected channel' }}
                        </div>
                    @else
                        <select id="channel_id" name="channel_id" required class="rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">Select a channel</option>
                            @foreach ($channels as $channel)
                                <option value="{{ $channel->id }}" @selected(old('channel_id') == $channel->id)>{{ $channel->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    @error('channel_id')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <fieldset class="flex flex-col gap-3">
                    <legend class="text-sm font-medium">Destinations</legend>
                    @foreach ($destinations as $index => $destination)
                        <label class="flex items-start gap-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                            <input type="checkbox" name="destination_indexes[]" value="{{ $index }}" @checked(in_array((string) $index, array_map('strval', old('destination_indexes', [])), true)) class="mt-1 rounded border-neutral-300">
                            <span class="flex min-w-0 flex-col gap-1">
                                <span class="font-medium">{{ $destination['name'] ?? $destination['external_id'] ?? 'Unknown destination' }}</span>
                                <span class="text-xs text-neutral-500">{{ strtoupper($destination['provider'] ?? $attempt->destination_network) }} · ID {{ $destination['external_id'] ?? 'unavailable' }}</span>
                                @if (! empty($destination['url']))
                                    <a class="text-sm underline" href="{{ $destination['url'] }}" target="_blank" rel="noopener noreferrer">Open external destination</a>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @error('destination_indexes')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                        Attach selected destinations
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-layouts::app>