# Multiple Choice Permit System - Technical Documentation

## Architecture Overview

The Enhanced Multiple Choice Permit System is built on a modular architecture that converts JSON template definitions into interactive web forms with comprehensive safety checklists.

## Core Components

### 1. Template System (`templates/form-presets/`)

All permit templates are defined as JSON files with the following structure:

```json
{
  "id": "permit-type-v1",
  "title": "Permit Type Name",
  "description": "Brief description",
  "meta": {
    "fields": [
      // Top-level permit details (location, dates, personnel, etc.)
    ]
  },
  "sections": [
    {
      "title": "SECTION NAME",
      "items": [
        "Safety check item 1",
        "Safety check item 2",
        ...
      ],
      "fields": [
        // Additional custom fields for this section
      ]
    }
  ],
  "signatures": [
    // Required signatures
  ]
}
```

### 2. Form Structure Builder (`src/FormTemplateSeeder.php`)

**Key Method**: `buildPublicFormStructure(array $schema): array`

This method transforms the JSON template into a structured array suitable for rendering:

```php
// Input: JSON template from file
// Output: Structured array with sections and fields

// For each section with "items" array:
- Creates tri-state radio fields (Yes/No/N/A)
- Marks them with 'scoreItem' => true
- Auto-generates field names like 'section1_item_1'
- Maintains field order and structure
```

**Field Type Mapping**:
- `radio` → Yes/No/N/A buttons for checklist items
- `select` → Dropdown menu
- `multiselect` → Multi-select dropdown
- `textarea` → Multi-line text input
- `date`, `time`, `datetime` → Appropriate input types
- `text`, `email`, `tel`, `number` → Respective HTML5 inputs

### 3. Public Form Renderer (`create-permit-public.php`)

The main permit creation interface with three key sections:

#### A. Server-Side Processing (PHP)

**Lines 100-269**: Form submission handling
- Validates required fields
- Processes file uploads (photos/videos)
- Generates unique permit IDs
- Saves to database
- Triggers notification workflows

**Lines 728-900**: Dynamic form rendering
- Iterates through form structure
- Renders appropriate field types
- Adds note/media attachment capabilities for score items
- Preserves existing data when editing

#### B. Client-Side Styling (CSS)

**Lines 279-771**: Comprehensive CSS including:

**Progress System**:
```css
.progress-bar-container { /* Sticky header */ }
.progress-fill { /* Animated fill with shimmer */ }
.risk-indicator { /* Dynamic risk badges */ }
```

**Section Indicators**:
```css
.section-title::before { /* Numbered badges */ }
.section-complete { /* Green border */ }
.section-incomplete { /* Red border */ }
```

**Choice Pills**:
```css
.choice-pill { /* Base button style */ }
.choice-pill.choice-yes { /* Green on checked */ }
.choice-pill.choice-no { /* Red on checked */ }
.choice-pill.choice-na { /* Blue on checked */ }
```

**Quick Navigation**:
```css
.quick-nav { /* Fixed sidebar */ }
.quick-nav-link { /* Section links */ }
```

#### C. Client-Side Logic (JavaScript)

**Lines 1047-1378**: JavaScript functionality

**1. Progress Tracking**:
```javascript
function updateProgress() {
  // Counts completed vs total fields
  // Calculates completion percentage
  // Updates progress bar
  // Triggers risk assessment
}
```

**2. Risk Assessment**:
```javascript
function updateRiskIndicator(noCount, yesCount, naCount) {
  // Calculates no percentage
  // Assigns risk level:
  //   High: 30%+ No answers
  //   Medium: 15-30% No or 3+ issues
  //   Low: < 15% No answers
  // Updates UI with color-coded indicator
}
```

**3. Section Tracking**:
```javascript
function updateSectionCounters() {
  // Iterates through all sections
  // Counts completed fields per section
  // Updates section badges
  // Builds quick navigation
  // Adds visual indicators
}
```

**4. Auto-Save**:
```javascript
function saveToLocalStorage() {
  // Serializes form data
  // Stores in localStorage with timestamp
  // Key: 'permit_draft_' + templateId
}

function restoreAutoSavedData() {
  // Checks for saved data
  // Validates age (< 24 hours)
  // Prompts user to restore
  // Repopulates form fields
}
```

