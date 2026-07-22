<?php
declare(strict_types=1);

namespace Permits;

use InvalidArgumentException;
use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Creates the initial administrator without shipping a shared default password.
 */
final class AdminProvisioner
{
    private const UPPERCASE = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWERCASE = 'abcdefghijkmnopqrstuvwxyz';
    private const NUMBERS = '23456789';
    private const SYMBOLS = '!@#$%&*+-=?';

    /**
     * @return array{id:string,email:string,name:string,password:string}
     */
    public static function createFirstAdmin(PDO $pdo, string $email, string $name = 'Administrator'): array
    {
        $email = self::validatedEmail($email);
        $name = self::validatedName($name);
        $ownsTransaction = !$pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $adminSql = "SELECT id, email FROM users WHERE role = 'admin' LIMIT 1";
            if ($driver === 'mysql') {
                // Serialise competing first-admin attempts on supported InnoDB installs.
                $adminSql .= ' FOR UPDATE';
            }

            $existingAdmin = $pdo->query($adminSql)->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingAdmin)) {
                throw new RuntimeException(
                    'An administrator already exists. Sign in with that account or use the authenticated user-management screen.'
                );
            }

            $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $emailCheck->execute([$email]);
            if ($emailCheck->fetchColumn() !== false) {
                throw new RuntimeException('That email address already belongs to an account; no changes were made.');
            }

            $password = self::generateOneTimePassword();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException('The administrator password could not be secured. No account was created.');
            }

            $id = Uuid::uuid4()->toString();
            $insert = $pdo->prepare(
                "INSERT INTO users (id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')"
            );
            $insert->execute([$id, $email, $hash, $name]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'id' => $id,
                'email' => $email,
                'name' => $name,
                'password' => $password,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException(
                'The administrator could not be created. Confirm that database/database.sql has been imported and try again.',
                0,
                $e
            );
        }
    }

    public static function generateOneTimePassword(int $length = 24): string
    {
        if ($length < 16) {
            throw new InvalidArgumentException('Generated passwords must contain at least 16 characters.');
        }

        $characters = [
            self::randomCharacter(self::UPPERCASE),
            self::randomCharacter(self::LOWERCASE),
            self::randomCharacter(self::NUMBERS),
            self::randomCharacter(self::SYMBOLS),
        ];
        $alphabet = self::UPPERCASE . self::LOWERCASE . self::NUMBERS . self::SYMBOLS;

        while (count($characters) < $length) {
            $characters[] = self::randomCharacter($alphabet);
        }

        // Fisher-Yates with random_int keeps the final positions unpredictable.
        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swapWith = random_int(0, $index);
            [$characters[$index], $characters[$swapWith]] = [$characters[$swapWith], $characters[$index]];
        }

        return implode('', $characters);
    }

    private static function validatedEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid administrator email address (maximum 255 characters).');
        }

        return $email;
    }

    private static function validatedName(string $name): string
    {
        $name = trim($name);
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $length > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('Enter an administrator name between 1 and 255 characters without control characters.');
        }

        return $name;
    }

    private static function randomCharacter(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }
}
