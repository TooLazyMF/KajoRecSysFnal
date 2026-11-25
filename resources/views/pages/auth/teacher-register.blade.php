<x-layouts::auth :title="__('Request a teacher account')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Request a teacher account')"
            :description="__('Submit your details for guidance counselor review')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <p class="text-sm text-zinc-600 dark:text-zinc-300">
            Select your name from the imported teacher list, upload a photo of your teacher ID, and verify your email. The counselor must approve your request before you can sign in. The active ID photo is stored encrypted and deleted after the counselor decides; encrypted copies in backups made while your request is pending may remain under the system's backup retention.
        </p>

        @unless (\App\Models\RosterImportState::isImported(\App\Models\RosterImportState::TEACHERS))
            <p role="status" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                Teacher registration is unavailable until the guidance counselor imports the teacher list.
            </p>
        @elseif ($teacherReferences->isEmpty())
            <p role="status" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                The imported teacher list is empty. Contact the guidance counselor.
            </p>
        @endunless

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('teacher.register.store') }}" enctype="multipart/form-data" class="flex flex-col gap-5">
            @csrf

            <label class="flex flex-col gap-2 text-sm font-medium">
                Teacher name
                <select name="teacher_name" required @if ($teacherReferences->isEmpty()) disabled @endif class="rounded-lg border-neutral-300 bg-white px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800">
                    <option value="">Select your name</option>
                    @foreach ($teacherReferences as $teacherReference)
                        <option value="{{ $teacherReference->name }}" @selected(old('teacher_name') === $teacherReference->name)>{{ $teacherReference->name }}</option>
                    @endforeach
                </select>
                @error('teacher_name')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <label class="flex flex-col gap-2 text-sm font-medium">
                Teacher ID
                <input name="teacher_id" type="text" value="{{ old('teacher_id') }}" required maxlength="255" inputmode="numeric" pattern="[0-9]*" data-digits-only autocomplete="off" class="rounded-lg border-neutral-300 bg-white px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800">
                <span class="text-xs font-normal text-neutral-500">Digits only.</span>
                @error('teacher_id')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <label class="flex flex-col gap-2 text-sm font-medium">
                Email address
                <input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" class="rounded-lg border-neutral-300 bg-white px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800">
                @error('email')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <label class="flex flex-col gap-2 text-sm font-medium">
                Photo of teacher ID
                <input name="id_photo" type="file" accept="image/jpeg,image/png,image/webp" required class="rounded-lg border border-neutral-300 bg-white px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800">
                <span class="text-xs font-normal text-neutral-500">JPG, PNG, or WebP; maximum 5 MB.</span>
                @error('id_photo')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <flux:input
                name="password"
                :label="__('Create a password')"
                type="password"
                required
                minlength="8"
                pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+"
                title="Use letters and numbers only, with at least one of each."
                autocomplete="new-password"
                viewable
            />
            @error('password')<span class="-mt-4 text-sm text-red-600">{{ $message }}</span>@enderror

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                minlength="8"
                pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+"
                title="Use letters and numbers only, with at least one of each."
                autocomplete="new-password"
                viewable
            />
            @error('password_confirmation')<span class="-mt-4 text-sm text-red-600">{{ $message }}</span>@enderror

            <flux:button type="submit" variant="primary" class="w-full" :disabled="$teacherReferences->isEmpty()">
                {{ __('Submit account request') }}
            </flux:button>
        </form>

        <details class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
            <summary class="cursor-pointer text-sm font-semibold">Need another verification email?</summary>
            <form method="POST" action="{{ route('teacher.register.resend-verification') }}" class="mt-4 flex flex-col gap-3">
                @csrf
                <label class="flex flex-col gap-2 text-sm font-medium">
                    Email address used for your request
                    <input name="email" type="email" required autocomplete="email" class="rounded-lg border-neutral-300 bg-white px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800">
                </label>
                <button type="submit" class="self-start rounded-lg border border-neutral-300 px-4 py-2 text-sm font-semibold dark:border-neutral-600">
                    Resend verification email
                </button>
            </form>
        </details>

        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            Already have an account?
            <flux:link :href="route('login')">{{ __('Sign in') }}</flux:link>
        </p>
    </div>
</x-layouts::auth>
