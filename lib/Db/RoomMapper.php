<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomMap>
 */
class RoomMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'talkbridge_room_map', RoomMap::class);
    }

    /**
     * Lookup by activity_id alone. Downstream consumers (Tasks 12/14/17)
     * depend on exactly this signature/behaviour.
     *
     * @throws DoesNotExistException if no room is mapped for this activity.
     */
    public function findByActivityId(int $activityId): RoomMap {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Idempotency lookup keyed on (activity_host, activity_id) — matches the
     * unique index tb_roommap_activity_ux. Used by RoomOrchestrator to
     * decide create-vs-skip; returns null (rather than throwing) since a
     * miss here is the expected, common case, not an error.
     */
    public function findByHostAndActivityId(string $activityHost, int $activityId): ?RoomMap {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('activity_host', $qb->createNamedParameter($activityHost)))
            ->andWhere($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
