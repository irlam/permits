<?php
declare(strict_types=1);

namespace Permits;

/** Convert technical audit entries into concise dashboard copy. */
final class DashboardActivityPresenter
{
    /** @param array<string,mixed> $activity
     *  @return array{title:string,description:string}
     */
    public static function present(array $activity): array
    {
        $action = strtolower(trim((string)($activity['action'] ?? '')));
        $description = trim((string)($activity['description'] ?? ''));

        $titles = [
            'holder_accepted' => 'Accepted by permit holder',
            'permit_approved' => 'Permit approved',
            'permit_revalidated' => 'Permit revalidated',
            'permit_suspended' => 'Permit suspended',
            'public_permit_created' => 'New permit submitted',
            'public_permit_draft_saved' => 'Permit draft saved',
            'user_login' => 'Team member signed in',
            'user_logout' => 'Team member signed out',
            'work_started' => 'Work started',
        ];

        if ($action === 'user_login') {
            $description = 'A team member signed in to the permit system.';
        } elseif ($action === 'user_logout') {
            $description = 'A team member signed out of the permit system.';
        } else {
            // Entity UUIDs and audit categories are valuable in the full audit
            // log, but are visual noise in this small dashboard summary.
            $description = preg_replace(
                '/\s*\[(?:form|user|permit):[0-9a-f-]{16,}\]\s*/i',
                ' ',
                $description
            ) ?? $description;
            $description = preg_replace('/\s*\([a-z][a-z0-9_-]*\)\s*$/i', '', $description) ?? $description;

            $description = str_ireplace(
                'Permit holder/receiver acceptance recorded',
                'The permit holder accepted responsibility for the permit.',
                $description
            );
            $description = str_ireplace(
                'Suspended permit revalidated; holder re-acceptance required',
                'The permit was revalidated. The holder must accept it again before work resumes.',
                $description
            );
            $description = str_ireplace(
                '; holder acceptance required',
                '. The permit holder must accept it before work starts.',
                $description
            );
            $description = trim((string)preg_replace('/\s+/', ' ', $description));
        }

        if ($description === '') {
            $description = 'Activity recorded in the permit system.';
        }

        return [
            'title' => $titles[$action] ?? self::titleFromAction($action),
            'description' => $description,
        ];
    }

    private static function titleFromAction(string $action): string
    {
        if ($action === '') {
            return 'System activity';
        }

        return ucwords(str_replace(['_', '-'], ' ', $action));
    }
}
