<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * The only place that knows the spreed v4 HTTP shape. All calls go out as
 * the bot app-password over OCS.
 *
 * Shapes pinned live against NC 35.0.0 / spreed 24.0.2 (dev stack, bot
 * `moodle-talk-bot`, 2026-07-24) — see task-10-report.md for full
 * transcripts:
 *  - POST /room -> 201, token at ocs.data.token.
 *  - POST /room/{token}/participants -> 200, ocs.data == [] (no attendeeId
 *    returned here).
 *  - POST /room/{token}/moderators requires the participant's numeric
 *    attendeeId, NOT the uid — posting the uid string 404s. attendeeId is
 *    resolved via GET /room/{token}/participants, matching actorId == uid.
 *  - DELETE /room/{token}/attendees also addresses by numeric attendeeId
 *    (pinned live on the same stack, 2026-07-24, bot moodle-talk-bot): added
 *    a throwaway attendee, GET /room/{token}/participants showed
 *    {actorType:"users", actorId:"alice", attendeeId:16}, then
 *    DELETE /room/{token}/attendees?attendeeId=16 -> 200, ocs.data == null,
 *    and the attendee no longer appeared in a follow-up GET. Confirmed via
 *    both a query-string attendeeId and a form-encoded body — the query
 *    string is what this class uses because OCP's IClient::delete() does
 *    NOT convert an array 'body' option to Guzzle form_params the way
 *    post() does (only post()/postAsync() do that translation in
 *    lib/private/Http/Client/Client.php), so an array 'body' on delete()
 *    would hit Guzzle's body option, which requires a string/stream, not an
 *    array. See task-14-report.md for the full transcript.
 *  - PUT /room/{token}/webinar/lobby {state} and PUT
 *    /room/{token}/permissions/default {permissions} (lecture mode, D6) hit
 *    the SAME array-'body' pitfall as delete() above: IClient::put() also
 *    does not translate an array 'body' to Guzzle's form_params (only
 *    post()/postAsync() do — confirmed from lib/private/Http/Client/Client.php
 *    in this container: put() passes options straight through with no
 *    'body' handling at all). A first live smoke attempt using 'body' 500'd
 *    with Guzzle's own message ("Passing in the 'body' request option as an
 *    array to send a request is not supported... use 'form_params'").
 *    Fixed by sending 'form_params' directly for both PUTs, confirmed live
 *    (state 0->1, defaultPermissions 0->133) — see task-16-report.md.
 */
