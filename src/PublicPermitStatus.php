<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use RuntimeException;

/**
 * Privacy-safe projection of permits that are useful to people on site.
 *
 * This deliberately exposes no holder details, arbitrary form answers,
 * approval notes, private links or internal IDs.
 */
final class PublicPermitStatus
{
    /** @var array<int,string> */
    private const PENDING_STATUSES = [
        'pending',
        'pending_approval',
        'awaiting',
        'awaiting_approval',
        'awaiting_acceptance',
    ];

    /** @var array<int,string> */
    private const ACTIVE_STATUSES = [
        'active',
        'approved',
        'issued',
        'open',
    ];

    /** @var array<int,string> */
    private const SAFE_LOCATION_KEYS = [
        'location',
        'exactWorkLocation',
        'workLocation',
        'exactLocation',
        'siteLocation',
        'siteBlock',
        'area',
        'siteProject',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function current(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        $pending = implode("','", self::PENDING_STATUSES);
        $active = implode("','", self::ACTIVE_STATUSES);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $recentExpiredSince = date('Y-m-d H:i:s', time() - 86400);

        $sql = "
            SELECT
                f.ref_number,
                f.status,
                f.site_block,
                f.form_data,
                f.created_at,
                f.valid_from,
                f.valid_to,
                CASE
                    WHEN f.valid_to IS NOT NULL AND f.valid_to < {$now} THEN 1
                    ELSE 0
                END AS is_past_validity,
                COALESCE(ft.name, 'Permit') AS template_name
            FROM forms f
            LEFT JOIN form_templates ft ON ft.id = f.template_id
            WHERE f.requires_approval = 1
              AND (
                LOWER(f.status) IN ('{$pending}')
                OR (
                    LOWER(f.status) IN ('{$active}', 'suspended')
                    AND (f.valid_to IS NULL OR f.valid_to >= {$now})
                )
                OR (
                    LOWER(f.status) IN ('{$active}', 'suspended', 'awaiting_acceptance', 'expired')
                    AND f.valid_to IS NOT NULL
                    AND f.valid_to >= :recent_expired_since
                    AND f.valid_to < {$now}
                )
              )
            ORDER BY
                CASE
                    WHEN LOWER(f.status) = 'expired' OR (f.valid_to IS NOT NULL AND f.valid_to < {$now}) THEN 0
                    WHEN LOWER(f.status) = 'suspended' THEN 1
                    WHEN LOWER(f.status) IN ('{$pending}') THEN 2
                    ELSE 3
                END,
                COALESCE(f.valid_to, f.valid_from, f.created_at) DESC
            LIMIT {$limit}
        ";

        $statement = $pdo->prepare($sql);
        $statement->execute(['recent_expired_since' => $recentExpiredSince]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        foreach ($rows as $row) {
            $rawStatus = strtolower(trim((string)($row['status'] ?? '')));
            $pastValidity = (int)($row['is_past_validity'] ?? 0) === 1;
            if ($rawStatus === 'expired' || $pastValidity) {
                $category = 'expired';
                $statusLabel = 'Expired — Do Not Work';
            } elseif ($rawStatus === 'suspended') {
                $category = 'suspended';
                $statusLabel = 'Suspended — Do Not Work';
            } elseif ($rawStatus === 'awaiting_acceptance') {
                $category = 'pending';
                $statusLabel = 'Awaiting Holder Acceptance';
            } elseif (in_array($rawStatus, self::PENDING_STATUSES, true)) {
                $category = 'pending';
                $statusLabel = 'Pending Approval';
            } else {
                $category = 'active';
                $statusLabel = 'Active';
            }

            $result[] = [
                'reference' => trim((string)($row['ref_number'] ?? '')),
                'permit_type' => trim((string)($row['template_name'] ?? 'Permit')) ?: 'Permit',
                'location' => self::publicLocation($row),
                'status' => $category,
                'status_label' => $statusLabel,
                'submitted_at' => $row['created_at'] ?? null,
                'valid_from' => $row['valid_from'] ?? null,
                'valid_to' => $row['valid_to'] ?? null,
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function publicLocation(array $row): string
    {
        $siteBlock = trim((string)($row['site_block'] ?? ''));
        if ($siteBlock !== '') {
            return mb_substr($siteBlock, 0, 160, 'UTF-8');
        }

        $answers = json_decode((string)($row['form_data'] ?? ''), true);
        if (!is_array($answers)) {
            return '';
        }

        foreach (self::SAFE_LOCATION_KEYS as $key) {
            $value = $answers[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $location = trim((string)$value);
            if ($location !== '') {
                return mb_substr($location, 0, 160, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $permits
     * @return array{pending:int,active:int,suspended:int,expired:int,total:int}
     */
    public static function counts(array $permits): array
    {
        $pending = 0;
        $active = 0;
        $suspended = 0;
        $expired = 0;

        foreach ($permits as $permit) {
            if (($permit['status'] ?? '') === 'pending') {
                $pending++;
            } elseif (($permit['status'] ?? '') === 'active') {
                $active++;
            } elseif (($permit['status'] ?? '') === 'suspended') {
                $suspended++;
            } elseif (($permit['status'] ?? '') === 'expired') {
                $expired++;
            }
        }

        return [
            'pending' => $pending,
            'active' => $active,
            'suspended' => $suspended,
            'expired' => $expired,
            'total' => count($permits),
        ];
    }
}
