<?php
declare(strict_types=1);

use Permits\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testTokenIsStableForAnActionAndScoped(): void
    {
        $token = Csrf::generateToken('save-settings');
        self::assertSame($token, Csrf::generateToken('save-settings'));
        self::assertTrue(Csrf::validateToken($token, 'save-settings'));
        self::assertFalse(Csrf::validateToken($token, 'delete-user'));
    }

    public function testRequestAcceptsFormFieldAndHeader(): void
    {
        $formToken = Csrf::generateToken('form-action');
        $_POST['csrf_token'] = $formToken;
        self::assertTrue(Csrf::validateRequest('form-action'));

        $_POST = [];
        $apiToken = Csrf::generateToken('api-action');
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $apiToken;
        self::assertTrue(Csrf::validateRequest('api-action'));
    }

    public function testConsumedTokenCannotBeReused(): void
    {
        $token = Csrf::generateToken('login');
        self::assertTrue(Csrf::validateToken($token, 'login', true));
        self::assertFalse(Csrf::validateToken($token, 'login'));
    }

    public function testFormFieldEscapesAndNamesToken(): void
    {
        $field = Csrf::getFormField('profile');
        self::assertStringContainsString('name="csrf_token"', $field);
        self::assertMatchesRegularExpression('/value="[a-f0-9]{64}"/', $field);
    }
}
