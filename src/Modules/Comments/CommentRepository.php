<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Comments;

final class CommentRepository
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_ADDRESSED = 'addressed';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUSES         = [self::STATUS_OPEN, self::STATUS_ADDRESSED, self::STATUS_RESOLVED];
    public const RESOLUTIONS      = ['exact', 'moved', 'near', 'detached'];

    private string $table;

    public function __construct()
    {
        $this->table = CommentTable::table_name();
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert($this->table, [
            'parent_id'   => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            'post_id'     => (int) ($row['post_id'] ?? 0),
            'url'         => mb_substr((string) ($row['url'] ?? ''), 0, 500),
            'status'      => (string) ($row['status'] ?? self::STATUS_OPEN),
            'anchor'      => isset($row['anchor']) ? (string) wp_json_encode($row['anchor']) : null,
            'block_path'  => isset($row['block_path']) ? mb_substr((string) $row['block_path'], 0, 60) : null,
            'block_name'  => isset($row['block_name']) ? mb_substr((string) $row['block_name'], 0, 100) : null,
            'quote'       => isset($row['quote']) ? mb_substr((string) $row['quote'], 0, 2000) : null,
            'resolution'  => isset($row['resolution']) ? (string) $row['resolution'] : null,
            'text'        => (string) ($row['text'] ?? ''),
            'author_id'   => (int) ($row['author_id'] ?? 0),
            'author_name' => mb_substr((string) ($row['author_name'] ?? ''), 0, 100),
            'change_id'   => isset($row['change_id']) ? (int) $row['change_id'] : null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

        return $row ? $this->shape($row) : null;
    }

    /**
     * Top-level comments, newest first, with their replies in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(?int $post_id = null, ?string $status = null, int $limit = 200): array
    {
        global $wpdb;
        $where = ['parent_id IS NULL'];
        $args  = [];
        if ($post_id !== null) {
            $where[] = 'post_id = %d';
            $args[]  = $post_id;
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'status = %s';
            $args[]  = $status;
        }
        $args[] = $limit;
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
        $out = [];
        foreach ((array) $rows as $row) {
            $shaped = $this->shape($row);
            $shaped['replies'] = $this->replies((int) $row['id']);
            $out[] = $shaped;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function replies(int $parent_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE parent_id = %d ORDER BY created_at ASC", $parent_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

        return array_map(fn (array $r): array => $this->shape($r), (array) $rows);
    }

    /**
     * The comments a change was proposed for, with their replies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function by_change(int $change_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE change_id = %d AND parent_id IS NULL ORDER BY created_at ASC", $change_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
        $out = [];
        foreach ((array) $rows as $row) {
            $shaped = $this->shape($row);
            $shaped['replies'] = $this->replies((int) $row['id']);
            $out[] = $shaped;
        }

        return $out;
    }

    public function count_open(int $post_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE post_id = %d AND parent_id IS NULL AND status <> %s", $post_id, self::STATUS_RESOLVED)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        global $wpdb;
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update($this->table, $fields, ['id' => $id]);
    }

    public function set_status(int $id, string $status, string $by = ''): void
    {
        $fields = ['status' => $status];
        if ($status === self::STATUS_RESOLVED) {
            $fields['resolved_at'] = current_time('mysql', true);
            $fields['resolved_by'] = mb_substr($by, 0, 100);
        } else {
            $fields['resolved_at'] = null;
            $fields['resolved_by'] = null;
        }
        $this->update($id, $fields);
    }

    /** Every open comment linked to a change, resolved because the change is live. */
    public function resolve_by_change(int $change_id, string $by): int
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->table} WHERE change_id = %d AND parent_id IS NULL AND status <> %s", $change_id, self::STATUS_RESOLVED)); // phpcs:ignore WordPress.DB.PreparedSQL
        foreach ((array) $ids as $id) {
            $this->set_status((int) $id, self::STATUS_RESOLVED, $by);
        }

        return count((array) $ids);
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($this->table, ['parent_id' => $id]);
        $wpdb->delete($this->table, ['id' => $id]);
    }

    /** Resolved comments older than the given days, removed for good. */
    public function purge_resolved(int $days): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->table} WHERE parent_id IS NULL AND status = %s AND resolved_at < %s", self::STATUS_RESOLVED, $cutoff)); // phpcs:ignore WordPress.DB.PreparedSQL
        foreach ((array) $ids as $id) {
            $this->delete((int) $id);
        }

        return count((array) $ids);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function shape(array $row): array
    {
        $anchor = json_decode((string) ($row['anchor'] ?? ''), true);

        return [
            'id'          => (int) $row['id'],
            'parent_id'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'post_id'     => (int) $row['post_id'],
            'url'         => (string) $row['url'],
            'status'      => (string) $row['status'],
            'anchor'      => is_array($anchor) ? $anchor : null,
            'block_path'  => $row['block_path'] !== null ? (string) $row['block_path'] : null,
            'block_name'  => $row['block_name'] !== null ? (string) $row['block_name'] : null,
            'quote'       => $row['quote'] !== null ? (string) $row['quote'] : null,
            'resolution'  => $row['resolution'] !== null ? (string) $row['resolution'] : null,
            'text'        => (string) $row['text'],
            'author_id'   => (int) $row['author_id'],
            'author_name' => (string) $row['author_name'],
            'change_id'   => $row['change_id'] !== null ? (int) $row['change_id'] : null,
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
            'resolved_at' => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            'resolved_by' => $row['resolved_by'] !== null ? (string) $row['resolved_by'] : null,
        ];
    }
}
