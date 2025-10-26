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