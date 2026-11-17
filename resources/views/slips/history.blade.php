<x-layouts::app :title="__('Slip History')">
    <main class="min-h-screen bg-[#f6f5f1] px-4 py-8 text-zinc-900 dark:bg-[#111712] dark:text-zinc-100 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-6xl space-y-6">
            <div>
                <flux:heading size="xl">Slip History</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-300">
                    Review the forms you have completed and submitted.
                </flux:text>
            </div>

            @if (! $hasHistory)
                <section class="rounded-xl bg-white p-6 text-sm text-zinc-600 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-300 dark:ring-zinc-700">
                    You have not completed any assigned slips yet.
                </section>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($folders as $folder)
                        <details class="group rounded-xl bg-white shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-700">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                <span class="flex items-center gap-3"><span class="text-xl">📁</span>{{ $folder['title'] }}</span>
                                <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $folder['entries']->count() }}</span>
                            </summary>
                            <div class="space-y-3 border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
                                @forelse ($folder['entries'] as $entry)
                                    <article class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <h3 class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $entry['title'] }}</h3>
                                                @if (! empty($entry['reason']))
                                                    <p class="mt-1 text-xs text-zinc-500">Reason for referral: {{ $entry['reason'] }}</p>
                                                @endif
                                                @if (! empty($entry['purpose']))
                                                    <p class="mt-1 text-xs text-zinc-500">{{ $entry['purpose'] }}</p>
                                                @endif
                                                @if (! empty($entry['scheduled_at']))
                                                    <p class="mt-1 text-xs text-zinc-500">Scheduled: {{ $entry['scheduled_at']->format('M j, Y g:i A') }}</p>
                                                @endif
                                                <p class="mt-1 text-xs text-zinc-500">{{ $entry['submitted_at']->format('M j, Y g:i A') }}</p>
                                            </div>
                                            <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ str($entry['status'] ?? 'pending')->replace('_', ' ')->title() }}</span>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-4 text-sm">
                                            @if ($entry['show_url']) <a href="{{ $entry['show_url'] }}" class="font-semibold text-[#28643a] hover:underline dark:text-[#91dfa6]">View</a> @endif
                                            @if ($entry['pdf_url']) <a href="{{ $entry['pdf_url'] }}" class="font-semibold text-[#28643a] hover:underline dark:text-[#91dfa6]">PDF</a> @endif
                                            @if (! $entry['show_url'] && ! $entry['pdf_url']) <span class="text-xs text-zinc-500">Pending</span> @endif
                                        </div>
                                        @if (! empty($entry['admission']))
                                            <div class="mt-4 rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-medium text-emerald-900 dark:text-emerald-100">{{ $entry['admission']['title'] }}</p>
                                                        <p class="mt-1 text-xs text-emerald-800/80 dark:text-emerald-200/80">{{ $entry['admission']['submitted_at']->format('M j, Y g:i A') }}</p>
                                                    </div>
                                                    <span class="rounded-full bg-white/70 px-2.5 py-1 text-xs text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-100">{{ str($entry['admission']['status'] ?? 'pending')->replace('_', ' ')->title() }}</span>
                                                </div>
                                                <div class="mt-2 flex flex-wrap gap-4 text-sm">
                                                    @if ($entry['admission']['show_url']) <a href="{{ $entry['admission']['show_url'] }}" class="font-semibold text-emerald-800 hover:underline dark:text-emerald-200">View result</a> @endif
                                                    @if ($entry['admission']['pdf_url']) <a href="{{ $entry['admission']['pdf_url'] }}" class="font-semibold text-emerald-800 hover:underline dark:text-emerald-200">PDF</a> @endif
                                                </div>
                                            </div>
                                        @endif
                                    </article>
                                @empty
                                    <p class="text-sm text-zinc-500">This folder is empty.</p>
                                @endforelse
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif
        </div>
    </main>
</x-layouts::app>
