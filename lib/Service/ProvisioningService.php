<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Service;

use OCA\MoodleTalkBridge\Db\UserMap;
use OCA\MoodleTalkBridge\Db\UserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Resolve a Moodle user's email to an NC uid; create on miss (D1).
 *
 * Runs entirely in-process via OCP\IUserManager rather than over the OCS
 * provisioning_api HTTP endpoints used in the first pass of Task 10. That
 * HTTP path is unusable for a bot authenticating with an app-password (a
 * "token" login, per NC's own Session code):
 *  - POST /cloud/users (create) is annotated #[PasswordConfirmationRequired]
 *    upstream, and NC only ever sets the session's last-password-confirm
 *    timestamp for a real-password login — never for a token login. So an
 *    app-password-authenticated create ALWAYS 403s ("Password confirmation
 *    is required"), regardless of the bot's privilege level (confirmed live
 *    against NC 35.0.0, see task-10-report.md).
 *  - GET /cloud/users?search= (lookup) requires the bot to be at least a
 *    subadmin/admin-delegate.
 * Since this app runs *inside* Nextcloud (not as an external OCS client),
 * calling IUserManager directly sidesteps both platform constraints: no
 * password-confirmation check applies to in-process user creation, and no
 * subadmin grant is needed to search users. This is a product decision
 * (fix pass on Task 10), not just an implementation convenience.
 */
class ProvisioningService {
    public function __construct(
        private IUserManager $userManager,
        private UserMapper $userMapper,
        private ISecureRandom $secureRandom,
    ) {
    }

    /**
     * @throws \RuntimeException if a brand-new NC account could not be created.
     */
    public function ensureUser(string $email, string $displayname): string {
        // Callers (RoomOrchestrator) already guard the empty-teacher case,
        // but stay defensive here too rather than let an empty email fall
        // through to userManager->getByEmail('')/createUser('', ...).
        if ($email === '') {
            return '';
        }

        try {
            return (string) $this->userMapper->findByEmail($email)->getNcUid();
        } catch (DoesNotExistException) {
            // Not cached yet — resolve via the user manager below.
        }

        $existing = $this->userManager->getByEmail($email);
        if ($existing !== []) {
            $uid = $existing[0]->getUID();
            $this->persistMap($email, $uid, false);
            return $uid;
        }

        $uid = $this->createNcUser($email, $displayname);
        $this->persistMap($email, $uid, true);
        return $uid;
    }

    private function createNcUser(string $email, string $displayname): string {
        // getByEmail() already returned no match for this email, so any account
        // whose uid we'd derive here belongs to a *different* user (e.g. the
        // built-in "admin" when a Moodle admin's email local-part is "admin").
        // Never hijack it — allocate a fresh, unique uid. NC also refuses a uid
        // whose data dir survived a prior deletion ("files already exist for
        // this user"), thrown as InvalidArgumentException; advance past those too.
        $base = $this->deriveUid($email);
        $password = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

        $uid = $base;
        $user = false;
        for ($suffix = 1; $suffix <= 1000; $suffix++) {
            if (!$this->userManager->userExists($uid)) {
                try {
                    $user = $this->userManager->createUser($uid, $password);
                    if ($user !== false) {
                        break;
                    }
                } catch (\InvalidArgumentException $e) {
                    // uid reserved / leftover data — fall through to the next candidate.
                }
            }
            $uid = $base . $suffix;
        }
        if ($user === false) {
            throw new \RuntimeException("failed to create a unique NC user for {$email}");
        }

        $user->setDisplayName($displayname !== '' ? $displayname : $uid);
        // getByEmail() (used above on cache-miss) matches on the *system*
        // email address, not setEMailAddress()'s target — see IUserManager
        // docblock ("returns all users having the provided email set as
        // system email address").
        $user->setSystemEMailAddress($email);

        return $uid;
    }

    private function deriveUid(string $email): string {
        $local = strstr($email, '@', true) ?: $email;
        $uid = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $local));
        return $uid !== '' ? $uid : 'mtb_' . substr(sha1($email), 0, 12);
    }

    private function persistMap(string $email, string $uid, bool $provisioned): void {
        $map = new UserMap();
        $map->setEmail($email);
        $map->setNcUid($uid);
        $map->setProvisioned($provisioned);
        $map->setCreated(time());
        $this->userMapper->insert($map);
    }
}
