<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Service;

use OCA\MoodleTalkBridge\Exception\InvalidTicketException;
use OCA\MoodleTalkBridge\Service\TicketVerifier;
use Test\TestCase;

class TicketVerifierTest extends TestCase {
    private TicketVerifier $verifier;
    private string $secret = 's3cr3t-shared';

    protected function setUp(): void {
        parent::setUp();
        $this->verifier = new TicketVerifier();
    }

    /** @param array<string,mixed> $claims */
    private function makeTicket(array $claims, ?string $secret = null): string {
        $body = json_encode($claims, JSON_UNESCAPED_SLASHES);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret ?? $this->secret);
        $b64 = rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
        return $b64 . '.' . $sig;
    }

    public function testValidTicketReturnsClaims(): void {
        $claims = [
            'email' => 'jane@uni.edu', 'displayname' => 'Jane Doe',
            'activity_id' => 42, 'role' => 'participant',
            'exp' => 2000000000, 'nonce' => 'n-1',
        ];
        $out = $this->verifier->verify($this->makeTicket($claims), $this->secret, 1000);
        $this->assertSame('jane@uni.edu', $out['email']);
        $this->assertSame(42, $out['activity_id']);
        $this->assertSame('n-1', $out['nonce']);
    }

    public function testTamperedTicketThrows(): void {
        $ticket = $this->makeTicket(['exp' => 2000000000, 'nonce' => 'n']);
        $this->expectException(InvalidTicketException::class);
        $this->verifier->verify($ticket . 'deadbeef', $this->secret, 1000);
    }

    public function testWrongSecretThrows(): void {
        $ticket = $this->makeTicket(['exp' => 2000000000, 'nonce' => 'n'], 'other-secret');
        $this->expectException(InvalidTicketException::class);
        $this->verifier->verify($ticket, $this->secret, 1000);
    }

    public function testExpiredTicketThrows(): void {
        $ticket = $this->makeTicket(['exp' => 500, 'nonce' => 'n']);
        $this->expectException(InvalidTicketException::class);
        $this->verifier->verify($ticket, $this->secret, 1000);
    }

    public function testTicketWithNoDotThrows(): void {
        $this->expectException(InvalidTicketException::class);
        $this->verifier->verify('nodothere', $this->secret, 1000);
    }

    public function testTicketWithExtraDotThrows(): void {
        $ticket = $this->makeTicket(['exp' => 2000000000, 'nonce' => 'n']);
        $this->expectException(InvalidTicketException::class);
        // Append an extra "." segment so the ticket splits into 3+ parts.
        $this->verifier->verify($ticket . '.extra', $this->secret, 1000);
    }

    public function testEmptySignaturePartThrows(): void {
        $body = json_encode(['exp' => 2000000000, 'nonce' => 'n'], JSON_UNESCAPED_SLASHES);
        $b64 = rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
        $this->expectException(InvalidTicketException::class);
        $this->verifier->verify($b64 . '.', $this->secret, 1000);
    }

    public function testNonBase64UrlBodyThrows(): void {
        $this->expectException(InvalidTicketException::class);
        // "!!!" is not valid base64url alphabet; decoding must fail cleanly.
        $this->verifier->verify('!!!.sha256=deadbeef', $this->secret, 1000);
    }
}
