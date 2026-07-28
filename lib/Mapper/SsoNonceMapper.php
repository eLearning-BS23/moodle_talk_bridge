<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Mapper;

use OCA\MoodleTalkBridge\Db\SsoNonce;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * QBMapper over talkbridge_sso_nonce. insert() is the single-use claim:
 * the UNIQUE index on `nonce` turns a replayed ticket into a
 * \OCP\DB\Exception (REASON_UNIQUE_CONSTRAINT_VIOLATION) that
 * SsoController maps to HTTP 403.
 *
 * @extends QBMapper<SsoNonce>
 */
class SsoNonceMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'talkbridge_sso_nonce', SsoNonce::class);
    }
}
