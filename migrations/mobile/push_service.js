// ============================================================
//  FILE: mobile/push_service.js
//
//  Push notification service for mobile apps.
//
//  Handles registration, permissions, and receiving push notifications.
//
//  Dependencies: Service Worker, Push API support
// ============================================================

class PushService {
    constructor() {
        this.registration = null;
        this.vapidPublicKey = 'YOUR_VAPID_PUBLIC_KEY'; // From server config
    }

    async init() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Push notifications not supported');
            return false;
        }

        try {
            this.registration = await navigator.serviceWorker.register('/sw.js');
            console.log('Service Worker registered');
            return true;
        } catch (error) {
            console.error('Service Worker registration failed:', error);
            return false;
        }
    }

    async requestPermission() {
        const permission = await Notification.requestPermission();
        return permission === 'granted';
    }

    async subscribe() {
        if (!this.registration) return null;

        try {
            const subscription = await this.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
            });

            // Send subscription to server
            await this.sendSubscriptionToServer(subscription);

            return subscription;
        } catch (error) {
            console.error('Push subscription failed:', error);
            return null;
        }
    }

    async sendSubscriptionToServer(subscription) {
        const response = await fetch('/api/notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + localStorage.getItem('authToken')
            },
            body: JSON.stringify({
                action: 'subscribe_push',
                subscription: subscription.toJSON()
            })
        });

        if (!response.ok) {
            throw new Error('Failed to send subscription to server');
        }
    }

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    async unsubscribe() {
        const subscription = await this.registration.pushManager.getSubscription();
        if (subscription) {
            await subscription.unsubscribe();
            await this.removeSubscriptionFromServer(subscription);
        }
    }

    async removeSubscriptionFromServer(subscription) {
        await fetch('/api/notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + localStorage.getItem('authToken')
            },
            body: JSON.stringify({
                action: 'unsubscribe_push',
                subscription: subscription.toJSON()
            })
        });
    }
}

// Service Worker (sw.js) - needs to be in root
/*
self.addEventListener('push', function(event) {
    const data = event.data.json();
    const options = {
        body: data.body,
        icon: '/icon.png',
        badge: '/badge.png'
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
        clients.openWindow('/player/dashboard.php')
    );
});
*/

// Usage:
/*
const pushService = new PushService();

async function setupPush() {
    const initialized = await pushService.init();
    if (!initialized) return;

    const permitted = await pushService.requestPermission();
    if (permitted) {
        await pushService.subscribe();
    }
}

setupPush();
*/