<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Service;

use OCA\MoodleTalkBridge\Service\TalkService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Test\TestCase;

class TalkServiceTest extends TestCase {
    public function testCreateGroupRoomParsesPinnedToken(): void {
        // spreed v4 POST /room response — pinned live against NC 35 / spreed
        // 24.0.2 (moodle-talk-bot, dev stack), 2026-07-24:
        //   POST /ocs/v2.php/apps/spreed/api/v4/room roomType=2 roomName=...
        //   -> 201, ocs.data.token = "pxomocf4" (statuscode 201, not 200 as
        //   naively assumed; token lives at ocs.data.token either way).
        $json = json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 201, 'message' => 'OK'],
            'data' => ['token' => 'a1b2c3d4', 'type' => 2, 'name' => 'CS101 - W1'],
        ]]);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($json);

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('/ocs/v2.php/apps/spreed/api/v4/room'),
                $this->callback(function (array $opts): bool {
                    return $opts['body']['roomType'] === 2
                        && $opts['body']['roomName'] === 'CS101 - W1'
                        && $opts['headers']['OCS-APIRequest'] === 'true'
                        && $opts['headers']['Accept'] === 'application/json'
                        && $opts['auth'] === ['bot42', 'app-pass'];
                }))
            ->willReturn($response);

        $svc = new TalkService($this->clientService($client), $this->config());
        $this->assertSame('a1b2c3d4', $svc->createGroupRoom('CS101 - W1'));
    }

    public function testPostMessagePostsToChatEndpoint(): void {
        // spreed chat API pinned live: POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}
        // with `message` -> 201 (posted as the bot's display name).
        $response = $this->createMock(IResponse::class);
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('/ocs/v2.php/apps/spreed/api/v1/chat/tok123'),
                $this->callback(function (array $opts): bool {
                    return $opts['body']['message'] === 'hello class'
                        && $opts['headers']['OCS-APIRequest'] === 'true'
                        && $opts['auth'] === ['bot42', 'app-pass'];
                }))
            ->willReturn($response);

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->postMessage('tok123', 'hello class');
    }

    public function testAddParticipantPostsNewParticipantAndSource(): void {
        // spreed v4 POST /room/{token}/participants — pinned live: 200,
        // ocs.data == [] (no attendeeId in this response; resolved separately
        // for promotion, see below).
        $json = json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => [],
        ]]);
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($json);

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('/room/tok123/participants'),
                $this->callback(function (array $opts): bool {
                    return $opts['body']['newParticipant'] === 'jose'
                        && $opts['body']['source'] === 'users';
                }))
            ->willReturn($response);

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->addParticipant('tok123', 'jose');
    }

    public function testPromoteToModeratorResolvesAttendeeIdThenPromotes(): void {
        // Pinned live: spreed's POST /room/{token}/moderators requires the
        // participant's numeric attendeeId, NOT the uid — posting the uid
        // directly returns 404. attendeeId is resolved via
        // GET /room/{token}/participants (actorId == uid -> attendeeId).
        $participantsJson = json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => [
                ['actorId' => 'moodle-talk-bot', 'actorType' => 'users', 'attendeeId' => 4],
                ['actorId' => 'jose', 'actorType' => 'users', 'attendeeId' => 5],
            ],
        ]]);
        $participantsResponse = $this->createMock(IResponse::class);
        $participantsResponse->method('getBody')->willReturn($participantsJson);

        $moderatorsResponse = $this->createMock(IResponse::class);
        $moderatorsResponse->method('getBody')->willReturn(json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => null,
        ]]));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('get')
            ->with($this->stringContains('/room/tok123/participants'))
            ->willReturn($participantsResponse);
        $client->expects($this->once())->method('post')
            ->with(
                $this->stringContains('/room/tok123/moderators'),
                $this->callback(fn (array $opts): bool => $opts['body']['attendeeId'] === 5))
            ->willReturn($moderatorsResponse);

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->promoteToModerator('tok123', 'jose');
    }

    public function testEnableLobbyPutsWebinarLobbyStateOne(): void {
        // spreed v4 PUT /room/{token}/webinar/lobby {state:1} — pinned live
        // against NC 35.0.0 / spreed 24.0.2 (moodle-talk-bot, dev stack),
        // 2026-07-24: 200, ocs.data.lobbyState flips 0 -> 1 in the same
        // response, confirmed persisted on a follow-up GET. See
        // task-16-report.md for the full transcript, including why this
        // uses 'form_params' and not 'body' (IClient::put() does NOT do the
        // array-'body'->form_params translation that post() does — a real
        // 500 caught live on the first smoke attempt).
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => ['token' => 'tok123', 'lobbyState' => 1],
        ]]));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('put')
            ->with(
                $this->stringContains('/room/tok123/webinar/lobby'),
                $this->callback(fn (array $opts): bool => $opts['form_params']['state'] === 1
                    && !isset($opts['body'])))
            ->willReturn($response);

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->enableLobby('tok123');
    }

    public function testSetDefaultPermissionsPutsPermissionsDefault(): void {
        // spreed v4 PUT /room/{token}/permissions/default {permissions} —
        // pinned live (same stack/date/pitfall as enableLobby's pin): 200,
        // ocs.data.defaultPermissions flips 0 -> the posted value in the
        // same response, confirmed persisted on a follow-up GET.
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => ['token' => 'tok123', 'defaultPermissions' => 133],
        ]]));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('put')
            ->with(
                $this->stringContains('/room/tok123/permissions/default'),
                $this->callback(fn (array $opts): bool => $opts['form_params']['permissions'] === 133
                    && !isset($opts['body'])))
            ->willReturn($response);

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->setDefaultPermissions('tok123', 133);
    }

    public function testRemoveParticipantResolvesAttendeeIdThenDeletes(): void {
        // Pinned live (see TalkService class docblock): DELETE
        // /room/{token}/attendees addresses by numeric attendeeId, resolved
        // via GET /room/{token}/participants the same way as promotion.
        // attendeeId travels as a query param, NOT a 'body' array — OCP's
        // IClient::delete() doesn't do the body->form_params translation
        // that post() does.
        $participantsJson = json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => [
                ['actorId' => 'moodle-talk-bot', 'actorType' => 'users', 'attendeeId' => 15],
                ['actorId' => 'alice', 'actorType' => 'users', 'attendeeId' => 16],
            ],
        ]]);
        $participantsResponse = $this->createMock(IResponse::class);
        $participantsResponse->method('getBody')->willReturn($participantsJson);

        $deleteResponse = $this->createMock(IResponse::class);
        $deleteResponse->method('getBody')->willReturn(json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => null,
        ]]));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('get')
            ->with($this->stringContains('/room/tok123/participants'))
            ->willReturn($participantsResponse);
        $client->expects($this->once())->method('delete')
            ->with(
                $this->stringContains('/room/tok123/attendees'),
                $this->callback(fn (array $opts): bool => $opts['query']['attendeeId'] === 16))
            ->willReturn($deleteResponse);

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->removeParticipant('tok123', 'alice');
    }

    public function testRemoveParticipantIsNoOpWhenNotAMember(): void {
        // Idempotency: un-enrolment fired twice, or the user was never added
        // in the first place, must not error.
        $participantsJson = json_encode(['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => [
                ['actorId' => 'moodle-talk-bot', 'actorType' => 'users', 'attendeeId' => 15],
            ],
        ]]);
        $participantsResponse = $this->createMock(IResponse::class);
        $participantsResponse->method('getBody')->willReturn($participantsJson);

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('get')->willReturn($participantsResponse);
        $client->expects($this->never())->method('delete');

        $svc = new TalkService($this->clientService($client), $this->config());
        $svc->removeParticipant('tok123', 'ghost');
    }

    private function clientService(IClient $client): IClientService {
        $cs = $this->createMock(IClientService::class);
        $cs->method('newClient')->willReturn($client);
        return $cs;
    }

    private function config(): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnMap([
            ['moodle_talk_bridge', 'nextcloud_url', '', 'https://nc.example'],
            ['moodle_talk_bridge', 'bot_user', '', 'bot42'],
            ['moodle_talk_bridge', 'bot_app_password', '', 'app-pass'],
        ]);
        return $config;
    }
}
