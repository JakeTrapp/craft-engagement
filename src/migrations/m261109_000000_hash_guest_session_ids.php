<?php

namespace jtdev\craftengagement\migrations;

use craft\db\Migration;
use craft\db\Query;
use jtdev\craftengagement\helpers\SecurityHelper;

/**
 * Replace raw PHP session IDs stored for guest votes with an HMAC of the session ID.
 *
 * Existing guests keep their votes because lookups now hash the current session ID the same way.
 * Craft records completed migrations; do not infer conversion from the value, because
 * a raw PHP session ID can itself be a 64-character hexadecimal string.
 */
class m261109_000000_hash_guest_session_ids extends Migration
{
    private const TABLES = [
        '{{%engagement_ratings_votes}}',
        '{{%engagement_likes_votes}}',
        '{{%engagement_favorites_entries}}',
    ];

    public function safeUp(): bool
    {
        foreach (self::TABLES as $table) {
            if ($this->db->getTableSchema($table) === null) {
                continue;
            }

            $query = (new Query())
                ->select(['id', 'sessionId'])
                ->from($table)
                ->where(['not', ['sessionId' => null]])
                ->andWhere(['not', ['sessionId' => '']]);

            foreach ($query->batch(500, $this->db) as $rows) {
                foreach ($rows as $row) {
                    $sessionId = (string)$row['sessionId'];
                    $this->update(
                        $table,
                        ['sessionId' => SecurityHelper::hashSessionId($sessionId)],
                        ['id' => (int)$row['id']],
                        [],
                        false
                    );
                }
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261109_000000_hash_guest_session_ids cannot be reverted (hashes are one-way).\n";

        return false;
    }
}
