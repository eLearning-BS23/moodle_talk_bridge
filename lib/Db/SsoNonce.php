<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row per consumed SSO ticket nonce (single-use ledger, D12). UNIQUE
 * index on `nonce` — a second insert of the same nonce is a replay attempt
 * and must be rejected (\OCP\DB\Exception REASON_UNIQUE_CONSTRAINT_VIOLATION).
 *
 * @method string getNonce()
 * @method void setNonce(string $nonce)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getExpires()
 * @method void setExpires(int $expires)
 */
class SsoNonce extends Entity {
    protected string $nonce = '';
    protected string $uid = '';
    protected int $expires = 0;

    public function __construct() {
        $this->addType('nonce', 'string');
        $this->addType('uid', 'string');
        $this->addType('expires', 'integer');
    }
}
