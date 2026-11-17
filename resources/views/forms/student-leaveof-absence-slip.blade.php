<flux:modal name="student-leaveof-absence-slip-modal" class="w-[95vw] max-w-7xl">
    <div class="space-y-6">
        <div><flux:heading size="lg">Student Leave of Absence Slip</flux:heading><flux:subheading>Preview the document before assigning it to a student.</flux:subheading></div>
        @include('forms.system-preview', ['slip' => $slip, 'previewData' => []])
        <a href="{{ asset('slips/STUDENT-LEAVEOF-ABSENCE-SLIP.pdf') }}" download="STUDENT-LEAVEOF-ABSENCE-SLIP.pdf" class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700">Download original form</a>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <a href="{{ route('slips.fillup', 'student-leaveof-absence-slip') }}" class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white">Assign to student</a>
        </div>
    </div>
</flux:modal>