**5. Smart Interactions**:
```javascript
// Auto-open notes on "No" selection
document.addEventListener('change', function(e) {
  if (e.target.value.toLowerCase() === 'no') {
    // Open note box
    // Focus textarea
    // Update placeholder with guidance
  }
});
```

**6. Pre-Submission Validation**:
```javascript
form.addEventListener('submit', function(e) {
  // Count incomplete safety checks
  // Show validation warnings
  // Require confirmation for incomplete items
  // Prevent submission if user cancels
});
```

## Data Flow

### 1. Template Loading

```
JSON Template File
    ↓
FormTemplateSeeder::importFromDirectory()
    ↓
buildPublicFormStructure()
    ↓
Database (form_templates.form_structure)
```

### 2. Form Rendering

```
Database Query
    ↓
PHP: Decode form_structure JSON
    ↓
PHP: Render HTML for each field
    ↓
Browser: Display form
    ↓
JavaScript: Initialize tracking
```

### 3. User Interaction

```
User fills form
    ↓
JavaScript: Update progress (every change)
    ↓
JavaScript: Calculate risk (on score items)
    ↓
JavaScript: Auto-save (every 2 seconds of inactivity)
    ↓
JavaScript: Update section status (real-time)
```

### 4. Submission

```
User clicks submit
    ↓
JavaScript: Validate completion
    ↓
JavaScript: Confirm with user
    ↓
PHP: Process form data
    ↓
PHP: Handle file uploads
    ↓
PHP: Save to database
    ↓
PHP: Send notifications
    ↓
Display success message
```

## Database Schema

### `form_templates` Table

```sql
CREATE TABLE form_templates (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255),
  version INT,
  json_schema TEXT,           -- Original JSON template
  form_structure TEXT,         -- Processed structure for rendering
  created_by VARCHAR(36),
  published_at DATETIME,
  updated_at DATETIME
);
```

### `forms` Table

```sql
CREATE TABLE forms (
  id VARCHAR(36) PRIMARY KEY,
  ref_number VARCHAR(50),
  template_id VARCHAR(36),
  form_data TEXT,              -- JSON with all field values
  status VARCHAR(32),          -- draft, pending_approval, approved, etc.
  holder_name VARCHAR(255),
  holder_email VARCHAR(255),
  holder_phone VARCHAR(50),
  unique_link VARCHAR(100),    -- For editing drafts
  created_at DATETIME,
  updated_at DATETIME,
  -- ... additional fields
);
```

## Field Naming Convention

Fields are auto-generated with predictable names:

### Meta Fields
- Pattern: `meta_{field_key}`
- Example: `meta_location`, `meta_description`

### Section Fields (Custom)
- Pattern: `section{N}_{field_key}`
- Example: `section1_isolation_method`

### Section Items (Checklist)
- Pattern: `section{N}_item_{M}`
- Example: `section1_item_1`, `section2_item_3`

### Associated Fields
- Notes: `{field_name}_note`
- Media: `{field_name}_media`

Example:
```
Field: section1_item_1
Note:  section1_item_1_note
Media: section1_item_1_media
```

## Extending the System

### Adding a New Permit Template

1. **Create JSON file** in `templates/form-presets/`:
```json
{
  "id": "my-new-permit-v1",
  "title": "My New Permit Type",
  "meta": {
    "fields": [
      {"key": "location", "label": "Location", "type": "text", "required": true}
    ]
  },
  "sections": [
    {
      "title": "SAFETY CHECKS",
      "items": [
        "Safety requirement 1",
        "Safety requirement 2"
      ]
    }
  ]
}
```

2. **Import template**:
```php
$result = FormTemplateSeeder::importFromDirectory($db, '/path/to/templates/form-presets');
```

3. **Access at**: `/create-permit-public.php?template=my-new-permit-v1`

### Customizing Field Types

To add new field types, modify `FormTemplateSeeder::mapFieldType()`:

```php
private static function mapFieldType(string $type, bool $hasOptions): string
{
    switch ($type) {
        case 'my_custom_type':
            return 'custom';
        // ... existing cases
    }
}
```

Then add rendering logic in `create-permit-public.php`:

```php
<?php elseif ($fieldType === 'custom'): ?>
    <!-- Your custom HTML here -->
<?php endif; ?>
```

### Modifying Risk Calculation

Edit the `updateRiskIndicator()` function in JavaScript:

