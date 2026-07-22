<?php
declare(strict_types=1);

namespace Permits;

use RuntimeException;

/** Resolves backup storage and guarantees it is outside the public application root. */
final class BackupStorage
{
    public static function configuredPath(string $root): string
    {
        return self::pathFromValue($root, (string)($_ENV['BACKUP_PATH'] ?? ''));
    }

    public static function pathFromValue(string $root, string $configured): string
    {
        $rootPath = realpath($root);
        if ($rootPath === false) {
            throw new RuntimeException('The application directory could not be verified.');
        }

        $configured = trim($configured);
        $candidate = $configured !== ''
            ? rtrim($configured, '/\\')
            : dirname($rootPath) . DIRECTORY_SEPARATOR . 'permits-private-backups';

        if (!self::isAbsolute($candidate)) {
            throw new RuntimeException('BACKUP_PATH must be an absolute server path.');
        }

        $existing = realpath($candidate);
        if ($existing !== false) {
            $resolved = $existing;
        } else {
            $parent = realpath(dirname($candidate));
            if ($parent === false) {
                throw new RuntimeException('The parent folder for BACKUP_PATH does not exist.');
            }
            $resolved = $parent . DIRECTORY_SEPARATOR . basename($candidate);
        }

        if (self::isInside($resolved, $rootPath)) {
            throw new RuntimeException('BACKUP_PATH must be outside the public application directory.');
        }

        return $resolved;
    }

    public static function ensure(string $root): string
    {
        $path = self::configuredPath($root);
        if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the private backup directory.');
        }
        @chmod($path, 0700);
        if (!is_writable($path)) {
            throw new RuntimeException('The private backup directory is not writable.');
        }

        $verified = realpath($path);
        if ($verified === false || self::isInside($verified, (string)realpath($root))) {
            throw new RuntimeException('The private backup directory could not be verified.');
        }

        return $verified;
    }

    public static function isInside(string $candidate, string $root): bool
    {
        $normalise = static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/');
        $candidate = $normalise($candidate);
        $root = $normalise($root);
        $caseInsensitive = DIRECTORY_SEPARATOR === '\\';
        if ($caseInsensitive) {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate, $root . '/');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
