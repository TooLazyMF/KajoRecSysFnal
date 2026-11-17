<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slip Information — System Preview</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 p-4 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 sm:p-6">
    <main class="mx-auto max-w-3xl rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold">Slip Information</h1>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">System view-only preview</p>
            </div>
            <span class="rounded-full bg-zinc-200 px-2.5 py-1 text-[10px] font-semibold uppercase text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">Read only</span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                'full_name' => 'Full Name', 'last_name' => 'Last Name', 'first_name' => 'First Name',
                'middle_initial' => 'Middle Initial', 'student_id' => 'Student ID', 'email' => 'Email',
                'phone' => 'Phone', 'degree' => 'Degree Program', 'year_level' => 'Year Level',
                'school_year' => 'School Year', 'purpose' => 'Purpose', 'purpose_other' => 'Other Purpose',
            ] as $key => $label)
                <div>
                    <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $label }}</p>
                    <div class="mt-1 min-h-9 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">{{ filled($slipInformation->{$key}) ? $slipInformation->{$key} : '—' }}</div>
                </div>
            @endforeach
        </div>
    </main>
</body>
</html>