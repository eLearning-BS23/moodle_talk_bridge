<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Local cache of email -> Nextcloud uid resolution (talkbridge_user_map,
 * unique index tb_usermap_email_ux). `provisioned` records whether this
 * app's ProvisioningService created the NC account (true) or merely found
 * one that already existed (false).
 *
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string|null getNcUid()
 * @method void setNcUid(?string $ncUid)
 * @method bool getProvisioned()
 * @method void setProvisioned(bool $provisioned)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class UserMap extends Entity {
    protected $email;
    protected $ncUid;
    protected $provisioned;
    protected $created;

    public function __construct() {
        $this->addType('email', Types::STRING);
        $this->addType('ncUid', Types::STRING);
        $this->addType('provisioned', Types::BOOLEAN);
        $this->addType('created', Types::BIGINT);
    }
}
