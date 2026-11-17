<flux:modal name="class-admission-slip-modal" class="w-[95vw] max-w-7xl">
    <div class="space-y-6">
        <div><flux:heading size="lg">Class Admission Slip</flux:heading><flux:subheading>The student's details and absence dates come from the Teacher Referral Slip. The student selects the cause(s) of absence, and Guidance completes the office review.</flux:subheading></div>
        @include('forms.system-preview', ['slip' => $slip, 'previewData' => []])
        <a href="{{ asset('slips/CLASS-ADMISSION-SLIP.pdf') }}" download="CLASS-ADMISSION-SLIP.pdf" class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700">Download original form</a>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <a href="{{ route('slips.fillup', 'class-admission-slip') }}" class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white">Issue to student</a>
        </div>
    </div>
</flux:modal>
