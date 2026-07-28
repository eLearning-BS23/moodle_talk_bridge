<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Service;

use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Db\UserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Handles membership.revoke (Flow 5, D7): remove an un-enrolled user from the
 * Talk room. Set-based and idempotent — a missing user or room mapping is a
 * harmless no-op ("skipped", HTTP 200), never an error, so re-delivery of the
 * webhook after the mapping has already vanished (or never existed) is safe.
 */
class MembershipService {

    public function __construct(
        private UserMapper $userMapper,
        private RoomMapper $roomMapper,
        private TalkService $talkService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{activity_id?:int,user?:array{email?:string}} $payload
     * @return string 'removed' | 'skipped'
     */
    public function revoke(array $payload): string {
        $email = (string) ($payload['user']['email'] ?? '');
        $activityId = (int) ($payload['activity_id'] ?? 0);

        try {
            $userMap = $this->userMapper->findByEmail($email);
        } catch (DoesNotExistException) {
            $this->logger->info('membership.revoke skipped: no user map for ' . $email);
            return 'skipped';
        }

        try {
            $room = $this->roomMapper->findByActivityId($activityId);
        } catch (DoesNotExistException) {
            $this->logger->info('membership.revoke skipped: no room map for activity ' . $activityId);
            return 'skipped';
        }

        $this->talkService->removeParticipant($room->getRoomToken(), $userMap->getNcUid());
        return 'removed';
    }
}
