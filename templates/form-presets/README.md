# Form Presets

This folder contains curated permit templates for common UK construction activities:

- Confined Space Entry Permit (`confined-space-v1.json`)
- Electrical Isolation & Energisation Permit (`electrical-isolation-v1.json`)
- Lifting Operations Permit (`lifting-operations-v1.json`)
- Temporary Works Permit (`temporary-works-v1.json`)
- Roof Access Permit (`roof-access-v1.json`)
- Hazardous Substances Handling Permit (`hazardous-substances-v1.json`)
- Environmental Protection Permit (`environmental-control-v1.json`)
- Traffic Management Interface Permit (`traffic-management-v1.json`)
- Noise & Vibration Control Permit (`noise-vibration-v1.json`)

## Importing into the application

Run the helper script to upsert every JSON preset into the `form_templates` table:

```bash
php bin/import-form-presets.php
```

The script is idempotent: if a template with the same `id` exists it will be updated with the latest JSON.

## Manual SQL (optional)

If you prefer to run SQL manually, use the pattern below and replace `:id`, `:name`, `:version` and `:json` with the values from the JSON file. This version works for MySQL; adapt the date functions if you are using SQLite.

```sql
INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
VALUES (:id, :name, :version, :json, 'system', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  version = VALUES(version),
  json_schema = VALUES(json_schema),
  updated_at = NOW();
```

Example for the confined space permit (make sure embedded quotes are escaped or passed as a parameter):

```sql
SET @json = LOAD_FILE('/path/to/confined-space-v1.json');
INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
VALUES ('confined-space-v1', 'Confined Space Entry Permit', 1, @json, 'system', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  version = VALUES(version),
  json_schema = VALUES(json_schema),
  updated_at = NOW();
```

Repeat for each preset JSON if you go the manual route.
