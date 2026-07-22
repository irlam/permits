<?php
declare(strict_types=1);

namespace Permits;

use PDO;

/**
 * Validation and administrator continuity rules for user management.
 */
final class UserAccountPolicy
{
    public const ROLES = ['user', 'manager', 'admin'];
    public const STATUSES = ['active', 'inactive'];

    /**
     * @return array{email:string,name:string,role:string,status:string,errors:list<string>}
     */
    public static function validateProfile(string $email, string $name, string $role, string $status): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        $role = strtolower(trim($role));
        $status = strtolower(trim($status));
        $errors = [];

        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address (maximum 255 characters).';
        }

        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $controlCharacterMatch = preg_match('/[\x00-\x1F\x7F]/u', $name);
        if ($nameLength < 1 || $nameLength > 255 || $controlCharacterMatch !== 0) {
            $errors[] = 'Enter a name between 1 and 255 characters without control characters.';
        }

        if (!in_array($role, self::ROLES, true)) {
            $errors[] = 'Choose a valid user role.';
        }

        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Choose a valid account status.';
        }

        return compact('email', 'name', 'role', 'status', 'errors');
    }

    public static function passwordError(string $password, bool $required): ?string
    {
        if ($password === '') {
            return $required ? 'Enter a password.' : null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($password, '8bit') : strlen($password);
        if (
            $length < 12
            || $length > 4096
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
        ) {
            return 'Passwords must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.';
        }

        return null;
    }

    /** @param array<string,mixed> $target */
    public static function wouldRemoveLastActiveAdmin(
        PDO $pdo,
        array $target,
        ?string $newRole,
        ?string $newStatus,
        bool $deleting = false
    ): bool {
        $isActiveAdmin = strtolower((string)($target['role'] ?? '')) === 'admin'
            && strtolower((string)($target['status'] ?? '')) === 'active';
        if (!$isActiveAdmin) {
            return false;
        }

        $willRemainActiveAdmin = !$deleting
            && strtolower((string)$newRole) === 'admin'
            && strtolower((string)$newStatus) === 'active';
        if ($willRemainActiveAdmin) {
            return false;
        }

        $sql = "SELECT id FROM users WHERE role = 'admin' AND status = 'active'";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && $pdo->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }

        return count($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)) <= 1;
    }
}
