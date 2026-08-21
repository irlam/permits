<?php
declare(strict_types=1);

use Permits\BackupStorage;
use PHPUnit\Framework\TestCase;

final class BackupStorageTest extends TestCase
{
    private string $sandbox;
    private string $root;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'permits-backup-' . bin2hex(random_bytes(6));
        $this->root = $this->sandbox . DIRECTORY_SEPARATOR . 'httpdocs';
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(glob($this->sandbox . DIRECTORY_SEPARATOR . '*') ?: []) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($this->sandbox);
    }

    public function testDefaultBackupFolderIsAProtectedSiblingOfTheDocumentRoot(): void
    {
        $path = BackupStorage::pathFromValue($this->root, '');

        self::assertFalse(BackupStorage::isInside($path, $this->root));
        self::assertStringEndsWith('permits-private-backups', str_replace('\\', '/', $path));
    }

    public function testConfiguredFolderInsideTheDocumentRootIsRejected(): void
    {
        $inside = $this->root . DIRECTORY_SEPARATOR . 'backups';

        $this->expectException(RuntimeException::class);
        BackupStorage::pathFromValue($this->root, $inside);
    }

    public function testEnsureCreatesPrivateWritableStorageOutsideTheDocumentRoot(): void
    {
        $previous = $_ENV['BACKUP_PATH'] ?? null;
        $_ENV['BACKUP_PATH'] = $this->sandbox . DIRECTORY_SEPARATOR . 'private-backups';
        try {
            $path = BackupStorage::ensure($this->root);
            self::assertDirectoryExists($path);
            self::assertTrue(is_writable($path));
            self::assertFalse(BackupStorage::isInside($path, $this->root));
        } finally {
            if ($previous === null) {
                unset($_ENV['BACKUP_PATH']);
            } else {
                $_ENV['BACKUP_PATH'] = $previous;
            }
            @rmdir($this->sandbox . DIRECTORY_SEPARATOR . 'private-backups');
        }
    }

    public function testConfiguredPrivateFolderCanBePassedDirectlyToEnsure(): void
    {
        $configured = $this->sandbox . DIRECTORY_SEPARATOR . 'configured-private-backups';

        $path = BackupStorage::ensure($this->root, $configured);

        self::assertSame(realpath($configured), $path);
        self::assertFalse(BackupStorage::isInside($path, $this->root));
        @rmdir($configured);
    }

    public function testInaccessibleConfiguredParentFailsWithoutLeakingAPhpWarning(): void
    {
        $warningRaised = false;
        set_error_handler(static function () use (&$warningRaised): bool {
            $warningRaised = true;
            return true;
        });

        try {
            BackupStorage::pathFromValue(
                $this->root,
                $this->sandbox . DIRECTORY_SEPARATOR . 'missing-parent' . DIRECTORY_SEPARATOR . 'backups'
            );
            self::fail('An inaccessible backup parent should be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('open_basedir', $exception->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertFalse($warningRaised);
    }
}
