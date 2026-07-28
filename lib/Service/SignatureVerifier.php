<?php

declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Service;

/**
 * Verifies HMAC-signed webhook envelopes. Byte-for-byte counterpart of the
 * Moodle \mod_nextcloudtalk\local\signer; both ends are pinned by the shared
 * fixtures in specs/02-integration/fixtures/.
 */
class SignatureVerifier {

    /** Replay window, seconds. */
    private const WINDOW = 300;

    /**
     * @param string $body raw canonical body as received on the wire.
     * @param string $signature signature from X-Moodle-Signature, "sha256=<hex>".
     * @param int $timestamp X-Moodle-Timestamp (unix seconds).
     * @param string $nonce X-Moodle-Nonce, bound into the signed message.
     * @param string $secret shared HMAC secret.
     * @param int $nowTs current unix time.
     * @return bool true only if fresh and the signature matches.
     */
    public function verify(string $body, string $signature, int $timestamp, string $nonce, string $secret, int $nowTs): bool {
        if (abs($nowTs - $timestamp) > self::WINDOW) {
            return false;
        }
        // Strip the "sha256=" prefix before comparing the raw hex digest.
        $received = str_starts_with($signature, 'sha256=')
            ? substr($signature, strlen('sha256='))
            : $signature;
        $expected = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
        return hash_equals($expected, $received);
    }
}
