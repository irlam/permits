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

// Show an install action only when the current browser can complete it.
let deferredInstallPrompt = null;

function initializeInstallButton() {
  const button = document.getElementById('installButton');
  if (!button) return;

  const standalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  const isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');

  button.hidden = standalone || !isIos;

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    button.hidden = false;
  });

  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    button.hidden = true;
  });

  button.addEventListener('click', async () => {
    if (deferredInstallPrompt) {
      deferredInstallPrompt.prompt();
      await deferredInstallPrompt.userChoice;
      deferredInstallPrompt = null;
      button.hidden = true;
      return;
    }

    if (isIos) {
      window.alert('To install: tap Share, then choose “Add to Home Screen”.');
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeInstallButton);
} else {
  initializeInstallButton();
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

// Toast notification system for user feedback
window.showToast = function(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
    color: white;
    padding: 16px 24px;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    z-index: 9999;
    animation: slideInRight 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 15px;
    font-weight: 500;
    max-width: 400px;
  `;
  
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.animation = 'slideOutRight 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
};

// Add CSS for toast animations
if (!document.getElementById('toast-styles')) {
  const style = document.createElement('style');
  style.id = 'toast-styles';
  style.textContent = `
    @keyframes slideInRight {
      from {
        transform: translateX(400px);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }
    @keyframes slideOutRight {
      from {
        transform: translateX(0);
        opacity: 1;
      }
      to {
        transform: translateX(400px);
        opacity: 0;
      }
    }
  `;
  document.head.appendChild(style);
}

// Enhanced form submission handler with loading states
function handleFormSubmit(formElement, callback) {
  const submitBtn = formElement.querySelector('button[type="submit"]');
  const originalText = submitBtn?.textContent || 'Submit';
  
  formElement.addEventListener('submit', async (e) => {
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Loading...';
      submitBtn.style.opacity = '0.6';
    }
    
    try {
      await callback(e);
    } catch (error) {
      console.error('Form submission error:', error);
      window.showToast('An error occurred. Please try again.', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        submitBtn.style.opacity = '1';
      }
    }
  });
}

// Add smooth scroll behavior
document.documentElement.style.scrollBehavior = 'smooth';

// Add entrance animations to elements on page load
document.addEventListener('DOMContentLoaded', () => {
  const animatedElements = document.querySelectorAll('.hero-stat, .panel, .card');
  animatedElements.forEach((el, index) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    setTimeout(() => {
      el.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    }, 100 * index);
  });
});

// Push Notification Functions
function getCsrfToken() {
  const tokenElement = document.querySelector('meta[name="csrf-token"]');
  return tokenElement ? tokenElement.getAttribute('content') : '';
}

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
        'X-CSRF-TOKEN': getCsrfToken(),
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
        'X-CSRF-TOKEN': getCsrfToken(),
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

// Phase 2: make the public permit picker quicker to scan on site.
const permitPickerGroups = [
  {
    key: 'high-risk',
    title: 'Core & High Risk',
    icon: '⚠️',
    description: 'General PTW and specialist permits for higher-risk work.',
    keywords: ['general permit', 'hot works', 'confined space', 'demolition', 'blasting', 'restricted area']
  },
  {
    key: 'electrical-isolation',
    title: 'Electrical & Isolation',
    icon: '⚡',
    description: 'Electrical safety, isolation and lockout/tagout controls.',
    keywords: ['electrical isolation', 'electrical work', 'lockout/tagout', 'lockout tagout']
  },
  {
    key: 'groundworks',
    title: 'Groundworks & Temporary Works',
    icon: '⛏️',
    description: 'Excavation, temporary works and structural preparation activities.',
    keywords: ['permit to dig', 'excavation', 'temporary works', 'concrete pouring']
  },
  {
    key: 'height-lifting',
    title: 'Work at Height & Lifting',
    icon: '🏗️',
    description: 'Access, scaffold, roof, lifting and fall-risk activities.',
    keywords: ['working at height', 'work at height', 'roof access', 'roof work', 'scaffolding', 'lifting operations', 'crane/lifting']
  },
  {
    key: 'hazard-environment',
    title: 'Hazardous Materials & Environment',
    icon: '☣️',
    description: 'Asbestos, substances, environmental, noise and discharge controls.',
    keywords: ['asbestos', 'hazardous substances', 'hazardous materials', 'environmental', 'noise & vibration', 'noise vibration', 'water discharge']
  },
  {
    key: 'traffic-access-inspection',
    title: 'Traffic, Access & Inspections',
    icon: '🚧',
    description: 'Traffic interfaces, controlled access and inspection checklists.',
    keywords: ['traffic management', 'road/traffic', 'vehicle / equipment access', 'vehicle equipment access', 'building inspection', 'final inspection', 'site safety inspection']
  }
];

const permitPickerIconRules = [
  { keywords: ['hot works', 'welding'], icon: '🔥' },
  { keywords: ['permit to dig', 'excavation'], icon: '⛏️' },
  { keywords: ['working at height', 'work at height'], icon: '🪜' },
  { keywords: ['roof access', 'roof work'], icon: '🏠' },
  { keywords: ['confined space'], icon: '🕳️' },
  { keywords: ['electrical isolation', 'electrical work'], icon: '⚡' },
  { keywords: ['lockout/tagout', 'lockout tagout'], icon: '🔒' },
  { keywords: ['asbestos'], icon: '☣️' },
  { keywords: ['hazardous substances', 'hazardous materials'], icon: '🧪' },
  { keywords: ['environmental'], icon: '🌿' },
  { keywords: ['noise & vibration', 'noise vibration'], icon: '🔊' },
  { keywords: ['water discharge'], icon: '💧' },
  { keywords: ['lifting operations', 'crane/lifting'], icon: '🏗️' },
  { keywords: ['scaffolding'], icon: '🏗️' },
  { keywords: ['temporary works'], icon: '🧱' },
  { keywords: ['demolition'], icon: '🧱' },
  { keywords: ['concrete pouring'], icon: '🏗️' },
  { keywords: ['traffic management', 'road/traffic'], icon: '🚧' },
  { keywords: ['vehicle / equipment access', 'vehicle equipment access'], icon: '🚚' },
  { keywords: ['restricted area'], icon: '⛔' },
  { keywords: ['blasting'], icon: '💥' },
  { keywords: ['building inspection', 'final inspection', 'site safety inspection'], icon: '🔎' },
  { keywords: ['general permit'], icon: '📋' }
];

function permitPickerNormalise(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();
}

function permitPickerFindGroup(name) {
  const normalized = permitPickerNormalise(name);
  return permitPickerGroups.find(group => group.keywords.some(keyword => normalized.includes(keyword))) || {
    key: 'other',
    title: 'Other Permits',
    icon: '📁',
    description: 'Additional specialist templates available on this site.',
    keywords: []
  };
}

function permitPickerFindIcon(name) {
  const normalized = permitPickerNormalise(name);
  const rule = permitPickerIconRules.find(candidate => candidate.keywords.some(keyword => normalized.includes(keyword)));
  return rule ? rule.icon : '📄';
}

function permitPickerAddStyles() {
  if (document.getElementById('permit-picker-phase2-styles')) return;

  const style = document.createElement('style');
  style.id = 'permit-picker-phase2-styles';
  style.textContent = `
    .permit-template-group {
      grid-column: 1 / -1;
      display: grid;
      gap: 12px;
      min-width: 0;
    }
    .permit-template-group + .permit-template-group {
      margin-top: 10px;
      padding-top: 18px;
      border-top: 1px solid rgba(148, 163, 184, 0.16);
    }
    .permit-template-group__header {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 10px;
      align-items: start;
    }
    .permit-template-group__icon {
      font-size: 22px;
      line-height: 1.2;
    }
    .permit-template-group__title {
      margin: 0;
      font-size: 17px;
      font-weight: 700;
      color: #f8fafc;
    }
    .permit-template-group__description {
      margin: 3px 0 0;
      font-size: 13px;
      line-height: 1.45;
      color: rgba(148, 163, 184, 0.9);
    }
    .permit-template-group__grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
    }
    .template-modal__body .permit-template-group__grid {
      grid-template-columns: 1fr;
    }
    .template-modal__body .permit-template-group + .permit-template-group {
      margin-top: 2px;
      padding-top: 14px;
    }
    .template-tile[data-permit-category] .template-tile__icon {
      line-height: 1;
    }
    @media (max-width: 600px) {
      .permit-template-group__grid {
        grid-template-columns: 1fr;
      }
    }
  `;
  document.head.appendChild(style);
}

function permitPickerEnhanceContainer(container) {
  if (!container || container.dataset.permitPickerEnhanced === 'true') return;

  const tiles = Array.from(container.children).filter(child => child.classList && child.classList.contains('template-tile'));
  if (tiles.length === 0) return;

  const groupedTiles = new Map();
  const groupMetadata = new Map();

  tiles.forEach(tile => {
    const nameElement = tile.querySelector('.template-tile__name');
    const iconElement = tile.querySelector('.template-tile__icon');
    const name = nameElement ? nameElement.textContent.trim() : tile.textContent.trim();
    const group = permitPickerFindGroup(name);

    tile.dataset.permitCategory = group.key;
    if (iconElement) {
      iconElement.textContent = permitPickerFindIcon(name);
      iconElement.setAttribute('aria-hidden', 'true');
    }

    if (!groupedTiles.has(group.key)) {
      groupedTiles.set(group.key, []);
      groupMetadata.set(group.key, group);
    }
    groupedTiles.get(group.key).push(tile);
  });

  const desiredOrder = permitPickerGroups.map(group => group.key).concat('other');
  const fragment = document.createDocumentFragment();

  desiredOrder.forEach(groupKey => {
    const groupTiles = groupedTiles.get(groupKey);
    if (!groupTiles || groupTiles.length === 0) return;

    const group = groupMetadata.get(groupKey);
    const section = document.createElement('section');
    section.className = 'permit-template-group';
    section.dataset.permitGroup = group.key;

    const header = document.createElement('header');
    header.className = 'permit-template-group__header';

    const groupIcon = document.createElement('span');
    groupIcon.className = 'permit-template-group__icon';
    groupIcon.textContent = group.icon;
    groupIcon.setAttribute('aria-hidden', 'true');

    const headingWrap = document.createElement('div');
    const heading = document.createElement('h3');
    heading.className = 'permit-template-group__title';
    heading.textContent = group.title;

    const description = document.createElement('p');
    description.className = 'permit-template-group__description';
    description.textContent = group.description;

    headingWrap.appendChild(heading);
    headingWrap.appendChild(description);
    header.appendChild(groupIcon);
    header.appendChild(headingWrap);

    const grid = document.createElement('div');
    grid.className = 'permit-template-group__grid';
    grid.setAttribute('role', 'list');
    groupTiles.forEach(tile => grid.appendChild(tile));

    section.appendChild(header);
    section.appendChild(grid);
    fragment.appendChild(section);
  });

  container.appendChild(fragment);
  container.dataset.permitPickerEnhanced = 'true';
}

function initializePermitPickerPresentation() {
  permitPickerAddStyles();
  permitPickerEnhanceContainer(document.querySelector('#templates .template-grid'));
  permitPickerEnhanceContainer(document.querySelector('#templateModal .template-modal__body'));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializePermitPickerPresentation);
} else {
  initializePermitPickerPresentation();
}
