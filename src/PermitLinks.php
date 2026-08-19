<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Symmetric links between permits for SIMOPS, dependencies and conflicts.
 */
final class PermitLinks
{
    /** @var array<string,string> */
    public const RELATION_TYPES = [
        'related' => 'Related work',
        'simops' => 'SIMOPS / simultaneous work',
        'isolation_dependency' => 'Isolation dependency',
        'conflict' => 'Conflict — must not run together',
    ];

    /** @return array<string,mixed> */
    public static function add(PDO $pdo, string $permitA, string $permitB, string $type, string $note, string $createdBy): array
    {
        $permitA = trim($permitA);
        $permitB = trim($permitB);
        $type = trim($type);
        $note = trim($note);
        if ($permitA === '' || $permitB === '' || hash_equals($permitA, $permitB)) {
            throw new RuntimeException('Choose two different permits to link.');
        }
        if (!isset(self::RELATION_TYPES[$type])) {
            throw new RuntimeException('Choose a valid permit relationship.');
        }
        if (mb_strlen($note, 'UTF-8') > 2000) {
            throw new RuntimeException('Link note is too long.');
        }

        $check = $pdo->prepare('SELECT id, requires_approval FROM forms WHERE id IN (?, ?)');
        $check->execute([$permitA, $permitB]);
        $rows = $check->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            throw new RuntimeException('One of the permits could not be found.');
        }
        foreach ($rows as $row) {
            if ((int)($row['requires_approval'] ?? 1) !== 1) {
                throw new RuntimeException('Inspection records cannot be linked as permits.');
            }
        }

        [$formA, $formB] = strcmp($permitA, $permitB) < 0 ? [$permitA, $permitB] : [$permitB, $permitA];
        $existing = $pdo->prepare('SELECT * FROM permit_links WHERE form_a_id = ? AND form_b_id = ? AND relation_type = ? LIMIT 1');
        $existing->execute([$formA, $formB, $type]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        $id = Uuid::uuid4()->toString();
        $insert = $pdo->prepare('INSERT INTO permit_links (id, form_a_id, form_b_id, relation_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$id, $formA, $formB, $type, $note, $createdBy]);

        return [
            'id' => $id,
            'form_a_id' => $formA,
            'form_b_id' => $formB,
            'relation_type' => $type,
            'note' => $note,
            'created_by' => $createdBy,
        ];
    }

    public static function remove(PDO $pdo, string $linkId): bool
    {
        $stmt = $pdo->prepare('DELETE FROM permit_links WHERE id = ?');
        $stmt->execute([$linkId]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public static function forPermit(PDO $pdo, string $permitId): array
    {
        $sql = "
            SELECT
                pl.id,
                pl.relation_type,
                pl.note,
                pl.created_by,
                pl.created_at,
                CASE WHEN pl.form_a_id = :permit_case THEN pl.form_b_id ELSE pl.form_a_id END AS linked_id,
                f.ref_number,
                f.status,
                f.valid_to,
                f.site_block,
                COALESCE(ft.name, 'Permit') AS template_name
            FROM permit_links pl
            INNER JOIN forms f
              ON f.id = CASE WHEN pl.form_a_id = :permit_join THEN pl.form_b_id ELSE pl.form_a_id END
            LEFT JOIN form_templates ft ON ft.id = f.template_id
            WHERE pl.form_a_id = :permit_a OR pl.form_b_id = :permit_b
            ORDER BY
                CASE pl.relation_type WHEN 'conflict' THEN 0 WHEN 'isolation_dependency' THEN 1 WHEN 'simops' THEN 2 ELSE 3 END,
                pl.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'permit_case' => $permitId,
            'permit_join' => $permitId,
            'permit_a' => $permitId,
            'permit_b' => $permitId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $type = (string)($row['relation_type'] ?? 'related');
            $row['relation_label'] = self::RELATION_TYPES[$type] ?? $type;
        }
        unset($row);
        return $rows;
    }

    /**
     * A conflict link is an explicit management decision that two permits must
     * not operate at the same time. Pending, awaiting-acceptance and suspended
     * permits are visible for coordination but do not themselves represent work
     * in operation, so only a currently active conflicting permit blocks start.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function blockingConflicts(PDO $pdo, string $permitId): array
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $sql = "
            SELECT
                f.id,
                f.ref_number,
                f.status,
                f.valid_to,
                COALESCE(ft.name, 'Permit') AS template_name
            FROM permit_links pl
            INNER JOIN forms f
              ON f.id = CASE WHEN pl.form_a_id = :permit_join THEN pl.form_b_id ELSE pl.form_a_id END
            LEFT JOIN form_templates ft ON ft.id = f.template_id
            WHERE (pl.form_a_id = :permit_a OR pl.form_b_id = :permit_b)
              AND pl.relation_type = 'conflict'
              AND LOWER(f.status) IN ('active', 'issued', 'approved', 'open')
              AND (f.valid_to IS NULL OR f.valid_to > {$now})
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'permit_join' => $permitId,
            'permit_a' => $permitId,
            'permit_b' => $permitId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public static function findByReference(PDO $pdo, string $reference): ?array
    {
        $reference = strtoupper(ltrim(trim($reference), '#'));
        if ($reference === '') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT id, ref_number, status, template_id FROM forms WHERE UPPER(TRIM(ref_number)) = ? AND requires_approval = 1 LIMIT 1");
        $stmt->execute([$reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
