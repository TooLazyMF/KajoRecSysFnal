<x-layouts::app :title="__('Received Referrals')">
    <main class="min-h-screen bg-[#f6f5f1] p-5 text-slate-900 dark:bg-[#111712] dark:text-zinc-100 sm:p-6 lg:p-8">
        <div class="mx-auto w-full max-w-7xl space-y-6">
            <div>
                <a href="{{ route('guidance.dashboard') }}" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400">← Back to dashboard</a>
                <p class="mt-5 text-xs font-bold uppercase tracking-wide text-purple-700 dark:text-purple-400">Slip management</p>
                <h1 class="mt-1 text-3xl font-bold">Received Student Referral Slips</h1>
                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Select a student to view the tardy count and referral reason.</p>
            </div>

            @if ($errors->any())
                <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                @if ($referrals->isEmpty())
                    <p class="p-8 text-center text-sm text-neutral-500">There are no received referrals needing follow-up forms.</p>
                @else
                    <div class="w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($referrals as $row)
                            @php
                                $referral = $row['assignment'];
                                $referralData = $referral->slipSubmission?->data?->getArrayCopy() ?? [];
                                $absenceDates = (array) ($referralData['absence_dates'] ?? []);
                                if ($absenceDates === [] && filled($referralData['date_absent'] ?? null)) {
                                    $absenceDates = explode(', ', $referralData['date_absent']);
                                }
                                $reasonLabels = [
                                    'class_attendance' => 'Class Attendance',
                                    'psychological_testing' => 'Psychological Testing',
                                    'student_housing' => 'Student Housing (Dormitory)',
                                    'academic_deficiencies' => 'Academic Deficiencies',
                                    'scholarship_grant_assistance' => 'Scholarship/Grant Assistance',
                                    'student_employment' => 'Student Employment (SA/SPS)',
                                    'other' => 'Other',
                                ];
                                $referralReasons = collect((array) ($referralData['referral_reasons'] ?? []))
                                    ->map(function ($reason) use ($reasonLabels, $referralData) {
                                        if ($reason === 'other' && filled($referralData['other_reason'] ?? null)) {
                                            return 'Other: '.$referralData['other_reason'];
                                        }

                                        return $reasonLabels[$reason] ?? str($reason)->replace('_', ' ')->title();
                                    });
                            @endphp
                            <div>
                                <button
                                    type="button"
                                    aria-label="View referral details for {{ $referral->student->name }}"
                                    aria-haspopup="dialog"
                                    aria-controls="referral-details-modal-{{ $referral->id }}"
                                    onclick="document.getElementById('referral-details-modal-{{ $referral->id }}').showModal()"
                                    class="w-full px-5 py-4 text-left font-semibold text-neutral-900 transition hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500 dark:text-white dark:hover:bg-neutral-800"
                                >
                                    {{ $referral->student->name }}
                                </button>
                            </div>
                            <dialog id="referral-details-modal-{{ $referral->id }}" class="w-[min(36rem,calc(100%-2rem))] max-w-none rounded-2xl border border-neutral-200 bg-white p-0 shadow-2xl backdrop:bg-black/50 dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                                    <h2 class="text-lg font-semibold">{{ $referral->student->name }} — Referral Details</h2>
                                    <button type="button" onclick="this.closest('dialog').close()" class="text-2xl leading-none text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200" aria-label="Close referral details">&times;</button>
                                </div>
                                <div class="space-y-5 p-5">
                                    <dl class="space-y-4">
                                        <div>
                                            <dt class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Number of days absent/tardy</dt>
                                            <dd class="mt-1 font-semibold">{{ $referralData['days_absent'] ?? '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Dates absent/tardy</dt>
                                            <dd class="mt-1 space-y-1 font-semibold">
                                                @forelse ($absenceDates as $absenceDate)
                                                    <p>{{ $absenceDate }}</p>
                                                @empty
                                                    <p>—</p>
                                                @endforelse
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Reason for referral</dt>
                                            <dd class="mt-1 space-y-1 font-semibold">
                                                @forelse ($referralReasons as $reason)
                                                    <p>{{ $reason }}</p>
                                                @empty
                                                    <p>—</p>
                                                @endforelse
                                            </dd>
                                        </div>
                                    </dl>
                                    @if ($row['appointment'])
                                        <section class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                                            <h3 class="font-semibold">Counseling appointment</h3>
                                            <p class="mt-1 text-sm">
                                                Status: <span class="font-semibold">{{ str($row['appointment']->status)->replace('_', ' ')->title() }}</span>
                                                @if ($row['appointment']->scheduled_at)
                                                    · {{ $row['appointment']->scheduled_at->format('M j, Y g:i A') }}
                                                @endif
                                            </p>
                                            @if ($row['appointment_requires_completion'])
                                                <p class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-200">Class Admission is on hold until this appointment is completed.</p>
                                            @endif
                                        </section>
                                    @endif
                                    @if (! $row['appointment'] || in_array($row['appointment']->status, ['missed', 'declined'], true))
                                        <form method="POST" action="{{ route('guidance.referrals.appoint', $referral) }}" data-appointment-reservation-date="appointment-date-{{ $referral->id }}" data-appointment-reservation-time="appointment-time-{{ $referral->id }}" data-appointment-reservation-duration="appointment-duration-{{ $referral->id }}" data-appointment-reservation-exclude-id="" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                                            @csrf
                                            <h3 class="font-semibold">Set a counseling appointment</h3>
                                            <p class="text-sm text-neutral-600 dark:text-neutral-300">The student will be asked to confirm this time or suggest another available time.</p>
                                            <label class="block text-sm font-medium" for="appointment-date-{{ $referral->id }}">Date</label>
                                            <input id="appointment-date-{{ $referral->id }}" name="scheduled_date" type="date" min="{{ now()->format('Y-m-d') }}" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">
                                            @error('scheduled_date')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                                            <label class="block text-sm font-medium" for="appointment-time-{{ $referral->id }}">Time</label>
                                            <input id="appointment-time-{{ $referral->id }}" name="scheduled_time" type="time" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">
                                            @error('scheduled_time')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                                            <p data-appointment-availability-feedback role="status" class="text-sm text-red-600 dark:text-red-400" aria-live="polite"></p>
                                            <label class="block text-sm font-medium" for="appointment-duration-{{ $referral->id }}">Duration</label>
                                            <select id="appointment-duration-{{ $referral->id }}" name="duration_minutes" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">
                                                <option value="30">30 minutes</option>
                                                <option value="60" selected>1 hour</option>
                                                <option value="90">1 hour 30 minutes</option>
                                                <option value="120">2 hours</option>
                                            </select>
                                            @error('duration_minutes')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                                            <label class="block text-sm font-medium" for="appointment-concerns-{{ $referral->id }}">Appointment concern</label>
                                            <textarea id="appointment-concerns-{{ $referral->id }}" name="concerns" rows="3" maxlength="10000" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">{{ old('concerns', $referralReasons->implode(', ')) }}</textarea>
                                            @error('concerns')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                                            <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">Send appointment proposal</button>
                                        </form>
                                    @elseif (in_array($row['appointment']->status, ['pending_confirmation', 'reschedule_requested'], true))
                                        <form method="POST" action="{{ route('guidance.referrals.appointments.update', [$referral, $row['appointment']]) }}" data-appointment-reservation-date="appointment-update-date-{{ $referral->id }}" data-appointment-reservation-time="appointment-update-time-{{ $referral->id }}" data-appointment-reservation-duration="appointment-update-duration-{{ $referral->id }}" data-appointment-reservation-exclude-id="{{ $row['appointment']->id }}" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                                            @csrf
                                            @method('PATCH')
                                            <h3 class="font-semibold">{{ $row['appointment']->status === 'reschedule_requested' ? 'Review student’s suggested time' : 'Update appointment proposal' }}</h3>
                                            <label class="block text-sm font-medium" for="appointment-update-date-{{ $referral->id }}">Date</label>
                                            <input id="appointment-update-date-{{ $referral->id }}" name="scheduled_date" type="date" min="{{ now()->format('Y-m-d') }}" value="{{ $row['appointment']->scheduled_at?->format('Y-m-d') }}" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">
                                            <label class="block text-sm font-medium" for="appointment-update-time-{{ $referral->id }}">Time</label>
                                            <input id="appointment-update-time-{{ $referral->id }}" name="scheduled_time" type="time" value="{{ $row['appointment']->scheduled_at?->format('H:i') }}" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">
                                            <p data-appointment-availability-feedback role="status" class="text-sm text-red-600 dark:text-red-400" aria-live="polite"></p>
                                            <label class="block text-sm font-medium" for="appointment-update-duration-{{ $referral->id }}">Duration</label>
                                            <select id="appointment-update-duration-{{ $referral->id }}" name="duration_minutes" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">
                                                @foreach ([30, 60, 90, 120] as $duration)
                                                    <option value="{{ $duration }}" @selected((int) $row['appointment']->duration_minutes === $duration)>{{ $duration }} minutes</option>
                                                @endforeach
                                            </select>
                                            <label class="block text-sm font-medium" for="appointment-update-concerns-{{ $referral->id }}">Appointment concern</label>
                                            <textarea id="appointment-update-concerns-{{ $referral->id }}" name="concerns" rows="3" maxlength="10000" required class="w-full rounded-lg border-neutral-300 dark:border-neutral-700 dark:bg-neutral-800">{{ $row['appointment']->concerns ?: $row['appointment']->purpose }}</textarea>
                                            @if ($row['appointment']->status === 'reschedule_requested')
                                                <p class="text-sm text-neutral-600 dark:text-neutral-300">Student’s note: {{ $row['appointment']->reschedule_reason }}</p>
                                            @endif
                                            <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">Send updated proposal</button>
                                        </form>
                                    @endif
                                    @if ($row['can_issue_class_admission'] && $referral->slipSubmission)
                                        <form method="POST" action="{{ route('guidance.slip-review.issue', [$referral, 'class-admission-slip']) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-green-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                                Send Class Admission Slip
                                            </button>
                                        </form>
                                    @elseif ($row['appointment_requires_completion'])
                                        <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Class Admission cannot be sent until the counseling appointment is marked done.</p>
                                    @elseif (! $row['can_issue_class_admission'])
                                        <p class="text-sm font-medium text-green-700 dark:text-green-400">Class Admission Slip already sent</p>
                                    @endif
                                    @if ($referral->slipSubmission)
                                        <a
                                            href="{{ route('slips.submissions.pdf', $referral->slipSubmission) }}"
                                            aria-label="Download completed referral PDF for {{ $referral->student->name }}"
                                            title="Download completed referral PDF"
                                            class="inline-flex h-11 w-11 items-center justify-center rounded-lg bg-green-600 text-white transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <path d="m7 10 5 5 5-5" />
                                                <path d="M12 15V3" />
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            </dialog>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </main>
    <script>
        (() => {
            const reservations = @js($appointmentReservations);
            const dailyLimit = @js(\App\Services\AppointmentAvailability::DAILY_LIMIT);
            const minutesFromTime = (time) => {
                const [hours, minutes] = time.split(':').map(Number);
                return hours * 60 + minutes;
            };

            document.querySelectorAll('[data-appointment-reservation-date]').forEach((form) => {
                const date = document.getElementById(form.dataset.appointmentReservationDate);
                const time = document.getElementById(form.dataset.appointmentReservationTime);
                const duration = document.getElementById(form.dataset.appointmentReservationDuration);
                const feedback = form.querySelector('[data-appointment-availability-feedback]');
                if (!date || !time || !duration || !feedback) return;

                const updateAvailability = () => {
                    time.setCustomValidity('');
                    feedback.textContent = '';
                    if (!date.value || !time.value) return;

                    const reservedForDay = reservations.filter((reservation) =>
                        reservation.date === date.value
                        && String(reservation.id) !== form.dataset.appointmentReservationExcludeId
                    );

                    if (reservedForDay.length >= dailyLimit) {
                        const message = 'The Guidance Office already has five appointments on this date. Please choose another date.';
                        time.setCustomValidity(message);
                        feedback.textContent = message;
                        return;
                    }

                    const start = minutesFromTime(time.value);
                    const end = start + Number(duration.value);
                    const overlaps = reservedForDay.some((reservation) => {
                        const reservedStart = minutesFromTime(reservation.time);
                        const reservedEnd = reservedStart + reservation.duration;

                        // Half-open intervals detect overlap while allowing back-to-back appointments.
                        return start < reservedEnd && end > reservedStart;
                    });

                    if (overlaps) {
                        const message = 'This time overlaps another appointment. Please choose a different time.';
                        time.setCustomValidity(message);
                        feedback.textContent = message;
                    }
                };

                date.addEventListener('change', updateAvailability);
                time.addEventListener('input', updateAvailability);
                duration.addEventListener('change', updateAvailability);
                updateAvailability();
            });
        })();
    </script>
    @include('partials.auto-refresh')
</x-layouts::app>
