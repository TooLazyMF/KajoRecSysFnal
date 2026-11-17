@php
    $admission = $referral->followups->firstWhere('slip', 'class-admission-slip');
    $steps = [
        [
            'title' => 'Referral sent to Guidance and student',
            'detail' => 'Teacher: '.($referral->assignedBy?->name ?? 'Unknown').', sent to Guidance and student: '.($referral->student?->name ?? 'Unknown').', sent: '.($referral->completed_at?->format('M j, Y g:i A') ?? $referral->created_at?->format('M j, Y g:i A') ?? 'Time unavailable'),
            'complete' => true,
        ],
        [
            'title' => 'Referral received by Guidance',
            'detail' => $referral->completed_at ? 'Guidance can review the referral since '.$referral->completed_at->format('M j, Y g:i A').'.' : 'Waiting for the referral to reach Guidance.',
            'complete' => $referral->status !== 'pending',
        ],
        [
            'title' => 'Guidance review',
            'detail' => $referral->approved_at ? 'Guidance Counselor approved the referral: '.$referral->approved_at->format('M j, Y g:i A') : 'Waiting for the counselor to review the referral.',
            'complete' => (bool) $referral->approved_at,
        ],
    ];

    if ($admission) {
        $counseling = $referral->appointments->sortByDesc('created_at')->first();
        if ($counseling) {
            $steps[] = [
                'title' => 'Counseling session',
                'detail' => $counseling->status === 'done'
                    ? 'Counseling completed on '.($counseling->completed_at?->format('M j, Y g:i A') ?? 'the recorded completion date').'. Guidance may now release the Class Admission result.'
                    : 'A counseling session is ongoing. The Class Admission result remains pending until the session is completed.',
                'complete' => $counseling->status === 'done',
            ];
        }

        $steps = [...$steps,
            [
                'title' => 'Class Admission form sent to student',
                'detail' => 'Counselor: '.($admission->assignedBy?->name ?? 'Unknown').', student: '.($referral->student?->name ?? 'Unknown').', sent: '.($admission->created_at?->format('M j, Y g:i A') ?? 'Time unavailable'),
                'complete' => true,
            ],
            [
                'title' => 'Student sent Class Admission to Guidance',
                'detail' => $admission->completed_at ? 'Student: '.($referral->student?->name ?? 'Unknown').', sent: '.$admission->completed_at->format('M j, Y g:i A') : 'Waiting for the student to submit Class Admission.',
                'complete' => (bool) $admission->completed_at,
            ],
            [
                'title' => 'Counselor sent completed result to teacher',
                'detail' => $admission->approved_at ? 'Counselor: '.($admission->approver?->name ?? 'Unknown').', sent to teacher: '.($referral->assignedBy?->name ?? 'Unknown').', sent: '.$admission->approved_at->format('M j, Y g:i A') : 'Waiting for Guidance to issue and send the result.',
                'complete' => (bool) $admission->approved_at,
            ],
            [
                'title' => 'Result received by teacher',
                'detail' => $referral->teacher_acknowledged_at ? 'Teacher: '.($referral->assignedBy?->name ?? 'Unknown').', received: '.$referral->teacher_acknowledged_at->format('M j, Y g:i A') : 'Waiting for the teacher to acknowledge the result.',
                'complete' => (bool) $referral->teacher_acknowledged_at,
            ],
        ];
    } else {
        $steps[] = [
            'title' => 'Class Admission',
            'detail' => 'Not issued yet.',
            'complete' => false,
        ];
    }
@endphp

<section>
    <h3 class="mb-4 text-base font-bold">Referral process</h3>
    <ol class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($steps as $step)
            <li class="rounded-xl border p-3 {{ $step['complete'] ? 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50' }}">
                <p class="text-sm font-semibold"><span class="mr-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-700 text-[10px] text-white">{{ $loop->iteration }}</span>{{ $step['title'] }}</p>
                <p class="mt-1.5 text-xs leading-5 text-zinc-700 dark:text-zinc-300">{{ $step['detail'] }}</p>
                @if (!$loop->last)
                    <div class="mt-3 flex justify-end border-t border-zinc-200 pt-2 text-xs font-bold text-emerald-700 dark:border-zinc-700 dark:text-emerald-300">
                        <span class="sm:hidden">Next step ↓</span>
                        <span class="hidden sm:inline">Next step {{ $loop->iteration % 2 === 1 ? '→' : '↙' }}</span>
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
