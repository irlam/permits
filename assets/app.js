/**
 * Main JavaScript file for Permits System
 * 
 * Description: Registers service worker for PWA functionality and provides utility functions
 * Name: app.js
 */

// Utility: Debounce function for performance optimization
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

// Register service worker if browser supports it
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then(registration => {
        console.log('[App] Service Worker registered successfully');
        
        // Check for updates periodically (every 5 minutes)
        setInterval(() => {
          registration.update();
        }, 300000);
        
        // Initialize push notifications if supported
        if ('PushManager' in window) {
          initializePushNotifications(registration);
        }
        
      })
      .catch(error => {
        console.error('[App] Service Worker registration failed:', error);
      });
  });
}

// Add global error handler for better debugging
window.addEventListener('error', (event) => {
  console.error('[App] Unhandled error:', event.error);
});

// Add unhandled promise rejection handler
window.addEventListener('unhandledrejection', (event) => {
  console.error('[App] Unhandled promise rejection:', event.reason);
});

// Push Notification Functions
async function initializePushNotifications(registration) {
  try {
    // Check if notifications are supported
    if (!('Notification' in window)) {
      console.log('[Push] Notifications not supported');
      return;
    }

    // Check current permission
    if (Notification.permission === 'denied') {
      console.log('[Push] Notification permission denied');
      return;
    }

    // Check if already subscribed
    const existingSubscription = await registration.pushManager.getSubscription();
    if (existingSubscription) {
      console.log('[Push] Already subscribed to push notifications');
      return;
    }

    // Auto-subscribe if permission already granted
    if (Notification.permission === 'granted') {
      await subscribeToPushNotifications(registration);
    }
  } catch (error) {
    console.error('[Push] Error initializing push notifications:', error);
  }
}

async function subscribeToPushNotifications(registration) {
  try {
    // Get VAPID public key from the page (should be set by backend)
    const vapidPublicKey = window.VAPID_PUBLIC_KEY || null;
    if (!vapidPublicKey) {
      console.error('[Push] VAPID public key not configured');
      return null;
    }

    // Convert VAPID key to Uint8Array
    const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);

    // Subscribe to push notifications
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: convertedVapidKey
    });

    console.log('[Push] Push notification subscription successful');

    // Send subscription to server
    const response = await fetch('/api/push/subscribe.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(subscription.toJSON())
    });

    if (!response.ok) {
      throw new Error(`Subscription failed: ${response.statusText}`);
    }

    const data = await response.json();
    console.log('[Push] Subscription saved to server:', data);
    return subscription;

  } catch (error) {
    console.error('[Push] Failed to subscribe to push notifications:', error);
    return null;
  }
}

async function unsubscribeFromPushNotifications() {
  try {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    
    if (!subscription) {
      console.log('[Push] No active subscription to unsubscribe from');
      return;
    }

    // Unsubscribe from browser
    await subscription.unsubscribe();
    console.log('[Push] Unsubscribed from push notifications');

    // Notify server
    await fetch('/api/push/unsubscribe.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(subscription.toJSON())
    });

    console.log('[Push] Subscription removed from server');

  } catch (error) {
    console.error('[Push] Failed to unsubscribe from push notifications:', error);
  }
}

// Helper function to convert VAPID key
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding)
    .replace(/\-/g, '+')
    .replace(/_/g, '/');

  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

// Expose push notification functions globally for manual triggering
window.subscribeToPush = async function() {
  try {
    // Request notification permission
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      console.log('[Push] Notification permission not granted');
      return;
    }

    const registration = await navigator.serviceWorker.ready;
    await subscribeToPushNotifications(registration);
    alert('Successfully subscribed to push notifications!');
  } catch (error) {
    console.error('[Push] Subscription error:', error);
    alert('Failed to subscribe to push notifications. Check console for details.');
  }
};

window.unsubscribeFromPush = unsubscribeFromPushNotifications;

// Prevent double form submission
document.addEventListener('DOMContentLoaded', () => {
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    form.addEventListener('submit', function(e) {
      const submitButton = this.querySelector('button[type="submit"]');
      if (submitButton && !submitButton.disabled) {
        // Disable button and add loading state
        submitButton.disabled = true;
        submitButton.dataset.originalText = submitButton.textContent;
        submitButton.textContent = 'Processing...';
        
        // Re-enable after a timeout as fallback
        setTimeout(() => {
          submitButton.disabled = false;
          submitButton.textContent = submitButton.dataset.originalText;
        }, 5000);
      }
    });
  });
});