<?php
declare(strict_types=1);

use Permits\PushSubscriptionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionValidatorTest extends TestCase
{
    private string $publicKey;
    private string $authSecret;

    protected function setUp(): void
    {
        $this->publicKey = $this->base64Url("\x04" . str_repeat("\x21", 64));
        $this->authSecret = $this->base64Url(str_repeat("\x42", 16));
    }

    #[DataProvider('trustedEndpointProvider')]
    public function testTrustedBrowserPushEndpointsAreAccepted(string $endpoint): void
    {
        $validated = PushSubscriptionValidator::validate($endpoint, $this->publicKey, $this->authSecret);

        self::assertSame($endpoint, $validated['endpoint']);
        self::assertSame($this->publicKey, $validated['p256dh']);
        self::assertSame($this->authSecret, $validated['auth']);
    }

    /** @return array<string,array{string}> */
    public static function trustedEndpointProvider(): array
    {
        return [
            'Chromium' => ['https://fcm.googleapis.com/fcm/send/example-token'],
            'legacy Chromium' => ['https://android.googleapis.com/gcm/send/example-token'],
            'Firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/example-token'],
            'Safari' => ['https://web.push.apple.com/Qexample-token'],
            'Windows' => ['https://wns2-db5p.notify.windows.com/w/?token=example'],
        ];
    }

    #[DataProvider('unsafeEndpointProvider')]
    public function testUnsafeOrUnsupportedEndpointsAreRejected(string $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);
        PushSubscriptionValidator::validate($endpoint, $this->publicKey, $this->authSecret);
    }

    /** @return array<string,array{string}> */
    public static function unsafeEndpointProvider(): array
    {
        return [
            'plain HTTP' => ['http://fcm.googleapis.com/fcm/send/token'],
            'private IP' => ['https://127.0.0.1/push'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data'],
            'arbitrary host' => ['https://example.com/push'],
            'lookalike host' => ['https://fcm.googleapis.com.example.com/push'],
            'credentials' => ['https://user:pass@fcm.googleapis.com/push'],
            'nonstandard port' => ['https://fcm.googleapis.com:8443/push'],
            'missing token path' => ['https://fcm.googleapis.com/'],
        ];
    }

    public function testMalformedKeysAreRejected(): void
    {
        foreach ([
            ['', $this->authSecret],
            ['not+base64', $this->authSecret],
            [$this->base64Url(str_repeat("\x21", 65)), $this->authSecret],
            [$this->publicKey, $this->base64Url(str_repeat("\x42", 15))],
        ] as [$publicKey, $authSecret]) {
            try {
                PushSubscriptionValidator::validate(
                    'https://fcm.googleapis.com/fcm/send/token',
                    $publicKey,
                    $authSecret
                );
                self::fail('Malformed push keys should be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
