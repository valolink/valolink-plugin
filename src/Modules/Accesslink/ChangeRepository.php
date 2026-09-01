<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * The one place anything reads or writes the change queue.
 */
final class ChangeRepository
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPLIED  = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_STALE    = 'stale';

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_UPDATE_BLOCK = 'update_block';
    public const ACTION_UPDATE_TEXT  = 'update_text';
    public const ACTION_INSERT_BLOCK = 'insert_block';
    public const ACTION_DELETE_BLOCK = 'delete_block';
    public const ACTION_MOVE_BLOCK   = 'move_block';

    /** Actions that rearrange the document, so positional paths may shift. */
    public const STRUCTURAL_ACTIONS = [
        self::ACTION_INSERT_BLOCK,
        self::ACTION_DELETE_BLOCK,
        self::ACTION_MOVE_BLOCK,
    ];

    public function insert(array $row): int
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->insert(ChangeTable::table_name(), [
            'created_at'      => $now,
            'updated_at'      => $now,
            'status'          => self::STATUS_PENDING,
            'entity_type'     => $row['entity_type'] ?? 'post',
            'action'          => $row['action'],
            'target_id'       => $row['target_id'] ?? null,
            'post_type'       => $row['post_type'] ?? 'post',
            'base_hash'       => $row['base_hash'] ?? null,
            'payload'         => wp_json_encode($row['payload'] ?? []),
            'summary'         => $row['summary'] ?? null,
            'note'            => $row['note'] ?? null,
            'requested_by'    => $row['requested_by'] ?? null,
            'idempotency_key' => $row['idempotency_key'] ?? null,
        ]);

        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = ChangeTable::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function find_by_idempotency_key(string $key): ?array
    {
        global $wpdb;
        $table = ChangeTable::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE idempotency_key = %s", $key),
            ARRAY_A,
        );

        return $row ? $this->hydrate($row) : null;
    }

    /** @return array<int, array> */
    public function list(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $table = ChangeTable::table_name();

        $sql = $status !== null
            ? $wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status,
                $limit,
                $offset,
            )
            : $wpdb->prepare(
                "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset,
            );

        return array_map([$this, 'hydrate'], $wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    public function count(?string $status = null): int
    {
        global $wpdb;
        $table = ChangeTable::table_name();

        $sql = $status !== null
            ? $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", $status)
            : "SELECT COUNT(*) FROM $table";

        return (int) $wpdb->get_var($sql);
    }

    public function update(int $id, array $fields): void
    {
        global $wpdb;
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update(ChangeTable::table_name(), $fields, ['id' => $id]);
    }

    /** Prune resolved rows older than $days. Pending rows are never pruned. */
    public function prune(int $days): int
    {
        global $wpdb;
        $table  = ChangeTable::table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE status <> %s AND updated_at < %s",
            self::STATUS_PENDING,
            $cutoff,
        ));
    }

    private function hydrate(array $row): array
    {
        $decoded = json_decode((string) $row['payload'], true);
        $row['payload'] = is_array($decoded) ? $decoded : [];
        $row['id'] = (int) $row['id'];
        $row['target_id'] = $row['target_id'] !== null ? (int) $row['target_id'] : null;

        return $row;
    }
}
