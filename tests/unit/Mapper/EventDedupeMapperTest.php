<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Mapper;

use OCA\MoodleTalkBridge\Mapper\EventDedupeMapper;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * A stand-in for the unique-constraint-violation subclass Nextcloud throws
 * internally; getReason() is not settable via the base Exception's
 * constructor, so we override it here to drive the two claim() branches.
 */
class FakeDbException extends DBException {
    public function __construct(private ?int $reason) {
        parent::__construct('db error');
    }

    public function getReason(): ?int {
        return $this->reason;
    }
}

class EventDedupeMapperTest extends TestCase {
    private IDBConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private EventDedupeMapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->qb->method('insert')->willReturnSelf();
        $this->qb->method('values')->willReturnSelf();
        $this->qb->method('createNamedParameter')->willReturn('placeholder');
        $this->db->method('getQueryBuilder')->willReturn($this->qb);

        $this->mapper = new EventDedupeMapper($this->db);
    }

    public function testClaimReturnsTrueOnFirstSighting(): void {
        $this->qb->method('executeStatement')->willReturn(1);

        $this->assertTrue($this->mapper->claim('evt_1'));
    }

    public function testClaimReturnsFalseOnUniqueConstraintViolation(): void {
        $this->qb->method('executeStatement')
            ->willThrowException(new FakeDbException(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION));

        $this->assertFalse($this->mapper->claim('evt_1'));
    }

    public function testClaimRethrowsOtherDbExceptions(): void {
        $this->qb->method('executeStatement')
            ->willThrowException(new FakeDbException(DBException::REASON_CONNECTION_LOST));

        $this->expectException(DBException::class);
        $this->mapper->claim('evt_1');
    }
}
