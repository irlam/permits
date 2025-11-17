# Multiple Choice Permit System - User Guide

## Overview

The Enhanced Multiple Choice Permit System provides an industry-standard, intuitive interface for creating and managing work permits with comprehensive safety checklists. This system is designed to ensure all safety requirements are thoroughly checked and documented before work begins.

## Key Features

### 🎯 Real-Time Progress Tracking

- **Progress Bar**: Shows completion percentage at the top of the form
- **Field Counter**: Displays completed fields vs. total fields (e.g., "23/45 fields")
- **Section Indicators**: Each section shows completion status with numbered badges
- **Visual Feedback**: 
  - ✅ Green border = Section complete
  - ⚠️ Red border = Section incomplete
  - Gray = Section not started

### ⚡ Risk Assessment System

The system automatically calculates risk levels based on your responses to safety checks:

- **Low Risk** (Green): Few or no "No" answers - most safety checks passed
- **Medium Risk** (Yellow): Some safety concerns (15-30% "No" answers or 3+ issues)
- **High Risk** (Red): Significant safety concerns (30%+ "No" answers)

**Risk levels are displayed in real-time** next to the progress indicator.

### 📋 Safety Checklist Questions

All permit templates include tri-state multiple choice questions:

- **Yes** ✅ (Green): Safety requirement met
- **No** ❌ (Red): Safety requirement not met
- **N/A** ℹ️ (Blue): Not applicable to this work

### 📝 Notes and Documentation

For each safety check, you can:

1. **Add Notes**: Click "📝 Add Note" to explain your answer
2. **Attach Media**: Click "📸 Add Photo/Video" to add visual evidence
3. **Auto-open on "No"**: Notes automatically open when you select "No" to encourage explanation

**Best Practice**: Always provide notes and photos for "No" answers to document why a safety requirement cannot be met and what alternative measures are in place.

### 🧭 Quick Navigation

A fixed sidebar (desktop only) shows:
- All sections in the permit
- Completion status for each section
- Click any section to jump directly to it

### 💾 Auto-Save

- Changes are automatically saved to your browser every 2 seconds
- If you close the page accidentally, you'll be prompted to restore your work
- Auto-save works for up to 24 hours

### ✅ Smart Validation

Before submitting:
- System checks for incomplete safety checks
- Warns if critical items are missing
- Asks for confirmation if safety checks are incomplete
- Prevents accidental submission of incomplete permits

## How to Use

### Starting a New Permit

1. Navigate to the permit creation page (e.g., `/create-permit-public.php?template=confined-space-v1`)
2. Fill in your contact information (Name, Email, Phone)
3. Optionally enable browser notifications to get alerts when your permit is approved

### Completing Safety Checks

For each safety check:

1. **Read the requirement carefully**
2. **Select your answer**: Yes, No, or N/A
3. **For "No" answers**:
   - A note box will automatically open
   - Explain why the requirement cannot be met
   - Document alternative safety measures
   - Attach photos if relevant
4. **Monitor the progress bar** to track your completion

### Adding Notes and Media

**To add a note:**
- Click the "📝 Add Note" button below any safety check
- Type your explanation or additional context
- Notes are especially important for "No" answers

**To add photos/videos:**
- Click the "📸 Add Photo/Video" button
- Choose "📷 Take Photo/Video" to use your camera (mobile)
- Choose "🖼️ Choose from Gallery" to select existing files
- You can upload multiple files per check

### Reviewing Your Permit

Before submission, check:
- ✅ Progress bar shows 100% or near-complete
- ✅ All sections have green completion indicators
- ✅ All "No" answers have explanatory notes
- ✅ High-risk items have supporting photos
- ✅ Your contact information is correct

### Submitting the Permit

1. **Save Draft**: Click "📝 Save Draft" to save your progress and continue later
2. **Submit for Approval**: Click "✅ Submit Permit for Approval" when ready
   - System will warn if safety checks are incomplete
   - You'll need to confirm your submission
   - Once submitted, permit goes to pending approval status

