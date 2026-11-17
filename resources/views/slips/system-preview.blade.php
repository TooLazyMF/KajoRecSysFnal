<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $slip['title'] }} — System Preview</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 p-4 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 sm:p-6">
    <main class="mx-auto max-w-5xl">
        <h1 class="mb-4 text-xl font-bold">{{ $slip['title'] }}</h1>
        @include('forms.system-preview', ['slip' => $slip, 'previewData' => $previewData])
    </main>
</body>
</html>
