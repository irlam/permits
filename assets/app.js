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

/* ==================== Mobile Enhancements ==================== */

/**
 * Mobile Menu Toggle
 * Handles hamburger menu for mobile navigation
 */
function initMobileMenu() {
  const menuToggle = document.querySelector('.mobile-menu-toggle');
  const mobileMenu = document.querySelector('.mobile-menu');
  
  if (menuToggle && mobileMenu) {
    menuToggle.addEventListener('click', (e) => {
      e.preventDefault();
      mobileMenu.classList.toggle('active');
      menuToggle.setAttribute('aria-expanded', 
        menuToggle.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'
      );
    });
    
    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
      if (!mobileMenu.contains(e.target) && !menuToggle.contains(e.target)) {
        mobileMenu.classList.remove('active');
        menuToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }
}

/**
 * Swipe Gesture Support
 * Adds swipe left/right gesture detection for mobile
 */
function initSwipeGestures() {
  let touchStartX = 0;
  let touchEndX = 0;
  const minSwipeDistance = 50; // Minimum distance for a swipe
  
  document.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
  }, { passive: true });
  
  document.addEventListener('touchend', (e) => {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
  }, { passive: true });
  
  function handleSwipe() {
    const swipeDistance = touchEndX - touchStartX;
    
    if (Math.abs(swipeDistance) > minSwipeDistance) {
      if (swipeDistance > 0) {
        // Swipe right
        handleSwipeRight();
      } else {
        // Swipe left
        handleSwipeLeft();
      }
    }
  }
  
  function handleSwipeRight() {
    // Custom swipe right action - e.g., open menu
    console.log('[Mobile] Swipe right detected');
    const event = new CustomEvent('swiperight');
    document.dispatchEvent(event);
  }
  
  function handleSwipeLeft() {
    // Custom swipe left action - e.g., close menu
    console.log('[Mobile] Swipe left detected');
    const event = new CustomEvent('swipeleft');
    document.dispatchEvent(event);
  }
}

/**
 * Touch Event Handlers
 * Improves touch interaction feedback
 */
function initTouchHandlers() {
  // Add active class on touch for better feedback
  document.addEventListener('touchstart', (e) => {
    const target = e.target.closest('.btn, .card, .item');
    if (target) {
      target.classList.add('touch-active');
    }
  }, { passive: true });
  
  document.addEventListener('touchend', (e) => {
    const target = e.target.closest('.btn, .card, .item');
    if (target) {
      setTimeout(() => {
        target.classList.remove('touch-active');
      }, 150);
    }
  }, { passive: true });
}

/**
 * Pull to Refresh
 * Implements pull-to-refresh functionality for mobile
 */
function initPullToRefresh() {
  let startY = 0;
  let currentY = 0;
  let isPulling = false;
  const threshold = 80; // Pixels to pull before refresh
  
  const refreshIndicator = document.createElement('div');
  refreshIndicator.className = 'pull-to-refresh-indicator';
  refreshIndicator.innerHTML = '↓ Pull to refresh';
  refreshIndicator.style.cssText = `
    position: fixed;
    top: -50px;
    left: 0;
    right: 0;
    height: 50px;
    background: #111827;
    color: #9ca3af;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: top 0.3s ease;
    z-index: 9999;
  `;
  document.body.appendChild(refreshIndicator);
  
  document.addEventListener('touchstart', (e) => {
    if (window.scrollY === 0) {
      startY = e.touches[0].pageY;
      isPulling = true;
    }
  }, { passive: true });
  
  document.addEventListener('touchmove', (e) => {
    if (!isPulling) return;
    
    currentY = e.touches[0].pageY;
    const pullDistance = currentY - startY;
    
    if (pullDistance > 0 && pullDistance < threshold) {
      refreshIndicator.style.top = (pullDistance - 50) + 'px';
    } else if (pullDistance >= threshold) {
      refreshIndicator.innerHTML = '↑ Release to refresh';
      refreshIndicator.style.top = '0px';
    }
  }, { passive: true });
  
  document.addEventListener('touchend', () => {
    if (!isPulling) return;
    
    const pullDistance = currentY - startY;
    
    if (pullDistance >= threshold) {
      refreshIndicator.innerHTML = '⟳ Refreshing...';
      setTimeout(() => {
        window.location.reload();
      }, 300);
    } else {
      refreshIndicator.style.top = '-50px';
      refreshIndicator.innerHTML = '↓ Pull to refresh';
    }
    
    isPulling = false;
    startY = 0;
    currentY = 0;
  });
}

/**
 * Viewport Height Fix for Mobile Browsers
 * Handles address bar appearing/disappearing
 */
function fixMobileViewportHeight() {
  const setVh = () => {
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
  };
  
  setVh();
  window.addEventListener('resize', setVh);
  window.addEventListener('orientationchange', setVh);
}

/**
 * Initialize all mobile features
 */
function initMobileFeatures() {
  // Detect mobile device
  const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
  const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
  
  if (isMobile || isTouchDevice) {
    console.log('[Mobile] Initializing mobile features');
    
    // Add mobile class to body
    document.body.classList.add('mobile-device');
    
    // Initialize mobile features
    initMobileMenu();
    initSwipeGestures();
    initTouchHandlers();
    initPullToRefresh();
    fixMobileViewportHeight();
    
    // Prevent double-tap zoom on buttons
    document.addEventListener('touchend', (e) => {
      if (e.target.closest('.btn')) {
        e.preventDefault();
        e.target.click();
      }
    });
  }
}

// Initialize mobile features when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initMobileFeatures);
} else {
  initMobileFeatures();
}