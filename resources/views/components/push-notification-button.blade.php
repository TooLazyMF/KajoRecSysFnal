@if (filled(config('webpush.vapid.public_key')))
    <div
        data-push-notifications
        data-vapid-public-key="{{ config('webpush.vapid.public_key') }}"
        class="mt-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
    >
        <button
            type="button"
            data-push-enable
            class="w-full rounded-lg bg-[#28643a] px-3 py-2 text-sm font-semibold text-white hover:bg-[#1e4d2c] disabled:cursor-not-allowed disabled:opacity-60"
        >
            Enable native notifications
        </button>
        <p data-push-status class="mt-2 text-xs text-zinc-500 dark:text-zinc-400"></p>
    </div>
    @vite('resources/js/push-notifications.js')
@endif
