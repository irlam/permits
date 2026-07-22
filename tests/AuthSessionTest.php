<?php
declare(strict_types=1);

use Permits\Db;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/Auth.php';

final class AuthSessionTest extends TestCase
{
    private string $databasePath;
    private ?Db $db = null;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'permits-auth-');
        self::assertNotFalse($path);
        $this->databasePath = $path;
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_SQLITE_PATH'] = $path;
        $_ENV['SESSION_IDLE_TIMEOUT'] = '7200';

        $this->db = new Db();
        $this->db->pdo->exec(
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                email TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT NOT NULL,
                status TEXT NOT NULL,
                last_login TEXT NULL
            )'
        );
        $insert = $this->db->pdo->prepare(
            'INSERT INTO users (id, email, name, role, status) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute(['active-user', 'active@example.test', 'Active User', 'user', 'active']);
        $insert->execute(['inactive-user', 'inactive@example.test', 'Inactive User', 'admin', 'inactive']);
        $insert->execute(['invalid-role', 'invalid@example.test', 'Invalid Role', 'owner', 'active']);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $GLOBALS['db'] = $this->db;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['db']);
        $this->db = null;
        gc_collect_cycles();
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function testActiveAccountRemainsAuthenticated(): void
    {
        $_SESSION = [
            'user_id' => 'active-user',
            'login_time' => time(),
            'last_activity_at' => time(),
        ];

        $auth = new Auth($this->db);
        self::assertTrue($auth->isLoggedIn());
        self::assertSame('active-user', $auth->getCurrentUser()['id'] ?? null);
    }

    public function testInactiveAndMissingAccountsAreDeauthenticated(): void
    {
        foreach (['inactive-user', 'invalid-role', 'missing-user'] as $id) {
            $_SESSION = [
                'user_id' => $id,
                'login_time' => time(),
                'last_activity_at' => time(),
            ];

            $auth = new Auth($this->db);
            self::assertFalse($auth->isLoggedIn());
            self::assertArrayNotHasKey('user_id', $_SESSION);
        }
    }

    public function testIdleSessionIsDeauthenticated(): void
    {
        $_ENV['SESSION_IDLE_TIMEOUT'] = '300';
        $_SESSION = [
            'user_id' => 'active-user',
            'login_time' => time() - 301,
            'last_activity_at' => time() - 301,
        ];

        $auth = new Auth($this->db);
        self::assertFalse($auth->isLoggedIn());
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testRoleResolverUsesDatabaseRoleAndRefreshesValidSession(): void
    {
        $_SESSION = [
            'user_id' => 'active-user',
            'user_role' => 'admin',
            'login_time' => time() - 60,
            'last_activity_at' => time() - 60,
        ];

        $auth = new Auth($this->db);
        self::assertNull($auth->userForRoles(['manager', 'admin']));
        self::assertSame('user', $_SESSION['user_role']);
        self::assertSame('active-user', $auth->userForRoles(['user'])['id'] ?? null);
        self::assertGreaterThan(time() - 5, $_SESSION['last_activity_at']);
    }
}