## Understanding Section Status

Each section header shows:
- **Section Number**: Numbered badge (1, 2, 3, etc.)
- **Section Name**: Description of what this section covers
- **Completion Counter**: (X/Y) where X is completed, Y is total

**Visual Indicators:**
- **Green left border**: All fields in this section are complete ✅
- **Red left border**: Some fields are incomplete ⚠️
- **No border**: Section not started yet

## Tips for Best Results

### ✅ Do's

- ✅ Complete all safety checks honestly and thoroughly
- ✅ Provide detailed notes for "No" answers
- ✅ Attach photos of safety equipment, hazards, or site conditions
- ✅ Review the risk indicator - high risk may require additional approvals
- ✅ Use the quick navigation to jump between sections
- ✅ Save draft frequently if completing a long permit
- ✅ Enable notifications to know when your permit is approved

### ❌ Don'ts

- ❌ Don't select "N/A" just to skip required safety checks
- ❌ Don't submit permits with multiple "No" answers without proper notes
- ❌ Don't rush through safety checks - they protect you and others
- ❌ Don't ignore the validation warnings
- ❌ Don't submit if the risk indicator shows High Risk without supervisor review

## Keyboard Shortcuts

- **Tab**: Move to next field
- **Shift + Tab**: Move to previous field
- **Space**: Toggle checkbox/radio button (when focused)
- **Ctrl + S** (or Cmd + S): Save draft
- **Ctrl + P** (or Cmd + P): Print permit

## Mobile Experience

On mobile devices:
- Progress bar is always visible at the top
- Larger touch targets for easier selection
- Camera integration for quick photo capture
- Swipe gestures supported
- Quick navigation hidden to save space

## Printing Permits

To print a permit:
1. Fill out all required information
2. Press Ctrl+P (Windows) or Cmd+P (Mac)
3. The print view automatically:
   - Hides navigation and buttons
   - Shows all notes and media
   - Formats for professional presentation
   - Includes all safety check responses

## Industry Standards Compliance

This system complies with industry best practices for Permit to Work systems:

✅ **Standardized Format**: Consistent across all permit types  
✅ **Risk Assessment**: Automatic risk calculation and display  
✅ **Clear Accountability**: All responses are documented  
✅ **Comprehensive Documentation**: Notes and media for all checks  
✅ **Progress Tracking**: Real-time visibility of completion  
✅ **Validation Controls**: Prevents incomplete submissions  
✅ **Digital Evidence**: Photo/video attachments supported  
✅ **Audit Trail**: All data is timestamped and stored  

## Available Permit Types

The system supports 35+ permit templates including:

- **Confined Space Entry** (v1) - 18 safety checks across 3 sections
- **Hot Works** - 9 checks for welding, cutting, grinding
- **Working at Heights** - 28 comprehensive height safety checks
- **Electrical Isolation** (v1) - 20 electrical safety checks
- **Excavation** - 26 detailed excavation safety items
- **General PTW** (v1) - 23 general work safety checks
- And 29+ more specialized permit types

Each template has been designed by safety professionals to meet industry standards.

## Troubleshooting

### Progress bar not updating
- Refresh the page and try again
- Check that JavaScript is enabled in your browser
- Clear browser cache and reload

### Auto-save not working
- Ensure browser allows local storage
- Check that you're not in private/incognito mode
- Try a different browser

### Photos not uploading
- Check file size (max 10MB per file)
- Ensure camera permissions are granted
- Try using "Choose from Gallery" instead

### Can't submit permit
- Check validation warnings at the bottom
- Ensure all required fields are filled
- Confirm your name and email are entered

## Support

For technical issues or questions:
- Contact: permits@defecttracker.uk
- System: Defect Tracker Permits
- Documentation: See README.md for full system documentation

---

**Last Updated**: November 2025  
**Version**: 2.0 - Enhanced Multiple Choice System
