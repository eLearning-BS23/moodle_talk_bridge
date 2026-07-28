<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Db;

use OCA\MoodleTalkBridge\Db\UserMap;
use OCA\MoodleTalkBridge\Db\UserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class UserMapperTest extends TestCase {
    private IDBConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private UserMapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('createNamedParameter')->willReturn('placeholder');

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq-expr');
        $this->qb->method('expr')->willReturn($expr);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->mapper = new UserMapper($this->db);
    }

    /** @param array<int, array|false> $rows successive fetchAssociative() returns */
    private function resultReturning(array $rows): IResult&MockObject {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...$rows);
        $result->method('closeCursor')->willReturn(true);
        return $result;
    }

    public function testFindByEmailReturnsMatch(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultReturning([
            ['id' => 1, 'email' => 'jose@uni.edu', 'nc_uid' => 'jose', 'provisioned' => false, 'created' => 100],
            false,
        ]));

        $map = $this->mapper->findByEmail('jose@uni.edu');
        $this->assertInstanceOf(UserMap::class, $map);
        $this->assertSame('jose', $map->getNcUid());
    }

    public function testFindByEmailThrowsWhenAbsent(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultReturning([false]));

        $this->expectException(DoesNotExistException::class);
        $this->mapper->findByEmail('nobody@uni.edu');
    }
}
