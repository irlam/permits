# Permits System - Modern Dark Theme Styling Guide

**Last Updated**: 21/10/2025 19:22:30 (UK)  
**Author**: irlam

## Overview

This guide documents the modern dark theme implementation and provides guidance for future styling updates.

---

## 🎨 Color Palette

### Core Colors

```css
/* Dark Theme Base Colors */
--background: #0a0f1a;      /* Deep dark blue-black - main background */
--surface: #111827;          /* Dark slate - cards, panels */
--surface-2: #0a101a;        /* Darker variant - inputs, nested elements */
--border: #1f2937;           /* Subtle borders */
--border-light: #374151;     /* Lighter borders for hover states */

/* Text Colors */
--text-primary: #f9fafb;     /* Almost white - main text */
--text-secondary: #9ca3af;   /* Muted gray - secondary text, labels */
--text-muted: #6b7280;       /* Even more muted - hints, placeholders */

/* Brand & Semantic Colors */
--accent: #3b82f6;           /* Modern blue - primary actions, links */
--accent-hover: #2563eb;     /* Darker blue - hover state */
--success: #10b981;          /* Green - success states, positive actions */
--warning: #f59e0b;          /* Amber - warnings, caution states */
--danger: #ef4444;           /* Red - errors, destructive actions */
```

### Status Badge Colors

```css
/* Status-specific colors for permits */
--status-draft: #6b7280;     /* Gray - draft permits */
--status-pending: #f59e0b;   /* Amber - pending review */
--status-issued: #3b82f6;    /* Blue - issued permits */
--status-active: #10b981;    /* Green - active permits */
--status-expired: #ef4444;   /* Red - expired permits */
--status-closed: #6b7280;    /* Gray - closed permits */
```

---

## 📐 Spacing System

Consistent spacing creates visual harmony:

```css
/* Base spacing unit: 4px */
--space-1: 4px;      /* Micro spacing */
--space-2: 8px;      /* Tight spacing */
--space-3: 12px;     /* Small spacing */
--space-4: 16px;     /* Standard spacing */
--space-5: 20px;     /* Medium spacing */
--space-6: 24px;     /* Large spacing */
--space-8: 32px;     /* Extra large spacing */
```

**Usage Guidelines:**
- Use `12px` for tight element spacing (gap between form fields)
- Use `16px` for standard card padding and element margins
- Use `24px` for section spacing and page margins

---

## 🔘 Border Radius

Smooth, modern rounded corners:

```css
/* Border radius scale */
--radius-sm: 6px;    /* Small - inputs, small buttons */
--radius-md: 8px;    /* Medium - buttons, badges */
--radius-lg: 12px;   /* Large - cards, panels */
--radius-full: 999px; /* Pills - status indicators */
```

---

## 🌈 Shadows

Subtle shadows create depth and hierarchy:

```css
/* Shadow system */
--shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
--shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
--shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.3);

/* Usage */
.card {
  box-shadow: var(--shadow-md);
}

.card:hover {
  box-shadow: var(--shadow-lg);
}
```

---

## ⚡ Transitions

Smooth, consistent animations:

```css
/* Standard transition */
transition: all 0.2s ease;

/* Use cases */
.btn {
  transition: all 0.2s ease;  /* Smooth hover effects */
}

.card {
  transition: box-shadow 0.2s ease;  /* Depth on hover */
}

details summary::before {
  transition: transform 0.2s ease;  /* Arrow rotation */
}
```

---

## 🔤 Typography

Clear, readable text hierarchy:

```css
/* Font Family */
font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Ubuntu, Arial, sans-serif;

/* Text Sizes */
--text-xs: 12px;     /* Small labels, captions */
--text-sm: 13px;     /* Form labels, secondary text */
--text-base: 15px;   /* Body text, inputs */
--text-lg: 16px;     /* Larger body text */
--text-xl: 18px;     /* Section headings */
--text-2xl: 20px;    /* Card titles */
--text-3xl: 24px;    /* Page titles */
--text-4xl: 28px;    /* Main headings */

/* Line Height */
line-height: 1.6;    /* Base line height for readability */
```

---

## 🔘 Button Styles

Modern, interactive buttons:

```css
/* Base Button */
.btn {
  padding: 10px 16px;
  border: 1px solid #1f2937;
  border-radius: 8px;
  background: #111827;
  color: #f9fafb;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

/* Hover State */
.btn:hover {
  background: #1f2937;
  border-color: #374151;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
  transform: translateY(-1px);
}

/* Active State */
.btn:active {
  transform: translateY(0);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

/* Accent Button (Primary Action) */
.btn-accent {
  background: #3b82f6;
  border-color: #3b82f6;
  color: #ffffff;
}

.btn-accent:hover {
  background: #2563eb;
  border-color: #2563eb;
}
```

---

## 📝 Form Elements

Clean, modern form inputs:

