@if (filled(config('webpush.vapid.public_key')))
    <div
        data-push-notifications
        data-vapid-public-key="{{ config('webpush.vapid.public_key') }}"
        class="mt-4 sm:mt-0"
    >
        <button
            type="button"
            data-push-open
            class="inline-flex items-center rounded-lg border border-emerald-300 bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50 dark:border-emerald-800 dark:bg-zinc-900 dark:text-emerald-200 dark:hover:bg-zinc-800"
        >
            Enable native notifications
        </button>

        <dialog data-push-dialog class="w-[min(28rem,calc(100%-2rem))] rounded-2xl border border-emerald-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-black/50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Browser notifications</p>
                        <h2 class="mt-1 text-xl font-bold">Enable native notifications</h2>
                    </div>
                    <button type="button" data-push-close class="text-2xl leading-none text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200" aria-label="Close">&times;</button>
                </div>
                <p class="mt-3 text-sm text-neutral-600 dark:text-zinc-300">Allow browser notifications to receive updates even when KajoRec is not open.</p>
                <p data-push-status role="status" aria-live="polite" class="mt-4 text-sm text-neutral-500 dark:text-zinc-400">Choose Enable to continue.</p>
                <div class="mt-5 flex flex-wrap justify-end gap-3">
                    <button type="button" data-push-close class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-semibold hover:bg-neutral-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Not now</button>
                    <button
                        type="button"
                        data-push-enable
                        class="rounded-lg bg-[#28643a] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1e4d2c] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Enable
                    </button>
                </div>
            </div>
        </dialog>
    </div>
    @vite('resources/js/push-notifications.js')
@endif
