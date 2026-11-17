<x-layouts::app :title="__('Slip Information Submitted')">
    <main class="min-h-screen bg-zinc-50 px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-5xl space-y-6">
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-zinc-200">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading size="xl">Slip Information Submitted</flux:heading>
                        <flux:text class="mt-2 text-zinc-600">
                            Your information was submitted with status: {{ ucfirst($slipInformation->review_status) }}.
                            @if ($slipInformation->review_status !== 'approved')
                                You can edit it until it is approved by the Guidance Office.
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex gap-3">
                        @if ($assignment && $slipInformation->review_status !== 'approved' && auth()->user()->role === 'student')
                            <a
                                href="{{ route('assigned-slips.fillup', $assignment) }}"
                                class="inline-flex items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-800"
                            >
                                Edit submission
                            </a>
                        @endif
                        <a
                            href="{{ auth()->user()->role === 'student' ? route('student.dashboard') : route('guidance.dashboard') }}"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100"
                        >
                            Done
                        </a>
                        <a
                            href="{{ route('slip-informations.pdf', $slipInformation) }}"
                            class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-zinc-700"
                        >
                            Download original PDF
                        </a>
                    </div>
                </div>
            </section>

            <iframe
                src="{{ route('slip-informations.system-preview', $slipInformation) }}"
                title="Submitted slip information preview"
                class="h-[80vh] w-full rounded-lg border border-zinc-300 bg-white"
            ></iframe>
        </div>
    </main>
</x-layouts::app>