```css
/* Input Fields */
.field input,
.field select,
.field textarea {
  width: 100%;
  background: #0a0f1a;
  color: #f9fafb;
  border: 1px solid #1f2937;
  border-radius: 8px;
  padding: 12px;
  font-size: 15px;
  transition: all 0.2s ease;
}

/* Focus State */
.field input:focus,
.field select:focus,
.field textarea:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Labels */
.field label {
  font-size: 13px;
  color: #9ca3af;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  margin-bottom: 6px;
}
```

---

## 🃏 Card Components

Elevated, interactive cards:

```css
/* Card Container */
.card {
  background: #111827;
  border: 1px solid #1f2937;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
  transition: box-shadow 0.2s ease;
}

/* Card Hover Effect */
.card:hover {
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
}

/* Card Title */
.card h2 {
  margin: 0 0 16px 0;
  font-size: 20px;
  font-weight: 600;
  color: #f9fafb;
}
```

---

## 📱 Responsive Breakpoints

Mobile-first responsive design:

```css
/* Breakpoints */
--breakpoint-mobile: 768px;
--breakpoint-tablet: 1024px;
--breakpoint-desktop: 1280px;

/* Mobile (<768px) */
@media (max-width: 768px) {
  .grid {
    grid-template-columns: 1fr;  /* Single column */
    padding: 16px;
  }
  
  .meta {
    grid-template-columns: 1fr;  /* Stack form fields */
  }
}
```

---

## 🖨️ Print Styles

Clean, printer-friendly output:

```css
@media print {
  /* Reset to light theme for printing */
  html, body {
    background: #fff !important;
    color: #111 !important;
    font-size: 12px;
  }
  
  /* Hide UI elements */
  .top, .btn, .tools {
    display: none !important;
  }
  
  /* Simplify borders */
  .card, .item {
    background: #fff !important;
    border-color: #999 !important;
    box-shadow: none !important;
  }
}
```

---

## 🎯 Best Practices

### Do's ✅

1. **Use Consistent Spacing**
   - Stick to 12px, 16px, 24px spacing system
   - Maintain visual rhythm

2. **Apply Smooth Transitions**
   - Use `0.2s ease` for most interactions
   - Makes UI feel responsive and polished

3. **Maintain Color Hierarchy**
   - Primary text: `#f9fafb`
   - Secondary text: `#9ca3af`
   - Muted text: `#6b7280`

4. **Use Semantic Colors**
   - Blue (`#3b82f6`) for primary actions
   - Green (`#10b981`) for success
   - Red (`#ef4444`) for errors
   - Amber (`#f59e0b`) for warnings

5. **Add Hover States**
   - All interactive elements should have hover feedback
   - Use subtle transforms and shadow changes

### Don'ts ❌

1. **Don't Use Inconsistent Colors**
   - Avoid introducing new colors without reason
   - Stick to the defined palette

2. **Don't Overcomplicate Shadows**
   - Use the three-tier shadow system
   - More isn't always better

3. **Don't Skip Mobile Testing**
   - Always test responsive layouts
   - Ensure touch targets are large enough (min 44px)

4. **Don't Forget Accessibility**
   - Maintain sufficient color contrast
   - Ensure focus states are visible
   - Keep text readable (min 14px on mobile)

---

## 🔧 Customization

To customize the theme:

1. **Change Primary Accent Color**
   ```css
   /* In assets/app.css, update: */
   .btn-accent {
     background: #your-color;
     border-color: #your-color;
   }
   ```

2. **Adjust Background Darkness**
   ```css
   body {
     background: #your-background-color;
   }
   ```

3. **Modify Spacing**
   ```css
   /* Update spacing variables in card, grid, etc. */
   .card {
     padding: 24px;  /* Adjust as needed */
   }
   ```

4. **Update Border Radius**
   ```css
   /* Make everything more or less rounded */
   .card {
     border-radius: 16px;  /* More rounded */
   }
   ```

---

## 📚 Component Examples

### Status Badge
```html
<span class="status-badge" style="background: #10b981">ACTIVE</span>
```

### Primary Action Button
```html
<button class="btn btn-accent">Save Form</button>
```

### Secondary Button
```html
<button class="btn">Cancel</button>
```

### Card with Hover
```html
<div class="card">
  <h2>Card Title</h2>
  <p>Card content goes here...</p>
</div>
```

### Form Field
```html
<div class="field">
  <label>Field Label</label>
  <input type="text" placeholder="Enter value...">
</div>
```

---

## 🎨 Design Philosophy

The modern dark theme follows these principles:

1. **Clarity**: Every element has clear purpose and hierarchy
2. **Consistency**: Uniform spacing, colors, and patterns
3. **Feedback**: Interactive elements provide clear feedback
4. **Accessibility**: High contrast, readable text, clear focus states
5. **Performance**: Smooth transitions without jank
6. **Maintainability**: Well-documented, easy to customize

---

**End of Styling Guide**
