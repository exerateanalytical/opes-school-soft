// Registers the service worker and offers Web Push subscription - but only
// when the backend actually has VAPID keys configured. Presenting an
// "Enable notifications" control that the server cannot yet complete
// (GenerateVapidKeys not yet run for this school) would be a dead button,
// and this codebase's convention throughout is an honest absence over a
// button that silently does nothing.
export async function initPushNotifications() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    const keyResponse = await fetch('/push/vapid-public-key', { credentials: 'same-origin' });
    const { publicKey } = await keyResponse.json();

    if (!publicKey) {
        return;
    }

    const registration = await navigator.serviceWorker.register('/sw.js');

    const existing = await registration.pushManager.getSubscription();

    if (existing) {
        return;
    }

    document.dispatchEvent(new CustomEvent('opes-push-available', {
        detail: {
            subscribe: () => subscribe(registration, publicKey),
        },
    }));
}

async function subscribe(registration, publicKeyB64Url) {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return;
    }

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKeyB64Url),
    });

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    await fetch('/push/subscribe', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(subscription.toJSON()),
    });
}

function urlBase64ToUint8Array(base64UrlString) {
    const padding = '='.repeat((4 - (base64UrlString.length % 4)) % 4);
    const base64 = (base64UrlString + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const output = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i++) {
        output[i] = rawData.charCodeAt(i);
    }

    return output;
}
