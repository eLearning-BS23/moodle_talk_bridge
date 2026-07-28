<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<UserMap>
 */
class UserMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'talkbridge_user_map', UserMap::class);
    }

    /**
     * Downstream consumers (Task 14) depend on exactly this signature.
     *
     * @throws DoesNotExistException if this email has never been resolved.
     */
    public function findByEmail(string $email): UserMap {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)));

        return $this->findEntity($qb);
    }

    // insert()/delete() are inherited from QBMapper as-is. A concurrent
    // "insert-or-update" isn't attempted here: QBMapper::insertOrUpdate()
    // only correctly updates an entity that already carries its row id
    // (e.g. one just loaded via findEntity), which a freshly-constructed
    // UserMap never does — calling it on a brand-new entity would throw on
    // the very race it's meant to handle. ProvisioningService::ensureUser()
    // avoids the race in practice by checking findByEmail() first.
}
