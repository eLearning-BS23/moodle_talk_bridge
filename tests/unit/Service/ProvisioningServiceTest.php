<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Service;

use OCA\MoodleTalkBridge\Db\UserMap;
use OCA\MoodleTalkBridge\Db\UserMapper;
use OCA\MoodleTalkBridge\Service\ProvisioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Provisioning now runs entirely in-process against OCP\IUserManager (Task
 * 10 fix pass) rather than over OCS provisioning_api HTTP — see
 * ProvisioningService's class docblock and task-10-report.md for why the
 * HTTP path is unusable for an app-password bot.
 */
class ProvisioningServiceTest extends TestCase {
    private IUserManager&MockObject $userManager;
    private UserMapper&MockObject $userMapper;
    private ISecureRandom&MockObject $secureRandom;
    private ProvisioningService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userMapper = $this->createMock(UserMapper::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->service = new ProvisioningService($this->userManager, $this->userMapper, $this->secureRandom);
    }

    public function testReturnsCachedUidWithoutTouchingUserManager(): void {
        $cached = new UserMap();
        $cached->setNcUid('jose');
        $this->userMapper->method('findByEmail')->with('jose@uni.edu')->willReturn($cached);

        $this->userManager->expects($this->never())->method('getByEmail');
        $this->userManager->expects($this->never())->method('createUser');
        $this->userMapper->expects($this->never())->method('insert');

        $this->assertSame('jose', $this->service->ensureUser('jose@uni.edu', 'José García'));
    }

    public function testReturnsExistingNcUserUidOnCacheMissWithoutCreating(): void {
        $this->userMapper->method('findByEmail')->with('jose@uni.edu')
            ->willThrowException(new DoesNotExistException('no cache row'));

        $existingUser = $this->createMock(IUser::class);
        $existingUser->method('getUID')->willReturn('jose');
        $this->userManager->expects($this->once())->method('getByEmail')
            ->with('jose@uni.edu')->willReturn([$existingUser]);
        $this->userManager->expects($this->never())->method('createUser');

        $this->userMapper->expects($this->once())->method('insert')
            ->with($this->callback(function (UserMap $map): bool {
                return $map->getEmail() === 'jose@uni.edu'
                    && $map->getNcUid() === 'jose'
                    && $map->getProvisioned() === false;
            }));

        $this->assertSame('jose', $this->service->ensureUser('jose@uni.edu', 'José García'));
    }

    public function testCreatesUserOnceOnCacheMissAndNoExistingNcUser(): void {
        $this->userMapper->method('findByEmail')->with('newbie@uni.edu')
            ->willThrowException(new DoesNotExistException('no cache row'));
        $this->userManager->method('getByEmail')->with('newbie@uni.edu')->willReturn([]);
        $this->userManager->method('userExists')->willReturn(false);
        $this->secureRandom->method('generate')->willReturn('a-random-password');

        $newUser = $this->createMock(IUser::class);
        $newUser->expects($this->once())->method('setDisplayName')->with('New Bie');
        $newUser->expects($this->once())->method('setSystemEMailAddress')->with('newbie@uni.edu');

        $this->userManager->expects($this->once())->method('createUser')
            ->with('newbie', 'a-random-password')->willReturn($newUser);

        $this->userMapper->expects($this->once())->method('insert')
            ->with($this->callback(function (UserMap $map): bool {
                return $map->getEmail() === 'newbie@uni.edu'
                    && $map->getNcUid() === 'newbie'
                    && $map->getProvisioned() === true;
            }));

        $this->assertSame('newbie', $this->service->ensureUser('newbie@uni.edu', 'New Bie'));
    }

    public function testAllocatesUniqueUidWhenDerivedUidCollides(): void {
        // Regression: a Moodle admin (admin@example.com) whose derived uid
        // "admin" collides with Nextcloud's built-in admin must NOT hijack it
        // and must NOT 500 — a fresh unique uid is allocated instead.
        $this->userMapper->method('findByEmail')->willThrowException(new DoesNotExistException('miss'));
        $this->userManager->method('getByEmail')->with('admin@example.com')->willReturn([]);
        $this->secureRandom->method('generate')->willReturn('pw');
        $this->userManager->method('userExists')->willReturnMap([
            ['admin', true],
            ['admin1', false],
        ]);

        $newUser = $this->createMock(IUser::class);
        $newUser->method('setDisplayName');
        $newUser->method('setSystemEMailAddress');
        $this->userManager->expects($this->once())->method('createUser')
            ->with('admin1', 'pw')->willReturn($newUser);

        $this->userMapper->expects($this->once())->method('insert')
            ->with($this->callback(function (UserMap $map): bool {
                return $map->getNcUid() === 'admin1' && $map->getProvisioned() === true;
            }));

        $this->assertSame('admin1', $this->service->ensureUser('admin@example.com', 'Admin User'));
    }

    public function testCreateFailureThrows(): void {
        $this->userManager->method('userExists')->willReturn(false);
        $this->userMapper->method('findByEmail')->willThrowException(new DoesNotExistException('miss'));
        $this->userManager->method('getByEmail')->willReturn([]);
        $this->secureRandom->method('generate')->willReturn('pw');
        $this->userManager->method('createUser')->willReturn(false);

        $this->userMapper->expects($this->never())->method('insert');
        $this->expectException(\RuntimeException::class);
        $this->service->ensureUser('fails@uni.edu', 'Fails');
    }

    public function testEmptyEmailSkipsGracefullyWithoutTouchingCollaborators(): void {
        $this->userMapper->expects($this->never())->method('findByEmail');
        $this->userManager->expects($this->never())->method('getByEmail');
        $this->userManager->expects($this->never())->method('createUser');
        $this->userMapper->expects($this->never())->method('insert');

        $this->assertSame('', $this->service->ensureUser('', 'Nobody'));
    }
}
