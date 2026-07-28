<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Db;

use OCA\MoodleTalkBridge\Db\RoomMap;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RoomMapperTest extends TestCase {
    private IDBConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private RoomMapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('createNamedParameter')->willReturn('placeholder');

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq-expr');
        $this->qb->method('expr')->willReturn($expr);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->mapper = new RoomMapper($this->db);
    }

    /** @param array<int, array|false> $rows successive fetchAssociative() returns */
    private function resultReturning(array $rows): IResult&MockObject {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...$rows);
        $result->method('closeCursor')->willReturn(true);
        return $result;
    }

    private function row(): array {
        return [
            'id' => 1, 'activity_id' => 7, 'activity_host' => 'moodle.local',
            'room_token' => 'tok', 'teacher_uid' => 'uid', 'created' => 100,
        ];
    }

    public function testFindByActivityIdReturnsMatch(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultReturning([$this->row(), false]));

        $room = $this->mapper->findByActivityId(7);
        $this->assertInstanceOf(RoomMap::class, $room);
        $this->assertSame('tok', $room->getRoomToken());
        $this->assertSame(7, $room->getActivityId());
    }

    public function testFindByActivityIdThrowsWhenAbsent(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultReturning([false]));

        $this->expectException(DoesNotExistException::class);
        $this->mapper->findByActivityId(999);
    }

    public function testFindByHostAndActivityIdReturnsMatch(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultReturning([$this->row(), false]));

        $room = $this->mapper->findByHostAndActivityId('moodle.local', 7);
        $this->assertInstanceOf(RoomMap::class, $room);
        $this->assertSame('tok', $room->getRoomToken());
    }

    public function testFindByHostAndActivityIdReturnsNullOnMiss(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultReturning([false]));

        $this->assertNull($this->mapper->findByHostAndActivityId('moodle.local', 999));
    }
}
