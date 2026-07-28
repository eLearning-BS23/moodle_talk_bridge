<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Mapper;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Idempotency ledger over talkbridge_event_dedupe.
 */
class EventDedupeMapper {
    public function __construct(private IDBConnection $db) {
    }

    /**
     * Atomically claim an event_id. Returns false if it was already seen
     * (unique-constraint violation), true if this is the first sighting.
     */
    public function claim(string $eventId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('talkbridge_event_dedupe')
            ->values([
                'event_id'    => $qb->createNamedParameter($eventId),
                'received_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
            return true;
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return false;
            }
            throw $e;
        }
    }
}
