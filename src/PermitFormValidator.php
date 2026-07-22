<?php
declare(strict_types=1);

namespace Permits;

use DateTimeImmutable;

/**
 * Validates public permit answers against the stored template structure.
 *
 * Browser validation is helpful feedback, but permit safety rules must also be
 * enforced on the server. Checklist score items are mandatory on final
 * submission even for older templates that stored them as optional fields.
 */
final class PermitFormValidator
{
    /**
     * @param array<int,array<string,mixed>> $structure
     * @param array<string,mixed> $values
     * @return array<string,string> Errors keyed by field name.
     */
    public static function validate(array $structure, array $values, bool $finalSubmission = true): array
    {
        $errors = [];

        foreach ($structure as $section) {
            if (!is_array($section) || !isset($section['fields']) || !is_array($section['fields'])) {
                continue;
            }

            foreach ($section['fields'] as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }

                $name = (string) $field['name'];
                $label = trim((string) ($field['label'] ?? $name));
                $type = strtolower((string) ($field['type'] ?? 'text'));
                $value = $values[$name] ?? null;
                $required = !empty($field['required']) || ($finalSubmission && !empty($field['scoreItem']));

                if (self::isBlank($value)) {
                    if ($required) {
                        $errors[$name] = $label . ' is required.';
                    }
                    continue;
                }

                if (is_array($value) && $type !== 'select_multiple') {
                    $errors[$name] = $label . ' contains an invalid value.';
                    continue;
                }

                $stringValue = is_array($value)
                    ? implode(', ', array_map(static fn ($item): string => trim((string) $item), $value))
                    : trim((string) $value);

                $maxLength = $type === 'textarea'
                    ? 5000
                    : ($type === 'email' ? 255 : ($type === 'tel' ? 50 : 1000));
                if (mb_strlen($stringValue, 'UTF-8') > $maxLength) {
                    $errors[$name] = sprintf('%s must be %d characters or fewer.', $label, $maxLength);
                    continue;
                }

                if (!self::hasAllowedOptions($field, $value, $type)) {
                    $errors[$name] = $label . ' contains an invalid selection.';
                    continue;
                }

                if (
                    $finalSubmission
                    && !empty($field['scoreItem'])
                    && strtolower($stringValue) === 'no'
                    && self::isBlank($values[$name . '_note'] ?? null)
                    && self::isBlank($values[$name . '_media'] ?? null)
                ) {
                    $errors[$name] = $label . ' needs a note or photo explaining the No answer.';
                    continue;
                }

                if ($type === 'email' && filter_var($stringValue, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$name] = $label . ' must be a valid email address.';
                } elseif ($type === 'date' && !self::matchesDateFormat($stringValue, ['Y-m-d'])) {
                    $errors[$name] = $label . ' must be a valid date.';
                } elseif ($type === 'time' && !self::matchesDateFormat($stringValue, ['H:i', 'H:i:s'])) {
                    $errors[$name] = $label . ' must be a valid time.';
                } elseif ($type === 'datetime' && !self::matchesDateFormat($stringValue, ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'])) {
                    $errors[$name] = $label . ' must be a valid date and time.';
                } elseif ($type === 'number') {
                    if (!is_numeric($stringValue)) {
                        $errors[$name] = $label . ' must be a number.';
                    } else {
                        $number = (float) $stringValue;
                        if (isset($field['min']) && $number < (float) $field['min']) {
                            $errors[$name] = sprintf('%s must be at least %s.', $label, (string) $field['min']);
                        } elseif (isset($field['max']) && $number > (float) $field['max']) {
                            $errors[$name] = sprintf('%s must be no more than %s.', $label, (string) $field['max']);
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /** @param mixed $value */
    private static function isBlank($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (trim((string) $item) !== '') {
                    return false;
                }
            }

            return true;
        }

        return $value === null || trim((string) $value) === '';
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed $value
     */
    private static function hasAllowedOptions(array $field, $value, string $type): bool
    {
        if (!in_array($type, ['select', 'select_multiple', 'radio'], true)) {
            return true;
        }

        $allowed = [];
        foreach (($field['options'] ?? []) as $option) {
            $optionValue = is_array($option)
                ? (string) ($option['value'] ?? ($option[0] ?? ''))
                : (string) $option;
            if ($optionValue !== '') {
                $allowed[] = $optionValue;
            }
        }

        if ($allowed === []) {
            return false;
        }

        if (is_array($value)) {
            $submitted = array_values(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $value
            ), static fn (string $item): bool => $item !== ''));
        } elseif ($type === 'select_multiple') {
            $submitted = array_values(array_filter(
                array_map('trim', explode(',', (string) $value)),
                static fn (string $item): bool => $item !== ''
            ));
        } else {
            $submitted = [trim((string) $value)];
        }

        if ($type !== 'select_multiple' && count($submitted) !== 1) {
            return false;
        }

        foreach ($submitted as $item) {
            if (!in_array($item, $allowed, true)) {
                return false;
            }
        }

        return $submitted !== [];
    }

    /** @param array<int,string> $formats */
    private static function matchesDateFormat(string $value, array $formats): bool
    {
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return true;
            }
        }

        return false;
    }
}
