/**
 * Permits System - Main JavaScript Application
 * 
 * Description: Core client-side JavaScript for PWA functionality and service worker management
 * Name: app.js
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Register service worker for Progressive Web App (PWA) capabilities
 * - Enable offline functionality and asset caching
 * - Handle service worker updates and version changes
 * - Auto-reload page when new version is available
 * - Provide user notifications for updates
 * 
 * Features:
 * - Automatic service worker registration on page load
 * - Periodic update checks every 60 seconds
 * - User-friendly update prompts
 * - Seamless version transitions
 * - Error handling and logging
 */

// Register service worker if browser supports it
if ('serviceWorker' in navigator) {
  // Wait for page to load before registering
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then(registration => {
        console.log('[App] Service Worker registered successfully');
        
        // Check for updates every 60 seconds
        setInterval(() => {
          registration.update();
        }, 60000);
        
        // Listen for updates
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              // New service worker is available!
              console.log('[App] New version available! Reloading page...');
              
              // Show user notification (optional)
              if (confirm('🎉 A new version is available! Click OK to update.')) {
                newWorker.postMessage({ type: 'SKIP_WAITING' });
                window.location.reload();
              }
            }
          });
        });
        
        // Reload page when new service worker takes control
        navigator.serviceWorker.addEventListener('controllerchange', () => {
          console.log('[App] New service worker activated, reloading page');
          window.location.reload();
        });
      })
      .catch(error => {
        console.log('[App] Service Worker registration failed:', error);
      });
  });
}