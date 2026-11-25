<x-layouts::app :title="$slip['title']">
    <main class="min-h-screen bg-[#f6f5f1] px-4 py-8 text-zinc-900 transition-colors dark:bg-[#111712] dark:text-zinc-100 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="mb-6">
                <flux:heading size="xl" class="text-zinc-900 dark:text-zinc-100">{{ $slip['title'] }}</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-300">
                    @if ($isTeacherSubmittedReferral)
                        This referral was already sent directly to the Guidance Counselor by your teacher. You may review the details below.
                    @else
                        Fill out the sections intended for you. You can edit this submission until it is approved by the Guidance Office.
                    @endif
                </flux:text>
            </div>

            @if ($selectedStudent)
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-200">
                    Assigned to: <strong>{{ $selectedStudent->name }}</strong>
                    <span class="ml-2">{{ $selectedStudent->id_number }}</span>
                </div>
            @endif

            @if ($isTeacherSubmittedReferral && $existingSubmission)
                <section class="mb-6 flex flex-col gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100 sm:flex-row sm:items-center sm:justify-between">
                    @if ($referralPdfReady)
                        <p>Open or print the referral for your records. It has already been sent directly to the Guidance Counselor.</p>
                        <a href="{{ route('slips.submissions.pdf', $existingSubmission) }}" target="_blank" rel="noopener" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-blue-300 bg-white px-4 py-2 text-xs font-semibold text-blue-800 hover:bg-blue-100 dark:border-blue-800 dark:bg-zinc-900 dark:text-blue-200 dark:hover:bg-zinc-800">
                            Download original teacher-issued referral PDF
                        </a>
                    @else
                        <p>The referral PDF is not printable yet. Guidance can still receive your referral and issue your Class Admission Slip; the PDF will appear here after the document-control details are issued.</p>
                    @endif
                </section>
                @unless ($referralPdfReady)
                    <iframe
                        src="{{ route('slips.submissions.system-preview', $existingSubmission) }}"
                        title="Referral form preview"
                        class="h-[80vh] w-full rounded-xl border border-blue-200 bg-white dark:border-blue-900"
                    ></iframe>
                @endunless
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">
                    <p class="font-medium">Please correct the following:</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($isStudentRequest && $assignment->assigned_by === auth()->id() && $assignment->status === 'pending' && !$existingSubmission)
                <form
                    id="cancel-student-slip-request"
                    method="POST"
                    action="{{ route('assigned-slips.cancel-request', $assignment) }}"
                    onsubmit="return confirm('Cancel this form request? It will be removed from your pending slips.')"
                >
                    @csrf
                </form>
            @endif

            <form method="POST" action="{{ route('assigned-slips.submit', $assignment) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @if ($selectedStudent)
                    <input type="hidden" name="referred_student_id" value="{{ $selectedStudent->id }}">
                @endif

                @unless ($isTeacherSubmittedReferral)
                @if ($assignment->slip === 'class-admission-slip')
                    <section class="space-y-5 rounded-2xl border border-emerald-100 bg-white p-6 shadow-[0_8px_24px_rgba(15,163,68,0.07)] dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">Class Admission Details</flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-300">The referral and your Client Information Form automatically provide the student details below. You only need to explain your excuse and attach proof if available.</flux:text>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach (['student_name' => 'Student Name', 'student_id' => 'Student ID', 'sex' => 'Sex', 'program_year' => 'Program and Year', 'date_absent' => 'Date/s Absent or Tardy', 'days_absent' => 'Number of Days'] as $key => $label)
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                                    <p class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ is_array($defaults[$key] ?? null) ? implode(', ', $defaults[$key]) : ($defaults[$key] ?? 'Not provided') }}</p>
                                </div>
                            @endforeach
                        </div>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Your excuse or explanation *</span>
                            <textarea name="details[student_excuse]" rows="6" maxlength="4000" required class="w-full rounded-lg border-zinc-300 bg-white text-sm text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">{{ old('details.student_excuse', $existingSubmission?->data['student_excuse'] ?? '') }}</textarea>
                        </label>
                    </section>
                @else
                    @if (!empty($slip['prefilled_fields']))
                    <section class="space-y-4 rounded-2xl border border-emerald-100 bg-white p-6 shadow-[0_8px_24px_rgba(15,163,68,0.07)] dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">From your Teacher Referral Slip</flux:heading>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($slip['prefilled_fields'] as $field)
                                <div>
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $field['label'] }}</p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ $defaults[$field['key']] ?? 'Not provided on the referral slip' }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                    @endif

                    @foreach ($slip['groups'] as $group)
                    <section class="space-y-5 rounded-2xl border border-emerald-100 bg-white p-6 shadow-[0_8px_24px_rgba(15,163,68,0.07)] dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">{{ $group['title'] }}</flux:heading>

                        @php($isCif = $assignment->slip === 'qf-bo-oss-04-client-information-form')
                        <div class="grid gap-5 {{ $isCif && $group['title'] === 'Personal Information' ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                            @foreach ($group['fields'] as $field)
                                @php($cifGridClass = $isCif && $group['title'] === 'Personal Information' ? match ($field['key']) {
                                    'last_name', 'first_name', 'middle_name', 'contact_number', 'birth_date', 'sex', 'civil_status', 'nationality', 'birth_place', 'email' => 'sm:col-span-1',
                                    'student_id', 'languages', 'address', 'disability', 'indigenous_group', 'pwd', 'student_parent', 'financial_sources' => 'sm:col-span-3',
                                    default => '',
                                } : '')
                                @if ($isCif && $group['title'] === 'Personal Information' && $field['key'] === 'course_year')
                                    @php($courseValue = old('details.course', $existingSubmission?->data['course'] ?? $defaults['course'] ?? ''))
                                    @php($yearValue = old('details.year_level', $existingSubmission?->data['year_level'] ?? $defaults['year_level'] ?? ''))
                                    <div>
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Course *</span>
                                            <select id="course" name="details[course]" required class="h-10 w-full rounded-lg border-zinc-300 bg-white px-3 text-sm text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                                <option value="">Choose a course</option>
                                                @foreach (\App\Support\SlipCatalog::courseOptions() as $value => $label)
                                                    <option value="{{ $value }}" @selected($courseValue === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Year Level *</span>
                                            <select id="year_level" name="details[year_level]" required class="h-10 w-full rounded-lg border-zinc-300 bg-white px-3 text-sm text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                                <option value="">Choose year level</option>
                                                @foreach (\App\Support\SlipCatalog::yearLevelOptions() as $value => $label)
                                                    <option value="{{ $value }}" @selected($yearValue === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                    <input id="course_year" type="hidden" name="details[course_year]" value="{{ old('details.course_year', $existingSubmission?->data['course_year'] ?? $defaults['course_year'] ?? trim(implode(' ', array_filter([$courseValue, $yearValue])))) }}" readonly>
                                @else
                                <div class="{{ $cifGridClass ?: (in_array($field['type'], ['textarea', 'checkboxes'], true) ? 'sm:col-span-2' : '') }}">
                                    @if ($isCif && $group['title'] === 'Personal Information' && $field['key'] === 'college')
                                        @php($collegeValue = old('details.college', $existingSubmission?->data['college'] ?? $defaults['college'] ?? ''))
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">College of *</span>
                                            <select id="college" name="details[college]" disabled class="h-10 w-full rounded-lg border-zinc-300 bg-zinc-100 px-3 text-sm text-zinc-900 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                                <option value="">Select a course first</option>
                                                @foreach (\App\Support\SlipCatalog::collegeOptions() as $value => $label)
                                                    <option value="{{ $value }}" @selected($collegeValue === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <input id="college_value" type="hidden" name="details[college]" value="{{ $collegeValue }}">
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Automatically selected from the Course.</p>
                                        </label>
                                    @elseif ($isCif && in_array($field['key'], ['contact_number', 'father_contact', 'mother_contact', 'guardian_contact'], true))
                                        @php($fieldValue = old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? $defaults[$field['key']] ?? null))
                                        <flux:input
                                            id="{{ $field['key'] }}"
                                            name="details[{{ $field['key'] }}]"
                                            type="tel"
                                            inputmode="numeric"
                                            maxlength="11"
                                            pattern="[0-9]{11}"
                                            data-digits-only
                                            label="{{ $field['label'] }}"
                                            value="{{ $fieldValue }}"
                                            :required="$field['key'] === 'contact_number'"
                                            :readonly="filled($defaults['contact_number'] ?? null)"
                                            data-cif-contact
                                        />
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Enter 11 digits only.</p>
                                    @elseif ($isCif && $field['key'] === 'parent_marital_status')
                                        @php($fieldValue = old('details.parent_marital_status', $existingSubmission?->data['parent_marital_status'] ?? ''))
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $field['label'] }}</span>
                                            <select name="details[parent_marital_status]" class="h-10 w-full rounded-lg border-zinc-300 bg-white px-3 text-sm text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                                <option value="">Choose an option</option>
                                                @foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced'] as $option)
                                                    <option value="{{ $option }}" @selected(strtolower((string) $fieldValue) === strtolower($option))>{{ $option }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @elseif ($isCif && in_array($field['key'], ['father_age', 'mother_age'], true))
                                        <flux:input
                                            id="{{ $field['key'] }}"
                                            name="details[{{ $field['key'] }}]"
                                            type="text"
                                            inputmode="numeric"
                                            pattern="[0-9]*"
                                            maxlength="3"
                                            data-digits-only
                                            label="{{ $field['label'] }}"
                                            value="{{ old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? null) }}"
                                        />
                                    @elseif ($isCif && $group['title'] === 'Personal Information' && in_array($field['key'], ['sex', 'civil_status'], true))
                                        @php($fieldValue = old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? $defaults[$field['key']] ?? null))
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $field['label'] }}</span>
                                            <select
                                                id="{{ $field['key'] }}"
                                                name="details[{{ $field['key'] }}]"
                                                class="h-10 w-full rounded-lg border-zinc-300 bg-white px-3 text-sm text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                            >
                                                <option value="">Choose an option</option>
                                                @php($options = $field['key'] === 'sex' ? ['Male', 'Female'] : ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'])
                                                @foreach ($options as $option)
                                                    <option value="{{ $option }}" @selected(strtolower((string) $fieldValue) === strtolower($option))>{{ $option }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @elseif (!empty($field['readonly']))
                                        <div>
                                            <flux:input
                                                id="{{ $field['key'] }}"
                                                label="{{ $field['label'] }}"
                                                value="{{ $defaults[$field['key']] ?? '' }}"
                                                readonly
                                                class="bg-zinc-100 dark:bg-zinc-800"
                                            />
                                            <input type="hidden" name="details[{{ $field['key'] }}]" value="{{ $defaults[$field['key']] ?? '' }}">
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">From your imported student record</p>
                                        </div>
                                    @elseif ($field['type'] === 'textarea')
                                        <flux:textarea
                                            name="details[{{ $field['key'] }}]"
                                            label="{{ $field['label'] }}"
                                            rows="{{ max(3, $field['rows']) }}"
                                            :required="$field['required']"
                                        >{{ old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? null) }}</flux:textarea>
                                    @elseif ($field['type'] === 'checkboxes')
                                        @php($selectedValues = old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? []))
                                        @php($selectedValues = is_array($selectedValues) ? $selectedValues : (filled($selectedValues) ? [$selectedValues] : []))
                                        <fieldset class="space-y-2">
                                            <legend class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                                {{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}
                                            </legend>
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                @foreach ($field['options'] as $value => $label)
                                                    <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                                        <input
                                                            type="{{ $field['key'] === 'referral_reasons' ? 'radio' : 'checkbox' }}"
                                                            name="details[{{ $field['key'] }}][]"
                                                            value="{{ $value }}"
                                                            @checked(in_array($value, $selectedValues, true))
                                                            @if ($field['key'] === 'referral_reasons' && $field['required']) required @endif
                                                            class="rounded border-zinc-300 text-emerald-700 focus:ring-emerald-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-emerald-400 dark:focus:ring-emerald-400"
                                                        >
                                                        <span>{{ $label }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @elseif ($field['type'] === 'select')
                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}</span>
                                            <select
                                                name="details[{{ $field['key'] }}]"
                                                @required($field['required'])
                                                class="h-10 w-full rounded-lg border-zinc-300 bg-white px-3 text-sm text-zinc-900 shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                            >
                                                <option value="">Choose an option</option>
                                                @foreach ($field['options'] as $value => $label)
                                                    <option value="{{ $value }}" @selected(old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? '') === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @elseif ($field['key'] === 'other_reason')
                                        <flux:input
                                            id="other_reason"
                                            name="details[{{ $field['key'] }}]"
                                            label="{{ $field['label'] }}"
                                            value="{{ old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? null) }}"
                                            maxlength="255"
                                            disabled
                                            readonly
                                        />
                                    @else
                                        <flux:input
                                            id="{{ $field['key'] }}"
                                            name="details[{{ $field['key'] }}]"
                                            label="{{ $field['label'] }}"
                                            type="{{ $field['type'] }}"
                                            value="{{ old('details.'.$field['key'], $existingSubmission?->data[$field['key']] ?? $defaults[$field['key']] ?? null) }}"
                                            :required="$field['required']"
                                        />
                                    @endif
                                </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                    @endforeach
                @endif
                @endunless

                @if ($assignment->slip === 'class-admission-slip' && !$isTeacherSubmittedReferral)
                    <section class="space-y-3 rounded-2xl border border-emerald-100 bg-white p-6 shadow-[0_8px_24px_rgba(15,163,68,0.07)] dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                        <div>
                            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">Supporting evidence (optional)</flux:heading>
                            <flux:text class="mt-1 text-zinc-600 dark:text-zinc-300">Attach images or documents that help explain your absence. You may select up to 5 files, up to 10 MB each.</flux:text>
                        </div>
                        <input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" class="block w-full rounded-lg border border-zinc-300 bg-white p-2 text-sm text-zinc-700 file:mr-4 file:rounded-md file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-emerald-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-100">
                        @if ($existingSubmission?->data['_attachments'] ?? false)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Existing attachments are kept when you save changes without selecting new files.</p>
                        @endif
                    </section>
                @endif

                <div class="flex justify-end gap-3">
                    @if ($isStudentRequest && $assignment->assigned_by === auth()->id() && $assignment->status === 'pending' && !$existingSubmission)
                        <button
                            type="submit"
                            form="cancel-student-slip-request"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Cancel request
                        </button>
                    @else
                        <a
                            href="{{ route('student.dashboard') }}"
                            class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Cancel
                        </a>
                    @endif
                    @if ($isTeacherSubmittedReferral)
                        <a href="{{ route('slips.submissions.show', $existingSubmission) }}" class="inline-flex items-center justify-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            View sent referral
                        </a>
                    @else
                        <flux:button variant="primary" type="submit">
                            {{ $existingSubmission ? 'Save Changes' : 'Submit' }}
                        </flux:button>
                    @endif
                </div>
            </form>
        </div>
    </main>

    @if ($assignment->slip === 'qf-bo-oss-04-client-information-form')
        <script>
            const courseCollegeMap = @json(\App\Support\SlipCatalog::courseCollegeMap());
            const courseInput = document.getElementById('course');
            const yearLevelInput = document.getElementById('year_level');
            const courseYearInput = document.getElementById('course_year');
            const collegeInput = document.getElementById('college');
            const collegeValueInput = document.getElementById('college_value');
            const contactNumberInputs = document.querySelectorAll('[data-cif-contact]');
            const syncCollegeFromCourse = () => {
                if (!courseInput || !collegeInput || !collegeValueInput) return;
                const college = courseCollegeMap[courseInput.value] || '';
                collegeInput.value = college;
                collegeValueInput.value = college;
                if (courseYearInput) {
                    courseYearInput.value = [courseInput.value, yearLevelInput?.value].filter(Boolean).join(' ');
                }
            };

            courseInput?.addEventListener('change', syncCollegeFromCourse);
            yearLevelInput?.addEventListener('change', syncCollegeFromCourse);
            syncCollegeFromCourse();

            const keepContactNumberNumeric = (input) => {
                input.value = input.value.replace(/\D/g, '').slice(0, 11);
            };
            contactNumberInputs.forEach((input) => {
                input.addEventListener('input', () => keepContactNumberNumeric(input));
                keepContactNumberNumeric(input);
            });
        </script>
    @endif

    @if ($assignment->slip === 'student-referral-slip' && !$isTeacherSubmittedReferral)
        <script>
            const referralReasonInputs = document.querySelectorAll('input[name="details[referral_reasons][]"]');
            const otherReasonInput = document.getElementById('other_reason');
            const updateOtherReasonRequirement = () => {
                const otherSelected = document.querySelector('input[name="details[referral_reasons][]"]:checked')?.value === 'other';
                if (otherReasonInput) {
                    otherReasonInput.disabled = !otherSelected;
                    otherReasonInput.readOnly = !otherSelected;
                    if (!otherSelected) otherReasonInput.value = '';
                    otherReasonInput.required = otherSelected;
                    otherReasonInput.setAttribute('aria-required', otherSelected ? 'true' : 'false');
                }
            };

            referralReasonInputs.forEach((input) => input.addEventListener('change', updateOtherReasonRequirement));
            updateOtherReasonRequirement();
        </script>
    @endif
</x-layouts::app>
