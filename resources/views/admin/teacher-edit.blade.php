<x-layouts::app :title="__('Edit Teacher Account')">
    <main class="min-h-screen bg-[#f6f5f1] p-5 text-slate-900 dark:bg-[#111712] dark:text-zinc-100 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-3xl">
            <a href="{{ route('admin.users.index') }}" class="text-sm font-semibold text-green-700 hover:underline dark:text-green-300">← Back to Manage Users</a>
            <section class="mt-5 rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h1 class="text-2xl font-bold">Edit Teacher Account</h1>
                <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">You can correct the teacher’s details until their first sign-in. Changing the email address sends a new verification link.</p>
                @if ($errors->any())
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
                @endif
                <form method="POST" action="{{ route('admin.teachers.update', $user) }}" class="mt-6 grid gap-4 md:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <label><span class="mb-2 block text-sm font-semibold">First name</span><input name="first_name" value="{{ old('first_name', $firstName) }}" required maxlength="100" class="w-full rounded-lg border-neutral-300 px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800"></label>
                    <label><span class="mb-2 block text-sm font-semibold">Last name</span><input name="last_name" value="{{ old('last_name', $lastName) }}" required maxlength="100" class="w-full rounded-lg border-neutral-300 px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800"></label>
                    <label><span class="mb-2 block text-sm font-semibold">Middle initial</span><input name="middle_initial" value="{{ old('middle_initial', $middleInitial) }}" required maxlength="2" class="w-full rounded-lg border-neutral-300 px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800"></label>
                    <label><span class="mb-2 block text-sm font-semibold">Teacher ID</span><input name="teacher_id" value="{{ old('teacher_id', $user->id_number) }}" required maxlength="255" class="w-full rounded-lg border-neutral-300 px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800"></label>
                    <label class="md:col-span-2"><span class="mb-2 block text-sm font-semibold">Gmail</span><input name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" class="w-full rounded-lg border-neutral-300 px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800"></label>
                    <label class="md:col-span-2"><span class="mb-2 block text-sm font-semibold">Counselor password</span><input name="counselor_password" type="password" required autocomplete="current-password" class="w-full rounded-lg border-neutral-300 px-3 py-2.5 dark:border-neutral-600 dark:bg-neutral-800"><span class="mt-1 block text-xs text-neutral-500">Required to authorize this change.</span></label>
                    <button type="submit" class="md:col-span-2 rounded-lg bg-[#09ba44] px-5 py-2.5 font-semibold text-[#092010] hover:bg-[#079b38]">Save teacher information</button>
                </form>
            </section>
        </div>
    </main>
</x-layouts::app>
