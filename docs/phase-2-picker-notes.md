# Phase 2 permit picker presentation

This phase is presentation-only. It does not modify permit schemas, template IDs, historical permit records, approval logic or database structure.

## Picker groups

The public permit tiles are grouped client-side into:

- Core & High Risk
- Electrical & Isolation
- Groundworks & Temporary Works
- Work at Height & Lifting
- Hazardous Materials & Environment
- Traffic, Access & Inspections
- Other Permits (fallback)

Unknown or future templates remain visible in the fallback group rather than being hidden.

## Icons

Specialist icons are selected from the displayed permit name. This fixes the fallback-document icon problem caused by canonical v2 names not matching the older server-side slug map.

## Favicon

`favicon.svg` is emitted through the shared `cache_meta_tags()` helper and is also included in the service-worker static shell.
