<?php
declare(strict_types=1);

final class NoteRepo
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function find(int $id): ?array
    {
        return $this->app->db->one('SELECT * FROM notes WHERE id = ?', [$id]);
    }

    public function create(array $row): int
    {
        $this->app->db->run(
            'INSERT INTO notes (type, status, writer_id, editor_id, editor_set_writer_id, editor_set_block, body, pinned, seen_writer, seen_observer)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $row['type'] ?? 'note',
                $row['status'] ?? 'live',
                $row['writer_id'] ?? null,
                $row['editor_id'] ?? null,
                $row['editor_set_writer_id'] ?? 0,
                $row['editor_set_block'] ?? 0,
                $row['body'] ?? '',
                !empty($row['pinned']) ? 1 : 0,
                $row['seen_writer'] ?? 'new',
                $row['seen_observer'] ?? 'new',
            ]
        );
        return (int) $this->app->db->lastId();
    }

    public function saveBody(int $id, string $body, array $extra = []): bool
    {
        try {
            $sql = 'UPDATE notes SET body = ?';
            $params = [$body];
            if (isset($extra['editor_set_writer_id'])) {
                $sql .= ', editor_set_writer_id = ?';
                $params[] = $extra['editor_set_writer_id'];
            }
            if (isset($extra['editor_set_block'])) {
                $sql .= ', editor_set_block = ?';
                $params[] = $extra['editor_set_block'];
            }
            if (isset($extra['type'])) {
                $sql .= ', type = ?';
                $params[] = $extra['type'];
            }
            if (isset($extra['status'])) {
                $sql .= ', status = ?';
                $params[] = $extra['status'];
            }
            if (array_key_exists('pinned', $extra)) {
                $sql .= ', pinned = ?';
                $params[] = $extra['pinned'] ? 1 : 0;
            }
            $sql .= ', seen_writer = \'new\' WHERE id = ?';
            $params[] = $id;
            $this->app->db->run($sql, $params);
            $row = $this->find($id);
            return $row && (string) $row['body'] === $body;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function markRead(int $id): void
    {
        $this->app->db->run('UPDATE notes SET seen_writer = \'read\' WHERE id = ?', [$id]);
    }

    public function forWriter(int $writerId, string $type = 'note'): array
    {
        return $this->app->db->all(
            'SELECT * FROM notes WHERE writer_id = ? AND type = ? AND status = \'live\' ORDER BY pinned DESC, id DESC',
            [$writerId, $type]
        );
    }

    public function memosForWriter(int $writerId): array
    {
        return $this->app->db->all(
            'SELECT * FROM notes WHERE type = \'memo\' AND status = \'live\'
             AND (editor_set_writer_id = ? OR writer_id = ?)
             ORDER BY pinned DESC, id DESC',
            [$writerId, $writerId]
        );
    }

    public function memosForEditor(int $editorId): array
    {
        return $this->app->db->all(
            'SELECT * FROM notes WHERE editor_id = ? AND type IN (\'memo\',\'task\') AND status = \'live\' ORDER BY id DESC',
            [$editorId]
        );
    }

    public function memosForBlock(int $blockId): array
    {
        return $this->app->db->all(
            'SELECT * FROM notes WHERE type IN (\'memo\',\'task\') AND status = \'live\' AND editor_set_block = ? ORDER BY id DESC',
            [$blockId]
        );
    }

    public function copyNoteToMemo(int $noteId, int $editorId): int
    {
        $n = $this->find($noteId);
        if (!$n) {
            throw new RuntimeException('Note not found');
        }
        return $this->create([
            'type' => 'memo',
            'status' => 'live',
            'editor_id' => $editorId,
            'editor_set_writer_id' => $n['writer_id'] ?? 0,
            'body' => $n['body'],
        ]);
    }

    public function pin(int $id, int $writerId, bool $pinned): void
    {
        $this->app->db->run(
            'UPDATE notes SET pinned = ? WHERE id = ? AND writer_id = ?',
            [$pinned ? 1 : 0, $id, $writerId]
        );
    }

    public function pinnedFor(int $writerId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->app->db->all(
            "SELECT * FROM notes WHERE pinned = 1 AND writer_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$writerId]
        );
    }

    public function memosForDash(int $writerId, array $blockIds, int $limit = 5): array
    {
        if (!$this->app->db || !$this->app->db->columnExists('notes', 'type')) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $sql = "SELECT * FROM notes WHERE type IN ('memo','task') AND status = 'live'
            AND (editor_set_writer_id = ? OR (editor_set_writer_id = 0 AND editor_set_block = 0)";
        $params = [$writerId];
        $ids = array_values(array_filter(array_map('intval', $blockIds), static fn ($id) => $id > 0));
        if ($ids) {
            $in = implode(',', $ids);
            $sql .= " OR editor_set_block IN ({$in})";
        }
        $sql .= ") ORDER BY save_date DESC LIMIT {$limit}";
        return $this->app->db->all($sql, $params);
    }

    /** @return array<int,string> id => "Title (Writer: Name|Block: Name)" */
    public function labeledForEditor(int $editorId): array
    {
        $this->app->need('user');
        $this->app->need('block');
        $out = [];
        foreach ($this->memosForEditor($editorId) as $n) {
            $title = note_heading($n['body'] ?? '');
            $paren = 'Main';
            if ((int) ($n['editor_set_writer_id'] ?? 0) > 0) {
                $w = $this->app->user->find((int) $n['editor_set_writer_id']) ?: [];
                $paren = 'Writer: ' . (string) ($w['name'] ?? '');
            } elseif ((int) ($n['editor_set_block'] ?? 0) > 0) {
                $b = $this->app->block->find((int) $n['editor_set_block']) ?: [];
                $paren = 'Block: ' . (string) ($b['name'] ?? '');
            }
            $out[(int) $n['id']] = $title . ' (' . $paren . ')';
        }
        natcasesort($out);
        return $out;
    }

    public function unopenedMemos(int $writerId, array $blockIds, int $limit = 25): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "SELECT * FROM notes WHERE type IN ('memo','task') AND status = 'live' AND seen_writer = 'new'
            AND (editor_set_writer_id = ? OR (editor_set_writer_id = 0 AND editor_set_block = 0)";
        $params = [$writerId];
        $ids = array_values(array_filter(array_map('intval', $blockIds), static fn ($id) => $id > 0));
        if ($ids) {
            $in = implode(',', $ids);
            $sql .= " OR editor_set_block IN ({$in})";
        }
        $sql .= ") ORDER BY save_date DESC LIMIT {$limit}";
        return $this->app->db->all($sql, $params);
    }

    public function deleteOwned(int $id, int $editorId): bool
    {
        $n = $this->find($id);
        if (!$n || (int) ($n['editor_id'] ?? 0) !== $editorId) {
            return false;
        }
        if (!in_array((string) ($n['type'] ?? ''), ['memo', 'task'], true)) {
            return false;
        }
        $this->app->db->run('DELETE FROM notes WHERE id = ? AND editor_id = ?', [$id, $editorId]);
        return true;
    }
}
