const pushRoot = document.querySelector('[data-push-notifications]');

if (pushRoot && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window) {
    const button = pushRoot.querySelector('[data-push-enable]');
    const status = pushRoot.querySelector('[data-push-status]');
    const vapidPublicKey = pushRoot.dataset.vapidPublicKey;

    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.classList.toggle('text-red-600', isError);
        status.classList.toggle('text-green-700', !isError);
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
        }
    };

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
                throw new Error('The subscription could not be saved.');
            }

            button.textContent = 'Notifications enabled';
            setStatus('Native notifications are enabled.');
        } catch (error) {
            button.disabled = false;
            setStatus(error.message || 'Unable to enable notifications.', true);
        }
    });

    syncButton().catch(() => {
        setStatus('Push notifications are unavailable in this browser.', true);
    });
} else if (pushRoot) {
    pushRoot.querySelector('[data-push-enable]').disabled = true;
    pushRoot.querySelector('[data-push-status]').textContent = 'Push notifications are unavailable in this browser.';
}
