<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row per persistent Talk room, keyed on (activity_host, activity_id)
 * (unique index tb_roommap_activity_ux — see
 * Migration\Version001Date20260101000000).
 *
 * @method int getActivityId()
 * @method void setActivityId(int $activityId)
 * @method string getActivityHost()
 * @method void setActivityHost(string $activityHost)
 * @method string|null getRoomToken()
 * @method void setRoomToken(?string $roomToken)
 * @method string|null getTeacherUid()
 * @method void setTeacherUid(?string $teacherUid)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class RoomMap extends Entity {
    protected $activityId;
    protected $activityHost;
    protected $roomToken;
    protected $teacherUid;
    protected $created;

    public function __construct() {
        $this->addType('activityId', Types::BIGINT);
        $this->addType('activityHost', Types::STRING);
        $this->addType('roomToken', Types::STRING);
        $this->addType('teacherUid', Types::STRING);
        $this->addType('created', Types::BIGINT);
    }
}
