<x-layouts::app :title="__('Account Logs')">
    <section class="w-full">
        @include('partials.settings-heading')

        <x-pages::settings.layout
            :heading="__('Account Logs')"
            :subheading="__('Review the devices and times associated with your account sessions.')"
        >
            <div class="space-y-5">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    New logs record the device and session times only; location is no longer collected.
                    IP addresses and raw browser details are not stored. You can end an active session from here.
                </p>

                <form method="GET" action="{{ route('account-logs.index') }}" class="flex flex-wrap items-end gap-3">
                    <label class="grid gap-1 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                        {{ __('Month') }}
                        <input
                            type="month"
                            name="month"
                            value="{{ $month }}"
                            required
                            class="rounded-lg border-zinc-300 bg-white text-sm text-zinc-900 shadow-sm focus:border-emerald-700 focus:ring-emerald-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                    </label>
                    <flux:button type="submit" variant="primary">{{ __('View logs') }}</flux:button>
                </form>

                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full min-w-[960px] divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                <th scope="col" class="px-4 py-3">{{ __('Device Name') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Logged In') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Last Activity') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Logged Out') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @forelse ($logs as $log)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $log->device_name }}</td>
                                    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $log->logged_in_at->format('M d, Y h:i A') }}</td>
                                    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $log->last_activity_at->format('M d, Y h:i A') }}</td>
                                    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                                        {{ $log->logged_out_at?->format('M d, Y h:i A') ?? __('Session active') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($logoutableLogIds->contains($log->id))
                                            <form method="POST" action="{{ route('account-logs.logout-device', $log) }}">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/40"
                                                    onclick="return confirm('{{ __('Log out this device?') }}')"
                                                >
                                                    {{ __('Log out this device') }}
                                                </button>
                                            </form>
                                        @elseif ($log->logged_out_at === null)
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Remote logout unavailable') }}</span>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                        {{ __('No account logs were recorded for this month.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @error('device')
                    <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                @if (session('status'))
                    <p role="status" class="text-sm text-emerald-700 dark:text-emerald-300">{{ session('status') }}</p>
                @endif

                <div>{{ $logs->links() }}</div>
            </div>
        </x-pages::settings.layout>
    </section>
</x-layouts::app>
