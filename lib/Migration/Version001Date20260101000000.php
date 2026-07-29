<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates room_map, user_map and event_dedupe tables.
 */
class Version001Date20260101000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('talkbridge_room_map')) {
            $t = $schema->createTable('talkbridge_room_map');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('activity_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('activity_host', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('room_token', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('teacher_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('created', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['activity_host', 'activity_id'], 'tb_roommap_activity_ux');
        }

        if (!$schema->hasTable('talkbridge_user_map')) {
            $t = $schema->createTable('talkbridge_user_map');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('nc_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('provisioned', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $t->addColumn('created', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['email'], 'tb_usermap_email_ux');
        }

        if (!$schema->hasTable('talkbridge_event_dedupe')) {
            $t = $schema->createTable('talkbridge_event_dedupe');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('event_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('received_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id'], 'tb_dedupe_pk');
            $t->addUniqueIndex(['event_id'], 'tb_dedupe_eventid_ux');
        }

        return $schema;
    }
}