```javascript
// Current logic:
const noPercentage = (noCount / totalScoreItems) * 100;
if (noPercentage >= 30) { /* High Risk */ }
else if (noPercentage >= 15 || noCount >= 3) { /* Medium Risk */ }
else { /* Low Risk */ }

// Customize thresholds or add new levels
```

### Adding New Features

**1. Add CSS** for visual styling
**2. Add HTML** in the appropriate section
**3. Add JavaScript** for interactivity
**4. Test** with multiple permit types

## Performance Optimization

### Current Optimizations

1. **Efficient DOM queries**: Cache selectors, use delegation
2. **Debounced auto-save**: Only saves after 2s of inactivity
3. **LocalStorage**: Reduces server load for drafts
4. **Static caching**: Form structure cached in database
5. **Lazy rendering**: Sections rendered only when needed

### Recommendations for Large Forms

- Consider virtual scrolling for 100+ checklist items
- Implement field visibility based on previous answers
- Add pagination for very long permits
- Use Web Workers for complex calculations
- Implement service worker for offline support

## Browser Compatibility

**Fully Supported**:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

**Partial Support** (graceful degradation):
- IE 11 (no auto-save, basic styles)
- Safari 13 (no smooth scrolling)

**Required Features**:
- JavaScript enabled
- LocalStorage available
- CSS3 support (flexbox, grid)
- HTML5 form inputs

## Security Considerations

### File Uploads

```php
// Whitelist allowed MIME types
$allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/quicktime','video/webm'];

// Validate file type
if (!in_array($type, $allowedTypes, true)) {
    // Reject file
}

// Sanitize filename
$safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($origName));
```

### Input Validation

- Server-side validation for all fields
- Prepared statements for database queries
- CSRF token validation (in main bootstrap)
- XSS prevention via `htmlspecialchars()`

### Auto-Save Security

- Data stored only in user's browser
- No sensitive data exposed to server until submission
- LocalStorage isolated per origin
- Automatic cleanup after 24 hours

## Testing

### Unit Tests

Test the form structure builder:
```php
$schema = json_decode(file_get_contents('template.json'), true);
$structure = FormTemplateSeeder::buildPublicFormStructure($schema);
$this->assertIsArray($structure);
$this->assertNotEmpty($structure);
```

### Integration Tests

1. Load template
2. Fill form programmatically
3. Submit
4. Verify database entry
5. Check notifications sent

### Manual Testing Checklist

- [ ] All 35 permit templates load correctly
- [ ] Progress bar updates on field changes
- [ ] Risk indicator shows correct levels
- [ ] Auto-save works and restores
- [ ] Notes open on "No" selection
- [ ] File uploads work (photos/videos)
- [ ] Quick navigation scrolls to sections
- [ ] Validation warnings display correctly
- [ ] Print styles render professionally
- [ ] Mobile experience is usable

## Monitoring & Analytics

### Key Metrics to Track

1. **Completion Rate**: % of started permits that are submitted
2. **Average Completion Time**: Time to fill out permit
3. **Risk Distribution**: % of permits by risk level
4. **Common "No" Answers**: Which safety checks fail most often
5. **Auto-Save Usage**: How often users return to drafts
6. **Template Popularity**: Most/least used permit types

### Logging

Key events to log:
```php
logActivity('permit_created', 'permit', 'form', $permit_id, $details);
logActivity('permit_draft_saved', 'permit', 'form', $permit_id, $details);
logActivity('permit_submitted', 'permit', 'form', $permit_id, $details);
```

## Future Enhancements

### Planned Features

1. **Conditional Logic**: Show/hide fields based on previous answers
2. **Smart Defaults**: Pre-fill based on user's previous permits
3. **Offline Mode**: Complete permits without internet connection
4. **Digital Signatures**: Touch/stylus signature capture
5. **Real-time Collaboration**: Multiple users editing simultaneously
6. **Template Builder UI**: Create templates without editing JSON
7. **Advanced Analytics**: Dashboards showing permit trends
8. **Integration APIs**: Connect with other safety systems

### Community Suggestions

The system is designed to be:
- ✅ Extensible: Easy to add new features
- ✅ Maintainable: Clear code structure
- ✅ Scalable: Handles growing numbers of permits
- ✅ Accessible: WCAG 2.1 AA compliant
- ✅ International: Ready for localization

---

**For additional technical support**: See codebase comments and inline documentation
**Last Updated**: November 2025
**System Version**: 2.0
