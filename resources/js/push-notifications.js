const pushRoot = document.querySelector('[data-push-notifications]');

if (pushRoot && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window) {
    const openButton = pushRoot.querySelector('[data-push-open]');
    const button = pushRoot.querySelector('[data-push-enable]');
    const dialog = pushRoot.querySelector('[data-push-dialog]');
    const status = pushRoot.querySelector('[data-push-status]');
    const vapidPublicKey = pushRoot.dataset.vapidPublicKey;

    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.classList.toggle('text-red-600', isError);
        status.classList.toggle('text-emerald-700', !isError);
        status.classList.toggle('dark:text-red-400', isError);
        status.classList.toggle('dark:text-emerald-300', !isError);
    };

    const urlBase64ToUint8Array = (base64String) => {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);

        return Uint8Array.from([...rawData].map((character) => character.charCodeAt(0)));
    };

    const syncButton = async () => {
        const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        const subscription = await registration.pushManager.getSubscription();

        if (subscription) {
            button.textContent = 'Notifications enabled';
            button.disabled = true;
            setStatus('Native notifications are already enabled.');
        }
    };

    openButton.addEventListener('click', () => dialog.showModal());
    pushRoot.querySelectorAll('[data-push-close]').forEach((closeButton) => {
        closeButton.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    button.addEventListener('click', async () => {
        button.disabled = true;
        setStatus('Requesting notification permission…');

        try {
            if (!vapidPublicKey) {
                throw new Error('Web Push is not configured on the server.');
            }

            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                throw new Error('Notification permission was not granted.');
            }

            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify(subscription.toJSON()),
            });

            if (!response.ok) {
                const responseBody = await response.json().catch(() => null);
                const validationMessage = Object.values(responseBody?.errors || {}).flat()[0];

                throw new Error(validationMessage || responseBody?.message || 'The subscription could not be saved.');
            }

            button.textContent = 'Notifications enabled';
            setStatus('Native notifications are enabled.');
        } catch (error) {
            button.disabled = false;
            const message = error instanceof Error ? error.message : 'Unable to enable notifications.';

            setStatus(
                /registration failed.*push service error/i.test(message)
                    ? 'Your browser could not connect to its push service. Check browser notification settings and your connection, then retry or try another browser. In-app and email notifications are unaffected.'
                    : message,
                true,
            );
        }
    });

    syncButton().catch(() => {
        setStatus('The service worker could not be registered. Check that this site is open over HTTPS, then retry.', true);
    });
} else if (pushRoot) {
    pushRoot.querySelector('[data-push-enable]').disabled = true;
    pushRoot.querySelector('[data-push-open]').addEventListener('click', () => {
        const dialog = pushRoot.querySelector('[data-push-dialog]');
        dialog.showModal();
        pushRoot.querySelector('[data-push-status]').textContent = 'This browser does not support native push notifications. In-app and email notifications are still available.';
    });

    pushRoot.querySelectorAll('[data-push-close]').forEach((closeButton) => {
        closeButton.addEventListener('click', () => pushRoot.querySelector('[data-push-dialog]').close());
    });
}
