<?php
declare(strict_types=1);

final class TestRepo
{
    private App $app;
    public TestParser $parser;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->parser = new TestParser();
    }

    public function find(int $id): ?array
    {
        return $this->app->db->one('SELECT * FROM tests WHERE id = ?', [$id]);
    }

    public function forEditor(int $editorId): array
    {
        return $this->app->db->all(
            'SELECT * FROM tests WHERE editor_id = ? AND status != \'archived\' ORDER BY id DESC',
            [$editorId]
        );
    }

    public function save(int $editorId, ?int $id, string $title, string $source, int $blockId, ?int $facilityId): array
    {
        [$items, $rewritten, $changed] = $this->parser->renumber($source);
        $parsed = json_enc($items);
        if ($id) {
            $this->app->db->run(
                'UPDATE tests SET title=?, source=?, parsed=?, block_id=? WHERE id=? AND editor_id=?',
                [$title, $rewritten, $parsed, $blockId, $id, $editorId]
            );
            $row = $this->find($id);
            if (!$row || (string) $row['source'] !== $rewritten || (int) $row['editor_id'] !== $editorId) {
                throw new RuntimeException('Test was not stored.');
            }
        } else {
            $this->app->db->run(
                'INSERT INTO tests (editor_id, facility_id, block_id, title, source, parsed, status) VALUES (?,?,?,?,?,?,\'draft\')',
                [$editorId, $facilityId, $blockId, $title, $rewritten, $parsed]
            );
            $id = (int) $this->app->db->lastId();
        }
        return ['id' => $id, 'source' => $rewritten, 'changed' => $changed, 'items' => $items];
    }

    public function publish(int $id, int $editorId): void
    {
        $this->app->db->run(
            'UPDATE tests SET status=\'current\' WHERE id=? AND editor_id=?',
            [$id, $editorId]
        );
    }

    public function archive(int $id, int $editorId): bool
    {
        $st = $this->app->db->run(
            'UPDATE tests SET status=\'archived\' WHERE id=? AND editor_id=? AND status != \'archived\'',
            [$id, $editorId]
        );
        return $st->rowCount() > 0;
    }

    public function deleteOwned(int $id, int $editorId): bool
    {
        $st = $this->app->db->run(
            'DELETE FROM tests WHERE id=? AND editor_id=?',
            [$id, $editorId]
        );
        return $st->rowCount() > 0;
    }

    public function assignToWriters(int $testId, array $writerIds, ?int $facilityId, int $blockId): array
    {
        $t = $this->find($testId);
        if (!$t) {
            return [];
        }
        $ids = [];
        foreach ($writerIds as $wid) {
            $ids[] = $this->app->writ->create([
                'writer_id' => (int) $wid,
                'facility_id' => $facilityId,
                'block_id' => $blockId,
                'kind' => 'test',
                'test_id' => $testId,
                'title' => $t['title'],
                'work' => 'Test',
                'instructions' => $t['source'],
            ]);
        }
        return $ids;
    }
}
