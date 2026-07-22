<?php
declare(strict_types=1);

namespace Permits;

/**
 * Central permit visibility rules used by dashboard queries.
 */
final class PermitAccess
{
    /** @param array<string,mixed> $user */
    public static function canViewAll(array $user): bool
    {
        return in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'manager'], true);
    }

    /**
     * Apply the dashboard ownership rule to an already loaded permit.
     * Authentication and active-account checks remain the caller's responsibility.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $permit
     */
    public static function canAccessPermit(array $user, array $permit): bool
    {
        if (self::canViewAll($user)) {
            return true;
        }

        $userId = (string)($user['id'] ?? '');
        if ($userId !== '') {
            foreach (['holder_id', 'issuer_id'] as $ownerColumn) {
                $permitUserId = (string)($permit[$ownerColumn] ?? '');
                if ($permitUserId !== '' && hash_equals($permitUserId, $userId)) {
                    return true;
                }
            }
        }

        $userEmail = strtolower(trim((string)($user['email'] ?? '')));
        $permitEmail = strtolower(trim((string)($permit['holder_email'] ?? '')));

        return $userEmail !== '' && $permitEmail !== '' && hash_equals($permitEmail, $userEmail);
    }

    /**
     * Return a parameterised SQL predicate and bindings for the user's permits.
     * A permit belongs to a regular user when they are its holder, its issuer,
     * or the permit's holder email matches their account email.
     *
     * @param array<string,mixed> $user
     * @return array{sql:string,params:array<string,string>}
     */
    public static function sqlScope(array $user, string $alias = 'f', string $prefix = 'scope'): array
    {
        if (self::canViewAll($user)) {
            return ['sql' => '1 = 1', 'params' => []];
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL table alias.');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL parameter prefix.');
        }

        $id = (string)($user['id'] ?? '');
        $email = strtolower(trim((string)($user['email'] ?? '')));

        return [
            'sql' => sprintf(
                '(%1$s.holder_id = :%2$s_holder OR %1$s.issuer_id = :%2$s_issuer OR LOWER(TRIM(%1$s.holder_email)) = :%2$s_email)',
                $alias,
                $prefix
            ),
            'params' => [
                $prefix . '_holder' => $id,
                $prefix . '_issuer' => $id,
                $prefix . '_email' => $email,
            ],
        ];
    }
}
