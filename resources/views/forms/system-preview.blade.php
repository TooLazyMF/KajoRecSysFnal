<div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">System view-only preview</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">This preview cannot edit the original document.</p>
        </div>
        <span class="rounded-full bg-zinc-200 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">Read only</span>
    </div>

    @if (! empty($slip['prefilled_fields']))
        <section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Referral Information</h3>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($slip['prefilled_fields'] as $field)
                    @php
                        $value = $previewData[$field['key']] ?? null;
                        $displayValue = is_array($value)
                            ? collect($value)->map(fn ($item) => $field['options'][$item] ?? $item)->join(', ')
                            : ($field['options'][$value] ?? $value);
                    @endphp
                    <div class="{{ in_array($field['type'], ['textarea', 'checkboxes'], true) ? 'sm:col-span-2' : '' }}">
                        <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $field['label'] }}</p>
                        <div class="mt-1 min-h-9 whitespace-pre-line rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                            {{ filled($displayValue) ? $displayValue : '—' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="space-y-4">
        @foreach ($slip['groups'] as $group)
            <section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $group['title'] }}</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($group['fields'] as $field)
                        @php
                            $value = $previewData[$field['key']] ?? null;
                            $displayValue = is_array($value)
                                ? collect($value)->map(fn ($item) => $field['options'][$item] ?? $item)->join(', ')
                                : ($field['options'][$value] ?? $value);
                        @endphp
                        <div class="{{ in_array($field['type'], ['textarea', 'checkboxes'], true) ? 'sm:col-span-2' : '' }}">
                            <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $field['label'] }}</p>
                            <div class="mt-1 min-h-9 whitespace-pre-line rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                {{ filled($displayValue) ? $displayValue : '—' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    @if (! empty($slip['guidance_fields']))
        <section class="mt-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Guidance Office Review</h3>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($slip['guidance_fields'] as $field)
                    @php
                        $value = $previewData[$field['key']] ?? null;
                        $displayValue = is_array($value)
                            ? collect($value)->map(fn ($item) => $field['options'][$item] ?? $item)->join(', ')
                            : ($field['options'][$value] ?? $value);
                    @endphp
                    <div class="{{ in_array($field['type'], ['textarea', 'checkboxes'], true) ? 'sm:col-span-2' : '' }}">
                        <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $field['label'] }}</p>
                        <div class="mt-1 min-h-9 whitespace-pre-line rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                            {{ filled($displayValue) ? $displayValue : '—' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
