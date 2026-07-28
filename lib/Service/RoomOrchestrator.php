<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Service;

use OCA\MoodleTalkBridge\Db\RoomMap;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Exception\ValidationException;
use OCP\IConfig;

/**
 * Orchestrates Talk room provisioning for a room.ensure webhook.
 *
 * The idempotent core of room.ensure: one persistent room per activity,
 * keyed on (activity_host, activity_id). activity_host is this app's own
 * configured `moodle_host` — WebhookController does not (yet) forward the
 * verified X-Moodle-Instance sender into the payload, so single-tenant
 * config is the pragmatic source for now (see task-10-report.md, Concerns).
 *
 * Empty-teacher handling: Task 8 (mod_nextcloudtalk observer) may emit an
 * empty teacher.email/displayname when an activity has no editing teacher
 * enrolled. That is a legitimate "no teacher yet" signal, not malformed
 * input: the room is still created (so it exists when a teacher is later
 * enrolled/join is attempted) but provisioning and moderator-promotion are
 * skipped. A *missing* teacher.email key (payload shape itself broken) is
 * still a hard validation error (422).
 */
class RoomOrchestrator {
    private const APP_ID = 'moodle_talk_bridge';

    /**
     * Default (non-moderator) permission bitmask for lecture mode (D6).
     *
     * spreed encodes participant permissions as a bitfield
     * (\OCA\Talk\Model\Attendee::PERMISSIONS_*, pinned live/from source —
     * see TalkService::setDefaultPermissions()):
     *   CUSTOM(1) | CALL_JOIN(4) | CHAT(128) = 133.
     * CUSTOM(1) marks the set as explicit (0 = "use call defaults" = every
     * permission); granting only CALL_JOIN + CHAT lets attendees join muted
     * and type, while PUBLISH_AUDIO(16) / PUBLISH_VIDEO(32) /
     * CALL_START(2) stay off — muted and unable to broadcast until a
     * moderator grants it.
     */
    private const LECTURE_DEFAULT_PERMISSIONS = 133;

    /** Falls back to this when the payload carries no explicit threshold. */
    private const DEFAULT_LECTURE_THRESHOLD = 30;

    /**
     * Posted by the bot into the room at creation. Nextcloud Talk has no
     * supported way to auto-start a call from a link (joining is a deliberate
     * click + a browser media gesture), so users land on the conversation with
     * a "Start call"/"Join call" button — this notice tells them what to do.
     */
    private const WELCOME_MESSAGE =
        "👋 Welcome to your class video room.\n\n"
        . "The video call does not start automatically. To begin the live session, "
        . "click the \"Start call\" button (teachers/moderators) or \"Join call\" button "
        . "(students) at the top of this screen, then allow camera & microphone access.";

    public function __construct(
        private TalkService $talk,
        private ProvisioningService $provisioning,
        private RoomMapper $roomMapper,
        private IConfig $config,
    ) {
    }

    /**
     * @param array $payload room.ensure payload.
     * @return array{status:string,room_token:string}
     */
    public function ensureRoom(array $payload): array {
        foreach (['activity_id', 'activity_name'] as $key) {
            if (empty($payload[$key])) {
                throw new ValidationException("missing $key");
            }
        }
        if (!array_key_exists('email', $payload['teacher'] ?? [])) {
            throw new ValidationException('missing teacher.email');
        }

        $host = $this->config->getAppValue(self::APP_ID, 'moodle_host', '');
        $activityId = (int) $payload['activity_id'];

        $existing = $this->roomMapper->findByHostAndActivityId($host, $activityId);
        if ($existing !== null) {
            return ['status' => 'skipped', 'room_token' => (string) $existing->getRoomToken()];
        }

        $teacherEmail = (string) $payload['teacher']['email'];
        $teacherUid = '';
        if ($teacherEmail !== '') {
            $teacherUid = $this->provisioning->ensureUser(
                $teacherEmail,
                (string) ($payload['teacher']['displayname'] ?? $teacherEmail));
        }

        $roomName = $this->buildRoomName($payload);
        $token = $this->talk->createGroupRoom($roomName);

        // Lecture mode (D6): forced by the teacher/Moodle-side threshold
        // check (payload.lecture_mode), or auto-triggered here if the
        // expected class size exceeds the threshold. Applied exactly once,
        // on this first-ensure path — a re-ensure never reaches this branch
        // (see the existing-mapping early return above).
        if ($this->isLectureMode($payload)) {
            $this->talk->enableLobby($token);
            $this->talk->setDefaultPermissions($token, self::LECTURE_DEFAULT_PERMISSIONS);
        }

        if ($teacherUid !== '') {
            // Promotion requires the target to already be a room participant
            // (pinned live: promoting a non-participant fails resolving an
            // attendeeId) — add before promoting.
            $this->talk->addParticipant($token, $teacherUid);
            $this->talk->promoteToModerator($token, $teacherUid);
        }

        // Guide users past the "conversation vs. start-call" confusion.
        $this->talk->postMessage($token, self::WELCOME_MESSAGE);

        $map = new RoomMap();
        $map->setActivityId($activityId);
        $map->setActivityHost($host);
        $map->setRoomToken($token);
        $map->setTeacherUid($teacherUid !== '' ? $teacherUid : null);
        $map->setCreated(time());
        $this->roomMapper->insert($map);

        return ['status' => 'applied', 'room_token' => $token];
    }

    /**
     * room.archive (Task 17): tear down the room for a deleted activity.
     * Idempotent — a missing mapping (room never provisioned, or already
     * archived by an earlier/duplicate delivery) returns 'skipped' rather
     * than erroring.
     *
     * MVP does a hard teardown (delete the room + drop the map row); true
     * archival-with-retention is a Phase-3 refinement (D8).
     *
     * @param array<string, mixed> $payload room.archive payload: {activity_id: int}.
     * @return array{status:string}
     */
    public function archiveRoom(array $payload): array {
        $activityId = (int) ($payload['activity_id'] ?? 0);

        try {
            $row = $this->roomMapper->findByActivityId($activityId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return ['status' => 'skipped'];
        }

        $this->talk->deleteRoom((string) $row->getRoomToken());
        $this->roomMapper->delete($row);

        return ['status' => 'archived'];
    }

    /**
     * Lecture mode (D6) fires when the payload's precomputed
     * `lecture_mode` flag is true (Task 8's Moodle-side observer already
     * compared expected enrolment to the activity's configured threshold),
     * OR — as a defence-in-depth fallback — this method's own comparison of
     * `participants_expected` against `activity.lecture_mode_threshold`
     * (default 30) also crosses it. Missing/malformed fields default to
     * "no lecture mode" rather than throwing, since the fields are optional
     * additions to the payload and must not break older/partial callers.
     *
     * @param array<string, mixed> $payload
     */
    private function isLectureMode(array $payload): bool {
        $forced = (bool) ($payload['lecture_mode'] ?? false);
        $expected = (int) ($payload['participants_expected'] ?? 0);
        $threshold = (int) ($payload['activity']['lecture_mode_threshold'] ?? self::DEFAULT_LECTURE_THRESHOLD);

        return $forced || $expected > $threshold;
    }

    /**
     * "{course_shortname} - {activity_name}", but without a stray leading
     * "- " when course_shortname is empty (e.g. "W1", not "- W1").
     */
    private function buildRoomName(array $payload): string {
        $parts = array_filter(
            [(string) ($payload['course_shortname'] ?? ''), (string) $payload['activity_name']],
            static fn (string $part): bool => $part !== '');
        return implode(' - ', $parts);
    }
}
