<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Service;

use OCA\MoodleTalkBridge\Db\RoomMap;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Db\UserMap;
use OCA\MoodleTalkBridge\Db\UserMapper;
use OCA\MoodleTalkBridge\Service\MembershipService;
use OCA\MoodleTalkBridge\Service\TalkService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * membership.revoke (Flow 5, D7): removing an un-enrolled user from the Talk
 * room. Idempotent — a missing user or room mapping is a no-op ("skipped"),
 * never an error, so re-delivery of the webhook is always safe.
 */
class MembershipServiceTest extends TestCase {
    private UserMapper&MockObject $userMapper;
    private RoomMapper&MockObject $roomMapper;
    private TalkService&MockObject $talkService;
    private MembershipService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->userMapper = $this->createMock(UserMapper::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->talkService = $this->createMock(TalkService::class);
        $this->service = new MembershipService(
            $this->userMapper, $this->roomMapper, $this->talkService,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testRevokeRemovesParticipant(): void {
        $userMap = new UserMap();
        $userMap->setNcUid('nc-jane');
        $this->userMapper->method('findByEmail')->with('jane@uni.edu')->willReturn($userMap);

        $room = new RoomMap();
        $room->setRoomToken('tok123');
        $this->roomMapper->method('findByActivityId')->with(42)->willReturn($room);

        $this->talkService->expects($this->once())->method('removeParticipant')
            ->with('tok123', 'nc-jane');

        $result = $this->service->revoke([
            'activity_id' => 42, 'user' => ['email' => 'jane@uni.edu'],
        ]);
        $this->assertSame('removed', $result);
    }

    public function testRevokeSkipsWhenNoUserMapping(): void {
        $this->userMapper->method('findByEmail')
            ->willThrowException(new DoesNotExistException('no user'));
        $this->talkService->expects($this->never())->method('removeParticipant');

        $result = $this->service->revoke([
            'activity_id' => 42, 'user' => ['email' => 'ghost@uni.edu'],
        ]);
        $this->assertSame('skipped', $result);
    }

    public function testRevokeSkipsWhenNoRoomMapping(): void {
        $userMap = new UserMap();
        $userMap->setNcUid('nc-jane');
        $this->userMapper->method('findByEmail')->willReturn($userMap);
        $this->roomMapper->method('findByActivityId')
            ->willThrowException(new DoesNotExistException('no room'));
        $this->talkService->expects($this->never())->method('removeParticipant');

        $result = $this->service->revoke([
            'activity_id' => 99, 'user' => ['email' => 'jane@uni.edu'],
        ]);
        $this->assertSame('skipped', $result);
    }
}
