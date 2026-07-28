<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Service;

use OCA\MoodleTalkBridge\Db\RoomMap;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Service\ProvisioningService;
use OCA\MoodleTalkBridge\Service\RoomOrchestrator;
use OCA\MoodleTalkBridge\Service\TalkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use Test\TestCase;

final class RoomOrchestratorArchiveTest extends TestCase {
    public function testArchiveDeletesRoomAndDropsMapRow(): void {
        $row = new RoomMap();
        $row->setActivityId(42);
        $row->setRoomToken('tok_abc');

        $roomMapper = $this->createMock(RoomMapper::class);
        $roomMapper->method('findByActivityId')->with(42)->willReturn($row);
        $roomMapper->expects($this->once())->method('delete')->with($row);

        $talk = $this->createMock(TalkService::class);
        $talk->expects($this->once())->method('deleteRoom')->with('tok_abc');

        $orchestrator = new RoomOrchestrator(
            $talk,
            $this->createMock(ProvisioningService::class),
            $roomMapper,
            $this->createMock(IConfig::class));

        $result = $orchestrator->archiveRoom(['activity_id' => 42]);
        $this->assertSame('archived', $result['status']);
    }

    public function testArchiveIsIdempotentWhenNoMapping(): void {
        $roomMapper = $this->createMock(RoomMapper::class);
        $roomMapper->method('findByActivityId')->with(99)->willThrowException(new DoesNotExistException('none'));
        $roomMapper->expects($this->never())->method('delete');

        $talk = $this->createMock(TalkService::class);
        $talk->expects($this->never())->method('deleteRoom');

        $orchestrator = new RoomOrchestrator(
            $talk,
            $this->createMock(ProvisioningService::class),
            $roomMapper,
            $this->createMock(IConfig::class));

        $result = $orchestrator->archiveRoom(['activity_id' => 99]);
        $this->assertSame('skipped', $result['status']);
    }
}
