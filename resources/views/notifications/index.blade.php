<x-layouts::app :title="__('Notifications')">
    <main class="min-h-screen bg-[#f6f5f1] p-5 text-slate-900 dark:bg-[#111712] dark:text-zinc-100 sm:p-6 lg:p-8">
        <div class="mx-auto w-full max-w-4xl space-y-6">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Notifications</h1>
                    <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Unread updates for your account. Opening an update marks it as read and removes it from this list.</p>
                </div>
                <x-push-notification-button />
            </header>

            @if (session('status'))
                <p role="status" class="rounded-xl bg-green-50 p-4 text-sm text-green-800 dark:bg-green-950/40 dark:text-green-200">{{ session('status') }}</p>
            @endif

            @forelse ($notifications as $notification)
                @php($data = $notification->data)
                <article class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="font-semibold">{{ $data['title'] ?? 'Notification' }}</h2>
                            <p class="mt-2 text-sm leading-6 text-neutral-700 dark:text-neutral-300">{{ $data['message'] ?? '' }}</p>
                            <p class="mt-2 text-xs text-neutral-500">{{ $notification->created_at?->format('M j, Y g:i A') }}</p>
                        </div>
                        @if (! $notification->read_at)
                            <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-200">New</span>
                        @endif
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3">
                        @if (filled($data['url'] ?? null))
                            <a href="{{ route('notifications.open', $notification->id) }}" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800">View update</a>
                        @endif
                        @if (! $notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-semibold hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Mark as read</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <p class="rounded-xl border border-neutral-200 bg-white p-6 text-sm text-neutral-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">You have no notifications.</p>
            @endforelse
        </div>
    </main>
</x-layouts::app>