class TalkService {
    private const APP_ID = 'moodle_talk_bridge';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
    ) {
    }

    /**
     * Create a group room (roomType=2) and return its token.
     */
    public function createGroupRoom(string $roomName): string {
        $response = $this->clientService->newClient()->post(
            $this->ocsUrl('/apps/spreed/api/v4/room'),
            $this->options(['roomType' => 2, 'roomName' => $roomName]));

        $json = $this->decode($response->getBody());
        return (string) $json['ocs']['data']['token'];
    }

    /**
     * Post a chat message into the room as the bot.
     * spreed chat API: POST .../apps/spreed/api/v1/chat/{token} with `message`
     * (pinned live → 201, posted as the bot's display name).
     */
    public function postMessage(string $token, string $message): void {
        $this->clientService->newClient()->post(
            $this->ocsUrl('/apps/spreed/api/v1/chat/' . rawurlencode($token)),
            $this->options(['message' => $message]));
    }

    public function addParticipant(string $token, string $uid): void {
        $this->clientService->newClient()->post(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token) . '/participants'),
            $this->options(['newParticipant' => $uid, 'source' => 'users']));
    }

    /**
     * Promote a participant (by uid) to moderator. spreed v4 addresses
     * moderators by attendeeId, so the uid is resolved to its attendeeId
     * via the participants list first (pinned live, see class docblock).
     */
    public function promoteToModerator(string $token, string $uid): void {
        $attendeeId = $this->resolveAttendeeId($token, $uid);
        $this->clientService->newClient()->post(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token) . '/moderators'),
            $this->options(['attendeeId' => $attendeeId]));
    }

    /**
     * Turn on the webinar lobby (lecture mode, D6): non-moderators wait in
     * the lobby until a moderator admits them, or opens the call.
     *
     * Pinned live (NC 35.0.0 / spreed 24.0.2, dev stack, bot
     * `moodle-talk-bot`, 2026-07-24): `PUT
     * /apps/spreed/api/v4/room/{token}/webinar/lobby` `{state:1}` -> 200,
     * `ocs.data.lobbyState` flips 0 -> 1 in the same response (system
     * message "You restricted the conversation to moderators"), and a
     * follow-up `GET /room/{token}` confirms it persists. state=1 is
     * spreed's `WEBINAR_LOBBY_NON_MODERATORS` (see task-16-report.md for the
     * full transcript, including the array-'body'-on-PUT pitfall noted in
     * the class docblock).
     */
    public function enableLobby(string $token): void {
        $this->clientService->newClient()->put(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token) . '/webinar/lobby'),
            $this->putOptions(['state' => 1]));
    }

    /**
     * Set the default (non-moderator) permission bitmask for the room
     * (lecture mode, D6).
     *
     * Pinned live (same stack/date as enableLobby()): `PUT
     * /apps/spreed/api/v4/room/{token}/permissions/default`
     * `{permissions:<int>}` -> 200, `ocs.data.defaultPermissions` flips from
     * 0 to the posted value in the same response, confirmed on a follow-up
     * GET. The bitmask uses spreed's participant permission bits
     * (`\OCA\Talk\Model\Attendee::PERMISSIONS_*`, read from the live spreed
     * source in the container): DEFAULT=0, CUSTOM=1, CALL_START=2,
     * CALL_JOIN=4, LOBBY_IGNORE=8, PUBLISH_AUDIO=16, PUBLISH_VIDEO=32,
     * PUBLISH_SCREEN=64, CHAT=128, REACT=256. `RoomOrchestrator` passes 133
     * = CUSTOM(1)|CALL_JOIN(4)|CHAT(128): CUSTOM marks the set explicit (0
     * would mean "inherit everything"), CALL_JOIN+CHAT let attendees join
     * muted and type, and PUBLISH_AUDIO/PUBLISH_VIDEO/CALL_START stay off —
     * a moderator must grant them before an attendee can broadcast.
     */
    public function setDefaultPermissions(string $token, int $permissions): void {
        $this->clientService->newClient()->put(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token) . '/permissions/default'),
            $this->putOptions(['permissions' => $permissions]));
    }

    /**
     * Remove a participant from a room (membership.revoke, Flow 5, D7).
     *
     * spreed v4 addresses attendees by numeric attendeeId (pinned live, see
     * class docblock), so the uid is resolved to its attendeeId via the
     * participants list first, same as promoteToModerator(). Idempotent: if
     * the uid isn't currently a participant, this is a silent no-op — the
     * un-enrolment already achieved the desired end state.
     */
    public function removeParticipant(string $token, string $uid): void {
        $attendeeId = $this->findAttendeeId($token, $uid);
        if ($attendeeId === null) {
            return;
        }
        $this->clientService->newClient()->delete(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token) . '/attendees'),
            $this->deleteOptions(['attendeeId' => $attendeeId]));
    }

    /**
     * Delete a Talk conversation outright (room.archive, Task 17 — hard
     * teardown). True archival-with-retention (rather than delete) is a
     * Phase-3 refinement (D8); MVP just tears the room down.
     *
     * Pinned live (NC 35.0.0 / spreed 24.0.2, dev stack, bot
     * `moodle-talk-bot`, 2026-07-24): created a throwaway room (token
     * `hrt9tpf4`), confirmed it existed via GET (200), then
     * `DELETE /apps/spreed/api/v4/room/{token}` -> 200, `ocs.data == null`,
     * and a follow-up GET on the same token 404'd. No body or query
     * parameters are needed for this endpoint (unlike removeParticipant()'s
     * DELETE, which addresses an attendee by query-string attendeeId) — see
     * task-17-report.md for the full transcript.
     */
    public function deleteRoom(string $token): void {
        $this->clientService->newClient()->delete(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token)),
            $this->options());
    }

    /**
     * Probe Talk (spreed v4) as the bot to feed the /health endpoint.
     *
     * GET .../room with the bot app-password:
     *   200          -> Talk reachable AND bot authenticated
     *   401/403      -> reachable, but the app-password did not authenticate
     *   anything else (connect timeout/DNS/refused) -> not reachable
     *
     * @return array{talk_reachable: bool, bot_authenticated: bool}
     */
    public function healthCheck(): array {
        try {
            $response = $this->clientService->newClient()->get(
                $this->ocsUrl('/apps/spreed/api/v4/room'),
                $this->options());

            return [
                'talk_reachable' => true,
                'bot_authenticated' => $response->getStatusCode() === 200,
            ];
        } catch (\Throwable $e) {
            // A 401/403 means spreed replied but rejected the bot creds
            // (reachable, not authenticated); any other error is connectivity.
            $rejected = str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), '403');

            return [
                'talk_reachable' => $rejected,
                'bot_authenticated' => false,
            ];
        }
    }

    private function resolveAttendeeId(string $token, string $uid): int {
        $attendeeId = $this->findAttendeeId($token, $uid);
        if ($attendeeId === null) {
            throw new \RuntimeException("participant not found in room {$token}: {$uid}");
        }
        return $attendeeId;
    }

    private function findAttendeeId(string $token, string $uid): ?int {
        $response = $this->clientService->newClient()->get(
            $this->ocsUrl('/apps/spreed/api/v4/room/' . rawurlencode($token) . '/participants'),
            $this->options());
        $json = $this->decode($response->getBody());
        $participants = $json['ocs']['data'] ?? [];
        foreach ($participants as $participant) {
            if (($participant['actorId'] ?? null) === $uid) {
                return (int) $participant['attendeeId'];
            }
        }
        return null;
    }

    /** @return array<mixed> */
    private function decode(string $body): array {
        return json_decode($body, true) ?? [];
    }

    private function ocsUrl(string $path): string {
        $base = rtrim($this->config->getAppValue(self::APP_ID, 'nextcloud_url', ''), '/');
        return $base . '/ocs/v2.php' . $path;
    }

    /** @param array<string,mixed> $body */
    private function options(array $body = []): array {
        $opts = [
            'auth' => [
                $this->config->getAppValue(self::APP_ID, 'bot_user', ''),
                $this->config->getAppValue(self::APP_ID, 'bot_app_password', ''),
            ],
            'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
        ];
        if ($body !== []) {
            $opts['body'] = $body;
        }
        return $opts;
    }

    /**
     * PUT-specific options: 'form_params', not 'body' (see
     * enableLobby()/setDefaultPermissions()/class docblock for why 'body'
     * doesn't work here — IClient::put() doesn't do the array 'body' ->
     * form_params translation that post() does).
     *
     * @param array<string,mixed> $formParams
     * @return array<string,mixed>
     */
    private function putOptions(array $formParams): array {
        return [
            'auth' => [
                $this->config->getAppValue(self::APP_ID, 'bot_user', ''),
                $this->config->getAppValue(self::APP_ID, 'bot_app_password', ''),
            ],
            'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
            'form_params' => $formParams,
        ];
    }

    /**
     * DELETE-specific options: the query string, not 'body' (see
     * removeParticipant()/class docblock for why 'body' doesn't work here).
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function deleteOptions(array $query = []): array {
        $opts = [
            'auth' => [
                $this->config->getAppValue(self::APP_ID, 'bot_user', ''),
                $this->config->getAppValue(self::APP_ID, 'bot_app_password', ''),
            ],
            'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
        ];
        if ($query !== []) {
            $opts['query'] = $query;
        }
        return $opts;
    }
}
