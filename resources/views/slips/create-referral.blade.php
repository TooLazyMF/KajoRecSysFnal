<x-layouts::app :title="$title">
    <main class="min-h-screen bg-[#f6f5f1] px-4 py-8 text-zinc-900 dark:bg-[#111712] dark:text-zinc-100 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl space-y-6">
            <header>
                <flux:heading size="xl">{{ $title }}</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-300">
                    Select a student, verify their profile details, and complete the referral reason before sending it to the student for review.
                </flux:text>
            </header>

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($students->isEmpty())
                <section class="rounded-2xl bg-white p-6 shadow-sm dark:bg-zinc-900">
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">There are no student accounts available to select.</p>
                    <a href="{{ $cancelUrl }}" class="mt-4 inline-block text-sm font-semibold text-green-700 hover:underline">Back to dashboard</a>
                </section>
            @else
                <form method="POST" action="{{ $selectAction }}" class="space-y-6">
                    @csrf

                    <dialog id="student_picker" class="w-[min(56rem,calc(100%-2rem))] max-h-[85vh] rounded-2xl border border-zinc-200 bg-white p-0 text-zinc-900 shadow-2xl backdrop:bg-black/60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                        <div class="sticky top-0 z-10 border-b border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                            <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold">Select a student</h2><p class="mt-1 text-base text-zinc-600 dark:text-zinc-300">Students are arranged by college and course. Search by name, student ID, college, or course.</p></div><button type="button" id="close_student_picker" aria-label="Close student picker" class="rounded-lg px-3 py-1 text-2xl hover:bg-zinc-100 dark:hover:bg-zinc-800">&times;</button></div>
                            <label for="student_search" class="sr-only">Search students</label>
                            <input id="student_search" type="search" autocomplete="off" placeholder="Search name, ID, or course…" class="mt-4 w-full rounded-lg border-zinc-300 bg-white px-4 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                        </div>
                        <div id="student_picker_list" class="max-h-[58vh] overflow-y-auto p-4 sm:p-6">
                            @foreach ($students->groupBy(fn ($student) => \App\Support\SlipCatalog::canonicalCollege($studentCifs->get($student->id)['college'] ?? null) ?: 'College not listed')->sortKeys() as $college => $collegeStudents)
                                <section class="student-college-group mb-6 last:mb-0" data-course-group>
                                    <h3 class="mb-2 border-b border-emerald-300 pb-2 text-base font-bold text-emerald-800 dark:border-emerald-700 dark:text-emerald-300">{{ \App\Support\SlipCatalog::collegeOptions()[$college] ?? $college }}</h3>
                                @foreach ($collegeStudents->groupBy(fn ($student) => $studentCifs->get($student->id)['course'] ?? $studentReferences->get(\App\Models\StudentReference::normalizeIdNumber($student->id_number))?->course ?: 'Course not listed')->sortKeys() as $course => $courseStudents)
                                    <h4 class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $course }}</h4>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($courseStudents as $student)
                                            @php($studentReference = $studentReferences->get(\App\Models\StudentReference::normalizeIdNumber($student->id_number)))
                                            @php($studentCif = $studentCifs->get($student->id, []))
                                            @php($studentCourse = $studentCif['course'] ?? $studentReference?->course)
                                            @php($studentYear = $studentCif['year_level'] ?? $studentReference?->year)
                                            <button type="button" class="student-choice rounded-xl border border-zinc-200 p-4 text-left hover:border-emerald-500 hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-600 dark:border-zinc-700 dark:hover:bg-zinc-800" data-student-id="{{ $student->id }}" data-id-number="{{ $student->id_number }}" data-name="{{ $student->name }}" data-sex="{{ ucfirst($student->gender) }}" data-program-year="{{ trim(implode(' ', array_filter([$studentCourse, $studentYear]))) }}" data-search="{{ strtolower($student->name.' '.$student->id_number.' '.$studentCourse.' '.$studentYear) }}">
                                                <span class="block text-base font-semibold">{{ $student->name }}</span><span class="mt-1 block text-sm text-zinc-600 dark:text-zinc-300">{{ $student->id_number ?: 'No student ID' }} · {{ trim(implode(' ', array_filter([$studentCourse, $studentYear]))) ?: 'Course not listed' }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endforeach
                                </section>
                            @endforeach
                            <p id="no_students_match" class="hidden rounded-lg bg-zinc-50 p-5 text-center text-base text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">No students match your search.</p>
                        </div>
                    </dialog>

                    <section class="space-y-5 rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                        <flux:heading size="lg">Verify Student Information</flux:heading>

                        <div class="space-y-2">
                            <span class="block text-base font-semibold text-zinc-700 dark:text-zinc-200">Student</span>
                            <input id="student_id" type="hidden" name="student_id" value="{{ old('student_id', request('student_id')) }}" required>
                            <button type="button" id="open_student_picker" class="flex w-full items-center justify-between rounded-lg border border-zinc-300 bg-white px-4 py-3 text-left text-base text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                <span id="selected_student_label">Choose a student</span><span aria-hidden="true" class="text-xl">⌄</span>
                            </button>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="display_student_id" class="mb-1 block text-base font-medium">Student ID No.</label>
                                <input id="display_student_id" readonly class="w-full rounded-lg border-zinc-300 bg-zinc-100 px-3 py-3 text-base dark:border-zinc-700 dark:bg-zinc-800" value="">
                                <input id="details_student_id" type="hidden" name="details[student_id]" value="">
                            </div>
                            <div>
                                <label for="display_student_name" class="mb-1 block text-base font-medium">Name of Student</label>
                                <input id="display_student_name" readonly class="w-full rounded-lg border-zinc-300 bg-zinc-100 px-3 py-3 text-base dark:border-zinc-700 dark:bg-zinc-800" value="">
                                <input id="details_student_name" type="hidden" name="details[student_name]" value="">
                            </div>
                            <div>
                                <label for="display_referral_date" class="mb-1 block text-base font-medium">Date</label>
                                <input id="display_referral_date" readonly class="w-full rounded-lg border-zinc-300 bg-zinc-100 px-3 py-3 text-base dark:border-zinc-700 dark:bg-zinc-800" value="{{ $today }}">
                                <input type="hidden" name="details[request_date]" value="{{ $today }}">
                            </div>
                            <div>
                                <label for="display_student_sex" class="mb-1 block text-base font-medium">Sex</label>
                                <input id="display_student_sex" readonly class="w-full rounded-lg border-zinc-300 bg-zinc-100 px-3 py-3 text-base dark:border-zinc-700 dark:bg-zinc-800" value="">
                                <input id="details_student_sex" type="hidden" name="details[sex]" value="">
                            </div>
                        </div>

                        <label class="block space-y-2">
                            <span class="text-base font-medium text-zinc-700 dark:text-zinc-200">Degree Program &amp; Year Level</span>
                            <input
                                name="details[program_year]"
                                value="{{ old('details.program_year') }}"
                                required
                                maxlength="255"
                                class="w-full rounded-lg border-zinc-300 bg-white px-3 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800"
                            >
                        </label>
                    </section>

                    <section class="space-y-5 rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                        <flux:heading size="lg">Referral Information</flux:heading>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="order-2 block space-y-2">
                                <span class="text-base font-medium text-zinc-700 dark:text-zinc-200">Date/s Absent/Tardy</span>
                                <input
                                    id="date_absent"
                                    name="details[date_absent]"
                                    value="{{ old('details.date_absent') }}"
                                    required
                                    maxlength="255"
                                    placeholder="e.g. October 10–11, 2026"
                                    readonly
                                    class="w-full rounded-lg border-zinc-300 bg-zinc-100 px-3 py-3 text-base shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
                                >
                            </label>
                            <label class="order-1 block space-y-2">
                                <span class="text-base font-medium text-zinc-700 dark:text-zinc-200">Number of day/s absent/tardy</span>
                                <input
                                    id="days_absent"
                                    type="number"
                                    name="details[days_absent]"
                                    value="{{ old('details.days_absent') }}"
                                    required
                                    min="1"
                                    max="365"
                                    class="w-full rounded-lg border-zinc-300 bg-white px-3 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800"
                                >
                            </label>
                        </div>
                        <div>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Enter the number of days first, then select the date and cause for each absence or tardy. Absences with the same cause will be grouped into one referral.</p>
                            <div id="absence_dates" class="mt-3 grid gap-3 sm:grid-cols-2"></div>
                            <p class="mt-3 text-sm font-medium text-zinc-700 dark:text-zinc-200">Reason for Referral</p>
                            <div id="absence_reasons" class="mt-3 grid gap-3 sm:grid-cols-2"></div>
                        </div>

                        @if (false)
                        @foreach ($slip['groups'] as $group)
                            @foreach ($group['fields'] as $field)
                                @if ($field['type'] === 'checkboxes')
                                    <fieldset class="space-y-3">
                                        <legend class="text-base font-medium text-zinc-700 dark:text-zinc-200">
                                            {{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}
                                        </legend>
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            @foreach ($field['options'] as $value => $label)
                                                <label class="flex items-center gap-3 py-1 text-base text-zinc-700 dark:text-zinc-300">
                                                    <input
                                                        type="radio"
                                                        name="details[{{ $field['key'] }}][]"
                                                        value="{{ $value }}"
                                                        @checked(in_array($value, old('details.'.$field['key'], []), true))
                                                        @required($field['required'])
                                                        class="rounded border-zinc-300 text-emerald-700 focus:ring-emerald-700 dark:border-zinc-600 dark:bg-zinc-800"
                                                    >
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @elseif ($field['key'] === 'other_reason')
                                    <label class="block space-y-2">
                                                    <span class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ $field['label'] }}</span>
                                            <input
                                            id="other_reason"
                                            name="details[{{ $field['key'] }}]"
                                            value="{{ old('details.'.$field['key']) }}"
                                            maxlength="255"
                                            placeholder="Select Other to enter a reason"
                                            disabled
                                            readonly
                                            class="w-full rounded-lg border-zinc-300 bg-white px-3 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800"
                                        >
                                    </label>
                                @endif
                            @endforeach
                        @endforeach
                        @endif
                    </section>

                    <label class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-base text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                        <input type="checkbox" name="information_verified" value="1" required class="mt-0.5 rounded border-emerald-400 text-emerald-700 focus:ring-emerald-700">
                        <span>I verified the selected student's ID, name, date, and sex, and selected the appropriate referral reason.</span>
                    </label>

                    <div class="flex justify-end gap-3">
                        <a href="{{ $cancelUrl }}" class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">
                            Cancel
                        </a>
                        <flux:button variant="primary" type="submit">Verify &amp; Send Referral</flux:button>
                    </div>
                </form>
            @endif
        </div>
    </main>

    @if (!$students->isEmpty())
        @php($referralReasonField = collect($slip['groups'])->flatMap(fn ($group) => $group['fields'])->firstWhere('key', 'referral_reasons'))
        <script>
            const studentSelect = document.getElementById('student_id');
            const studentPicker = document.getElementById('student_picker');
            const studentSearch = document.getElementById('student_search');
            const selectedStudentLabel = document.getElementById('selected_student_label');
            const filterStudents = () => {
                const query = studentSearch.value.trim().toLocaleLowerCase();
                let visibleStudents = 0;

                document.querySelectorAll('.student-choice').forEach((button) => {
                    const matches = button.dataset.search.includes(query);
                    button.hidden = !matches;
                    if (matches) visibleStudents += 1;
                });
                document.querySelectorAll('[data-course-group]').forEach((group) => {
                    group.hidden = !group.querySelector('.student-choice:not([hidden])');
                });
                document.getElementById('no_students_match').classList.toggle('hidden', visibleStudents > 0);
            };

            document.getElementById('open_student_picker').addEventListener('click', () => {
                studentPicker.showModal();
                studentSearch.value = '';
                filterStudents();
                studentSearch.focus();
            });
            document.getElementById('close_student_picker').addEventListener('click', () => studentPicker.close());
            studentSearch.addEventListener('input', filterStudents);
            document.querySelectorAll('.student-choice').forEach((button) => {
                button.addEventListener('click', () => {
                    studentSelect.value = button.dataset.studentId;
                    selectedStudentLabel.textContent = `${button.dataset.name} · ${button.dataset.idNumber || 'No student ID'}`;
                    studentSelect.dispatchEvent(new Event('change'));
                    studentPicker.close();
                });
            });

            const updateStudentDetails = () => {
                const student = document.querySelector(`.student-choice[data-student-id="${CSS.escape(studentSelect.value)}"]`);
                const idNumber = student?.dataset.idNumber || '';
                const name = student?.dataset.name || '';
                const sex = student?.dataset.sex || '';
                const programYear = student?.dataset.programYear || '';

                document.getElementById('display_student_id').value = idNumber;
                document.getElementById('details_student_id').value = idNumber;
                document.getElementById('display_student_name').value = name;
                document.getElementById('details_student_name').value = name;
                document.getElementById('display_student_sex').value = sex;
                document.getElementById('details_student_sex').value = sex;
                const programYearInput = document.querySelector('input[name="details[program_year]"]');
                if (programYearInput) {
                    programYearInput.value = programYear;
                }
                if (student) selectedStudentLabel.textContent = `${name} · ${idNumber || 'No student ID'}`;
            };

            studentSelect.addEventListener('change', updateStudentDetails);
            updateStudentDetails();

            const daysAbsentInput = document.getElementById('days_absent');
            const absenceDates = document.getElementById('absence_dates');
            const dateAbsentInput = document.getElementById('date_absent');
            const oldAbsenceDates = @json(old('details.absence_dates', []));
            const oldAbsenceReasons = @json(old('details.absence_reasons', []));
            const oldOtherReasons = @json(old('details.absence_other_reasons', []));
            const reasonOptions = @json($referralReasonField['options'] ?? []);
            const today = new Date().toISOString().split('T')[0];

            const updateDateValidation = () => {
                const inputs = Array.from(absenceDates.querySelectorAll('input[type="date"]'));
                const selectedDates = inputs.map((input) => input.value).filter(Boolean);

                inputs.forEach((input) => {
                    const isDuplicate = input.value && selectedDates.filter((date) => date === input.value).length > 1;
                    input.setCustomValidity(isDuplicate ? 'This date has already been selected.' : '');
                });
            };

            const updateDateSummary = () => {
                dateAbsentInput.value = Array.from(absenceDates.querySelectorAll('input[type="date"]'))
                    .map((input) => input.value)
                    .filter(Boolean)
                    .join(', ');
                updateDateValidation();
            };

            const renderAbsenceDates = () => {
                const count = Math.min(Math.max(Number.parseInt(daysAbsentInput.value || '0', 10), 0), 365);
                const currentDates = Array.from(absenceDates.querySelectorAll('input[type="date"]')).map((input) => input.value);
                const dates = currentDates.length ? currentDates : oldAbsenceDates;
                const currentReasons = Array.from(document.querySelectorAll('#absence_reasons select')).map((input) => input.value);
                const currentOtherReasons = Array.from(document.querySelectorAll('#absence_reasons input[data-other-reason]')).map((input) => input.value);
                const reasons = currentReasons.length ? currentReasons : oldAbsenceReasons;
                const otherReasons = currentOtherReasons.length ? currentOtherReasons : oldOtherReasons;

                absenceDates.innerHTML = '';
                document.getElementById('absence_reasons').innerHTML = '';
                for (let index = 0; index < count; index += 1) {
                    const label = document.createElement('label');
                    label.className = 'block space-y-1';
                    label.innerHTML = `<span class="text-base font-medium text-zinc-600 dark:text-zinc-300">Date ${index + 1}</span><input type="date" name="details[absence_dates][]" max="${today}" required class="w-full rounded-lg border-zinc-300 bg-white px-3 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">`;
                    const input = label.querySelector('input');
                    input.value = dates[index] || '';
                    input.addEventListener('change', updateDateSummary);
                    input.addEventListener('input', updateDateSummary);
                    absenceDates.appendChild(label);

                    const reasonLabel = document.createElement('label');
                    reasonLabel.className = 'block space-y-1';
                    const options = Object.entries(reasonOptions)
                        .map(([value, label]) => `<option value="${value}">${label}</option>`)
                        .join('');
                    reasonLabel.innerHTML = `<span class="text-base font-medium text-zinc-600 dark:text-zinc-300">Cause for Date ${index + 1}</span><select name="details[absence_reasons][]" required class="w-full rounded-lg border-zinc-300 bg-white px-3 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-green-700 dark:bg-zinc-800 dark:text-zinc-100"><option value="">Choose a cause</option>${options}</select><input type="text" name="details[absence_other_reasons][]" data-other-reason class="mt-2 hidden w-full rounded-lg border-zinc-300 bg-white px-3 py-3 text-base shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-green-700 dark:bg-zinc-800 dark:text-zinc-100" placeholder="Specify the other cause">`;
                    const reasonSelect = reasonLabel.querySelector('select');
                    const otherReasonInput = reasonLabel.querySelector('[data-other-reason]');
                    reasonSelect.value = reasons[index] || '';
                    otherReasonInput.value = otherReasons[index] || '';
                    const updateOtherReason = () => {
                        const isOther = reasonSelect.value === 'other';
                        otherReasonInput.classList.toggle('hidden', !isOther);
                        otherReasonInput.required = isOther;
                    };
                    reasonSelect.addEventListener('change', updateOtherReason);
                    updateOtherReason();
                    document.getElementById('absence_reasons').appendChild(reasonLabel);
                }
                updateDateSummary();
            };

            daysAbsentInput.addEventListener('input', renderAbsenceDates);
            renderAbsenceDates();
        </script>
    @endif
</x-layouts::app>
