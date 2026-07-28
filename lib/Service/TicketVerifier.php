<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Service;

use OCA\MoodleTalkBridge\Exception\InvalidTicketException;

/**
 * Verifies the D12 SSO ticket minted by Moodle's \mod_nextcloudtalk\local\ticket.
 *
 * Ticket = base64url(canonicalJson(claims)) . "." . "sha256=" . hex-hmac-sha256(rawJsonBody, secret).
 * The HMAC is computed over the RAW (base64url-decoded) JSON bytes, never
 * over the base64 string itself — this mirrors the Moodle signer
 * byte-for-byte (Task 11). See authentication-and-security.md §3.
 */
class TicketVerifier {

    /**
     * @return array<string,mixed> decoded claims
     * @throws InvalidTicketException on malformed / bad-signature / expired ticket
     */
    public function verify(string $ticket, string $secret, int $nowTs): array {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            throw new InvalidTicketException('malformed ticket');
        }
        [$b64body, $sig] = $parts;
        $body = $this->b64urlDecode($b64body);
        if ($body === '') {
            throw new InvalidTicketException('malformed ticket body');
        }

        // Strip the "sha256=" prefix before comparing the raw hex digest.
        $received = str_starts_with($sig, 'sha256=')
            ? substr($sig, strlen('sha256='))
            : $sig;
        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $received)) {
            throw new InvalidTicketException('bad signature');
        }

        $claims = json_decode($body, true);
        if (!is_array($claims) || !isset($claims['exp'])) {
            throw new InvalidTicketException('bad claims');
        }
        if ((int)$claims['exp'] < $nowTs) {
            throw new InvalidTicketException('expired');
        }
        return $claims;
    }

    private function b64urlDecode(string $data): string {
        $pad = strlen($data) % 4;
        if ($pad !== 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
