<?php

declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit;

use OCA\MoodleTalkBridge\Service\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase {

    private const FIXTURE_DIR = __DIR__ . '/../../../../specs/02-integration/fixtures';

    /** @var array{secret:string,now_ts:int,events:string[]} */
    private array $manifest;

    protected function setUp(): void {
        $manifestPath = self::FIXTURE_DIR . '/manifest.json';
        $this->assertFileExists($manifestPath, 'Run: php specs/02-integration/fixtures/generate.php');
        $this->manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function eventProvider(): array {
        return [
            'room.ensure' => ['room.ensure'],
            'membership.revoke' => ['membership.revoke'],
            'room.archive' => ['room.archive'],
        ];
    }

    /**
     * @dataProvider eventProvider
     */
    public function testValidFixtureVerifiesTrue(string $event): void {
        $verifier = new SignatureVerifier();
        [$body, $sig, $ts, $nonce, $secret] = $this->loadFixture($event);

        // nowTs == ts: inside the 300s window, signature matches -> true.
        $this->assertTrue($verifier->verify($body, $sig, $ts, $nonce, $secret, $ts));
    }

    /**
     * @dataProvider eventProvider
     */
    public function testTamperedBodyVerifiesFalse(string $event): void {
        $verifier = new SignatureVerifier();
        [$body, $sig, $ts, $nonce, $secret] = $this->loadFixture($event);

        $tampered = $body . ' ';
        $this->assertFalse($verifier->verify($tampered, $sig, $ts, $nonce, $secret, $ts));
    }

    /**
     * @dataProvider eventProvider
     */
    public function testStaleTimestampVerifiesFalse(string $event): void {
        $verifier = new SignatureVerifier();
        [$body, $sig, $ts, $nonce, $secret] = $this->loadFixture($event);

        // 301s in the future -> outside the +/-300s window -> false, even though
        // the signature itself is valid.
        $this->assertFalse($verifier->verify($body, $sig, $ts, $nonce, $secret, $ts + 301));
    }

    /**
     * @dataProvider eventProvider
     */
    public function testWrongNonceVerifiesFalse(string $event): void {
        $verifier = new SignatureVerifier();
        [$body, $sig, $ts, $nonce, $secret] = $this->loadFixture($event);

        // The nonce is bound into the signed message; a different nonce must
        // fail even though timestamp/body/secret are all correct.
        $this->assertFalse($verifier->verify($body, $sig, $ts, $nonce . 'x', $secret, $ts));
    }

    /**
     * @return array{0:string,1:string,2:int,3:string,4:string} [body, signature, timestamp, nonce, secret]
     */
    private function loadFixture(string $event): array {
        $path = self::FIXTURE_DIR . '/' . $event . '.json';
        $this->assertFileExists($path, 'Run: php specs/02-integration/fixtures/generate.php');
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return [
            $fixture['body'],
            $fixture['headers']['X-Moodle-Signature'],
            (int) $fixture['headers']['X-Moodle-Timestamp'],
            (string) $fixture['headers']['X-Moodle-Nonce'],
            $this->manifest['secret'],
        ];
    }
}
