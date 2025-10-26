<?php
declare(strict_types=1);

namespace Permits;

/**
 * Input Validation and Sanitization
 * 
 * Provides reusable validation and sanitization methods for user input
 * to improve security and data integrity.
 * 
 * @package Permits
 */
class Validator
{
    /**
     * Validate email address
     * 
     * @param string $email Email address to validate
     * @return bool True if valid email format
     */
    public static function isValidEmail(string $email): bool
    {
        return \filter_var($email, \FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL
     * 
     * @param string $url URL to validate
     * @return bool True if valid URL format
     */
    public static function isValidUrl(string $url): bool
    {
        return \filter_var($url, \FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate UUID (version 4)
     * 
     * @param string $uuid UUID to validate
     * @return bool True if valid UUID format
     */
    public static function isValidUuid(string $uuid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return \preg_match($pattern, $uuid) === 1;
    }

    /**
     * Validate date format
     * 
     * @param string $date Date string to validate
     * @param string $format Expected date format (default: Y-m-d)
     * @return bool True if valid date in specified format
     */
    public static function isValidDate(string $date, string $format = 'Y-m-d'): bool
    {
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Sanitize string for safe output
     * 
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    public static function sanitizeString(string $string): string
    {
        return \htmlspecialchars($string, \ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize string for safe HTML output (allows some HTML tags)
     * 
     * @param string $html HTML string to sanitize
     * @param array<string> $allowedTags Allowed HTML tags (default: p, br, strong, em, a)
     * @return string Sanitized HTML
     */
    public static function sanitizeHtml(string $html, array $allowedTags = ['p', 'br', 'strong', 'em', 'a']): string
    {
        $allowed = '<' . \implode('><', $allowedTags) . '>';
        return \strip_tags($html, $allowed);
    }

    /**
     * Validate and sanitize integer
     * 
     * @param mixed $value Value to validate
     * @param int|null $min Minimum allowed value
     * @param int|null $max Maximum allowed value
     * @return int|null Sanitized integer or null if invalid
     */
    public static function sanitizeInt($value, ?int $min = null, ?int $max = null): ?int
    {
        $filtered = \filter_var($value, \FILTER_VALIDATE_INT);
        
        if ($filtered === false) {
            return null;
        }
        
        if ($min !== null && $filtered < $min) {
            return null;
        }
        
        if ($max !== null && $filtered > $max) {
            return null;
        }
        
        return $filtered;
    }

    /**
     * Validate and sanitize float
     * 
     * @param mixed $value Value to validate
     * @param float|null $min Minimum allowed value
     * @param float|null $max Maximum allowed value
     * @return float|null Sanitized float or null if invalid
     */
    public static function sanitizeFloat($value, ?float $min = null, ?float $max = null): ?float
    {
        $filtered = \filter_var($value, \FILTER_VALIDATE_FLOAT);
        
        if ($filtered === false) {
            return null;
        }
        
        if ($min !== null && $filtered < $min) {
            return null;
        }
        
        if ($max !== null && $filtered > $max) {
            return null;
        }
        
        return $filtered;
    }

    /**
     * Validate string length
     * 
     * @param string $string String to validate
     * @param int $minLength Minimum length (default: 0)
     * @param int|null $maxLength Maximum length (null for no limit)
     * @return bool True if length is valid
     */
    public static function isValidLength(string $string, int $minLength = 0, ?int $maxLength = null): bool
    {
        $length = \mb_strlen($string, 'UTF-8');
        
        if ($length < $minLength) {
            return false;
        }
        
        if ($maxLength !== null && $length > $maxLength) {
            return false;
        }
        
        return true;
    }

    /**
     * Validate required field
     * 
     * @param mixed $value Value to check
     * @return bool True if value is not empty
     */
    public static function isRequired($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        
        if (\is_array($value) && empty($value)) {
            return false;
        }
        
        return true;
    }

    /**
     * Sanitize filename for safe file storage
     * 
     * @param string $filename Filename to sanitize
     * @return string Sanitized filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path components
        $filename = \basename($filename);
        
        // Remove any characters that aren't alphanumeric, dash, underscore, or dot
        $filename = \preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Prevent directory traversal
        $filename = \str_replace(['..', '//', '\\\\'], '_', $filename);
        
        // Limit length
        if (\mb_strlen($filename, 'UTF-8') > 255) {
            $ext = \pathinfo($filename, \PATHINFO_EXTENSION);
            $name = \mb_substr(\pathinfo($filename, \PATHINFO_FILENAME), 0, 250, 'UTF-8');
            $filename = $name . '.' . $ext;
        }
        
        return $filename;
    }

    /**
     * Validate allowed file extension
     * 
     * @param string $filename Filename to check
     * @param array<string> $allowedExtensions Array of allowed extensions (without dot)
     * @return bool True if extension is allowed
     */
    public static function isAllowedExtension(string $filename, array $allowedExtensions): bool
    {
        $ext = \strtolower(\pathinfo($filename, \PATHINFO_EXTENSION));
        return \in_array($ext, \array_map('strtolower', $allowedExtensions), true);
    }

    /**
     * Validate array contains only expected keys
     * 
     * @param array<string,mixed> $data Array to validate
     * @param array<string> $allowedKeys Array of allowed keys
     * @return bool True if array only contains allowed keys
     */
    public static function hasOnlyAllowedKeys(array $data, array $allowedKeys): bool
    {
        $dataKeys = \array_keys($data);
        $unexpectedKeys = \array_diff($dataKeys, $allowedKeys);
        return empty($unexpectedKeys);
    }

    /**
     * Validate phone number (basic validation)
     * 
     * @param string $phone Phone number to validate
     * @return bool True if valid phone format
     */
    public static function isValidPhone(string $phone): bool
    {
        // Remove common formatting characters
        $cleaned = \preg_replace('/[\s\-\(\)\+]/', '', $phone);
        
        // Check if result contains only digits
        return $cleaned !== null && \preg_match('/^\d{7,15}$/', $cleaned) === 1;
    }

    /**
     * Validate alphanumeric string
     * 
     * @param string $string String to validate
     * @param bool $allowSpaces Allow spaces in string
     * @param bool $allowDashes Allow dashes in string
     * @return bool True if string is alphanumeric
     */
    public static function isAlphanumeric(string $string, bool $allowSpaces = false, bool $allowDashes = false): bool
    {
        $pattern = '/^[a-zA-Z0-9';
        
        if ($allowSpaces) {
            $pattern .= '\s';
        }
        
        if ($allowDashes) {
            $pattern .= '\-';
        }
        
        $pattern .= ']+$/';
        
        return \preg_match($pattern, $string) === 1;
    }
}
