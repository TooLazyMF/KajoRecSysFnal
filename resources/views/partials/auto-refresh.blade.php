<p class="mx-auto mt-4 max-w-7xl px-5 text-xs text-zinc-500 dark:text-zinc-400" role="status">
    This page checks for new Guidance updates every 15 seconds. Refreshing pauses while you have unsaved changes or an open dialog.
</p>
<script>
    (() => {
        let hasUnsavedChanges = false;

        // Pausing reloads protects user input and open dialogs from being discarded.
        document.addEventListener('input', (event) => {
            if (event.target.closest('form')) {
                hasUnsavedChanges = true;
            }
        });

        document.addEventListener('change', (event) => {
            if (event.target.closest('form')) {
                hasUnsavedChanges = true;
            }
        });

        window.setInterval(() => {
            if (
                document.visibilityState === 'visible'
                && !hasUnsavedChanges
                && !document.querySelector('dialog[open]')
            ) {
                window.location.reload();
            }
        }, 15000);
    })();
</script>
