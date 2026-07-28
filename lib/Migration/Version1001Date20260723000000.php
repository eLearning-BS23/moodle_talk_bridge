<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Single-use SSO ticket ledger (D12): id, nonce UNIQUE, uid, expires. */
class Version1001Date20260723000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('talkbridge_sso_nonce')) {
            $table = $schema->createTable('talkbridge_sso_nonce');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('nonce', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('expires', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['nonce'], 'tb_sso_nonce_uniq');
        }
        return $schema;
    }
}
