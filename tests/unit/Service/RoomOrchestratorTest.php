<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Service;

use OCA\MoodleTalkBridge\Db\RoomMap;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Exception\ValidationException;
use OCA\MoodleTalkBridge\Service\ProvisioningService;
use OCA\MoodleTalkBridge\Service\RoomOrchestrator;
use OCA\MoodleTalkBridge\Service\TalkService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RoomOrchestratorTest extends TestCase {
    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array {
        return array_replace([
            'activity_id' => 7, 'activity_name' => 'W1', 'course_shortname' => 'CS101',
            'teacher' => ['email' => 't@uni.edu', 'displayname' => 'T'],
            'lobby' => true, 'lecture_mode' => false, 'participants_expected' => 2,
        ], $overrides);
    }

    /** @return array{0:RoomOrchestrator,1:TalkService&MockObject,2:ProvisioningService&MockObject,3:RoomMapper&MockObject} */
    private function make(?RoomMap $existing) {
        $talk = $this->createMock(TalkService::class);
        $prov = $this->createMock(ProvisioningService::class);
        $roomMapper = $this->createMock(RoomMapper::class);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('moodle.local');
        $roomMapper->method('findByHostAndActivityId')->willReturn($existing);

        $orch = new RoomOrchestrator($talk, $prov, $roomMapper, $config);
        return [$orch, $talk, $prov, $roomMapper];
    }

    public function testIdempotentSecondCallSkipsWithNoSideEffects(): void {
        $existing = new RoomMap();
        $existing->setRoomToken('tokExisting');
        [$orch, $talk, $prov, $roomMapper] = $this->make($existing);

        $talk->expects($this->never())->method('createGroupRoom');
        $talk->expects($this->never())->method('addParticipant');
        $talk->expects($this->never())->method('promoteToModerator');
        $prov->expects($this->never())->method('ensureUser');
        // Regression guard: a skip must not touch persistence at all.
        $roomMapper->expects($this->never())->method('insert');
        // Lecture-mode state was already applied on the first ensure (if at
        // all) — a re-ensure must not re-apply it.
        $talk->expects($this->never())->method('enableLobby');
        $talk->expects($this->never())->method('setDefaultPermissions');
        // The welcome notice is posted only at creation, never on a re-ensure.
        $talk->expects($this->never())->method('postMessage');

        $result = $orch->ensureRoom($this->payload(['lecture_mode' => true]));
        $this->assertSame(['status' => 'skipped', 'room_token' => 'tokExisting'], $result);
    }

    public function testFirstCallProvisionsCreatesPromotesInsertsInOrder(): void {
        [$orch, $talk, $prov, $roomMapper] = $this->make(null);
        $order = [];
        $prov->method('ensureUser')->willReturnCallback(function () use (&$order) {
            $order[] = 'ensureUser';
            return 'teacherUid';
        });
        $talk->method('createGroupRoom')->willReturnCallback(function () use (&$order) {
            $order[] = 'createGroupRoom';
            return 'tokNew';
        });
        // A teacher must be a room participant before they can be promoted
        // to moderator (pinned live: promoting a non-participant 404s /
        // errors resolving attendeeId — see task-10-report.md).
        $talk->expects($this->once())->method('addParticipant')
            ->with('tokNew', 'teacherUid')
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'addParticipant';
            });
        $talk->method('promoteToModerator')->willReturnCallback(function () use (&$order) {
            $order[] = 'promoteToModerator';
        });
        $roomMapper->expects($this->once())->method('insert')
            ->with($this->callback(function (RoomMap $map) use (&$order): bool {
                $order[] = 'insert';
                return $map->getActivityId() === 7
                    && $map->getActivityHost() === 'moodle.local'
                    && $map->getRoomToken() === 'tokNew'
                    && $map->getTeacherUid() === 'teacherUid';
            }));
        // A welcome/how-to-start notice is posted into the new room.
        $talk->expects($this->once())->method('postMessage')
            ->with('tokNew', $this->stringContains('Start call'));

        $result = $orch->ensureRoom($this->payload());
        $this->assertSame(['status' => 'applied', 'room_token' => 'tokNew'], $result);
        $this->assertSame(
            ['ensureUser', 'createGroupRoom', 'addParticipant', 'promoteToModerator', 'insert'],
            $order);
    }

    public function testMissingTeacherEmailThrows422(): void {
        [$orch] = $this->make(null);
        $payload = $this->payload();
        unset($payload['teacher']['email']);
        $this->expectException(ValidationException::class);
        $orch->ensureRoom($payload);
    }

    public function testEmptyTeacherEmailSkipsProvisioningAndPromotion(): void {
        // D-empty-teacher: Task 8 may emit '' for teacher.email/displayname
        // when there is no editing teacher enrolled. Rather than crash (or
        // silently 422, which would block the room existing at all), the
        // room is still created but provisioning/promotion is skipped.
        [$orch, $talk, $prov, $roomMapper] = $this->make(null);
        $prov->expects($this->never())->method('ensureUser');
        $talk->expects($this->never())->method('addParticipant');
        $talk->expects($this->never())->method('promoteToModerator');
        $talk->method('createGroupRoom')->willReturn('tokNew');
        $roomMapper->expects($this->once())->method('insert')
            ->with($this->callback(fn (RoomMap $map): bool => $map->getTeacherUid() === null));

        $payload = $this->payload();
        $payload['teacher'] = ['email' => '', 'displayname' => ''];

        $result = $orch->ensureRoom($payload);
        $this->assertSame(['status' => 'applied', 'room_token' => 'tokNew'], $result);
    }

    public function testRoomNameOmitsLeadingSeparatorWhenCourseShortnameEmpty(): void {
        [$orch, $talk] = $this->make(null);
        $talk->expects($this->once())->method('createGroupRoom')->with('W1')->willReturn('tokNew');

        $payload = $this->payload();
        $payload['course_shortname'] = '';
        $payload['teacher'] = ['email' => '', 'displayname' => ''];

        $orch->ensureRoom($payload);
    }

    public function testRoomNameIncludesCourseShortnameWhenPresent(): void {
        [$orch, $talk] = $this->make(null);
        $talk->expects($this->once())->method('createGroupRoom')->with('CS101 - W1')->willReturn('tokNew');

        $payload = $this->payload();
        $payload['teacher'] = ['email' => '', 'displayname' => ''];

        $orch->ensureRoom($payload);
    }

    /**
     * Lecture mode (D6): on the FIRST ensure, a forced flag or an expected
     * class size over the threshold locks the lobby and mutes non-moderators
     * by default. Small classes touch neither. A re-ensure short-circuits and
     * re-applies nothing (see testIdempotentSecondCallSkipsWithNoSideEffects
     * above, which already asserts enableLobby/setDefaultPermissions never()).
     */
    public function testLectureModeEnablesLobbyAndMutesDefaults(): void {
        [$orch, $talk, $prov] = $this->make(null);
        $prov->method('ensureUser')->willReturn('teacherUid');
        $talk->method('createGroupRoom')->willReturn('tokNew');

        $talk->expects($this->once())->method('enableLobby')->with('tokNew');
        // 133 = CUSTOM(1)|CALL_JOIN(4)|CHAT(128): join muted, no audio/video.
        $talk->expects($this->once())->method('setDefaultPermissions')->with('tokNew', 133);
        // Teacher is still promoted — lecture mode does not touch that path.
        $talk->expects($this->once())->method('promoteToModerator')->with('tokNew', 'teacherUid');

        $payload = $this->payload();
        $payload['lecture_mode'] = true;
        $result = $orch->ensureRoom($payload);

        $this->assertSame('applied', $result['status']);
    }

    public function testLargeExpectedEnrolmentAloneTriggersLectureMode(): void {
        // lecture_mode forced flag is false, but participants_expected (35)
        // exceeds the default threshold (30) — still triggers lecture mode.
        [$orch, $talk, $prov] = $this->make(null);
        $prov->method('ensureUser')->willReturn('teacherUid');
        $talk->method('createGroupRoom')->willReturn('tokNew');

        $talk->expects($this->once())->method('enableLobby')->with('tokNew');
        $talk->expects($this->once())->method('setDefaultPermissions')->with('tokNew', 133);

        $payload = $this->payload();
        $payload['lecture_mode'] = false;
        $payload['participants_expected'] = 35;
        $orch->ensureRoom($payload);
    }

    public function testSmallClassSkipsLobbyAndPermissions(): void {
        // lecture_mode false AND expected (2, from payload()) < threshold (30)
        // -> neither applied.
        [$orch, $talk, $prov] = $this->make(null);
        $prov->method('ensureUser')->willReturn('teacherUid');
        $talk->method('createGroupRoom')->willReturn('tokNew');

        $talk->expects($this->never())->method('enableLobby');
        $talk->expects($this->never())->method('setDefaultPermissions');
        $talk->expects($this->once())->method('promoteToModerator')->with('tokNew', 'teacherUid');

        $result = $orch->ensureRoom($this->payload());
        $this->assertSame('applied', $result['status']);
    }
}
