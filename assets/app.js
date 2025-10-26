/**
 * Main JavaScript file for Permits System
 * 
 * Description: Registers service worker for PWA functionality
 * Name: app.js
 */

// Register service worker if browser supports it
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then(registration => {
        console.log('[App] Service Worker registered successfully');
        
        // Check for updates periodically (but not too often)
        setInterval(() => {
          registration.update();
        }, 300000); // Every 5 minutes instead of 1 minute
        
      })
      .catch(error => {
        console.log('[App] Service Worker registration failed:', error);
      });
  });
}