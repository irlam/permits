-- Enforce unique public permit identifiers on MySQL 5.7+/MariaDB 10.2+.
-- Apply after 2026-07-production-hardening.sql.
-- Back up first. If a migration_error row is returned, resolve the duplicate
-- records and run this file again before putting the application into service.

UPDATE forms SET ref_number = NULL WHERE ref_number IS NOT NULL AND TRIM(ref_number) = '';
UPDATE forms SET unique_link = NULL WHERE unique_link IS NOT NULL AND TRIM(unique_link) = '';

SET @duplicate_ref_groups = (
  SELECT COUNT(*) FROM (
    SELECT ref_number FROM forms
    WHERE ref_number IS NOT NULL
    GROUP BY ref_number HAVING COUNT(*) > 1
  ) duplicate_refs
);
SET @duplicate_link_groups = (
  SELECT COUNT(*) FROM (
    SELECT unique_link FROM forms
    WHERE unique_link IS NOT NULL
    GROUP BY unique_link HAVING COUNT(*) > 1
  ) duplicate_links
);

SET @has_unique_ref = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'forms'
    AND index_name = 'uq_forms_ref_number' AND non_unique = 0
);
SET @sql = IF(
  @has_unique_ref > 0,
  'SELECT 1',
  IF(
    @duplicate_ref_groups = 0,
    'CREATE UNIQUE INDEX uq_forms_ref_number ON forms (ref_number)',
    'SELECT ''ERROR: duplicate non-empty forms.ref_number values must be resolved before production'' AS migration_error'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_unique_link = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'forms'
    AND index_name = 'uq_forms_unique_link' AND non_unique = 0
);
SET @sql = IF(
  @has_unique_link > 0,
  'SELECT 1',
  IF(
    @duplicate_link_groups = 0,
    'CREATE UNIQUE INDEX uq_forms_unique_link ON forms (unique_link)',
    'SELECT ''ERROR: duplicate non-empty forms.unique_link values must be replaced before production'' AS migration_error'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Remove the redundant non-unique indexes only after the unique replacements exist.
SET @has_unique_ref = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'forms'
    AND index_name = 'uq_forms_ref_number' AND non_unique = 0
);
SET @has_legacy_ref = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'forms'
    AND index_name = 'idx_ref_number'
);
SET @sql = IF(@has_unique_ref > 0 AND @has_legacy_ref > 0, 'DROP INDEX idx_ref_number ON forms', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_unique_link = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'forms'
    AND index_name = 'uq_forms_unique_link' AND non_unique = 0
);
SET @has_legacy_link = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'forms'
    AND index_name = 'idx_forms_unique_link'
);
SET @sql = IF(@has_unique_link > 0 AND @has_legacy_link > 0, 'DROP INDEX idx_forms_unique_link ON forms', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
