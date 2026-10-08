<?php
declare(strict_types=1);

final class WritRepo
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function find(int $id): ?array
    {
        return $this->app->db->one('SELECT * FROM writs WHERE id = ?', [$id]);
    }

    public function create(array $row): int
    {
        $drafts = $row['drafts'] ?? [];
        $redrafts = $row['redrafts'] ?? [];
        $this->app->db->run(
            'INSERT INTO writs (writer_id, facility_id, block_id, kind, memo_id, test_id, instructions, title, work, draft, draft_wordcount, drafts, redrafts, notes, outof)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $row['writer_id'],
                $row['facility_id'] ?? null,
                $row['block_id'] ?? 0,
                $row['kind'] ?? 'writ',
                $row['memo_id'] ?? null,
                $row['test_id'] ?? null,
                $row['instructions'] ?? null,
                $row['title'] ?? '',
                $row['work'] ?? '',
                $row['draft'] ?? '',
                $row['draft_wordcount'] ?? 0,
                json_enc($drafts),
                json_enc($redrafts),
                $row['notes'] ?? '',
                $row['outof'] ?? 100,
            ]
        );
        $id = (int) $this->app->db->lastId();
        if (trim((string) ($row['work'] ?? '')) === '') {
            $this->app->db->run('UPDATE writs SET work = ? WHERE id = ?', ['task-' . $id, $id]);
        }
        if (trim((string) ($row['title'] ?? '')) === '') {
            $this->app->db->run('UPDATE writs SET title = ? WHERE id = ?', ['Untitled', $id]);
        }
        return $id;
    }

    public function forWriter(int $writerId, string $term = 'current'): array
    {
        return $this->app->db->all(
            'SELECT * FROM writs WHERE writer_id = ? AND term_status = ? ORDER BY draft_save_date DESC, id DESC',
            [$writerId, $term]
        );
    }

    public function forEditor(int $editorId, string $review = 'current'): array
    {
        return $this->app->db->all(
            'SELECT w.*, u.name AS writer_name FROM writs w
             JOIN users u ON u.id = w.writer_id
             WHERE u.editor_id = ? AND w.review_status = ?
             ORDER BY w.draft_submit_date DESC, w.id DESC',
            [$editorId, $review]
        );
    }

    public function hasHistory(array $w): bool
    {
        return count(json_arr($w['drafts'] ?? [])) > 1
            || count(json_arr($w['redrafts'] ?? [])) > 1;
    }

    public function saveDraft(int $id, int $writerId, array $fields): bool
    {
        try {
            $this->app->db->run(
                'UPDATE writs SET title=?, work=?, block_id=?, notes=?, draft=?, draft_wordcount=?, writing_time=?, draft_status=\'saved\', draft_save_date=NOW()
                 WHERE id=? AND writer_id=?',
                [
                    $fields['title'], $fields['work'], $fields['block_id'], $fields['notes'],
                    $fields['draft'], $fields['draft_wordcount'], $fields['writing_time'] ?? 0,
                    $id, $writerId,
                ]
            );
            $row = $this->find($id);
            return $row
                && (int) $row['writer_id'] === $writerId
                && (string) $row['draft'] === (string) $fields['draft']
                && (string) $row['title'] === (string) $fields['title']
                && (string) $row['notes'] === (string) $fields['notes'];
        } catch (Throwable $e) {
            return false;
        }
    }

    public function submitDraft(int $id, int $writerId): bool
    {
        try {
            $w = $this->find($id);
            if (!$w || (int) $w['writer_id'] !== $writerId) {
                return false;
            }
            $body = (string) $w['draft'];
            $drafts = json_arr($w['drafts']);
            $drafts[] = [
                'at' => date('c'),
                'body' => $body,
                'wordcount' => (int) $w['draft_wordcount'],
            ];
            $this->app->db->run(
                'UPDATE writs SET drafts=?, draft_status=\'submitted\', draft_submit_date=NOW() WHERE id=? AND writer_id=?',
                [json_enc($drafts), $id, $writerId]
            );
            $row = $this->find($id);
            return $row
                && (string) $row['draft_status'] === 'submitted'
                && (string) $row['draft'] === $body;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function saveEdits(int $id, array $fields): bool
    {
        try {
            $this->app->db->run(
                'UPDATE writs SET block_id=?, title=?, work=?, notes=?, edits=?, edits_wordcount=?, edit_notes=?, scoring=?, score=?, outof=?
                 WHERE id=?',
                [
                    $fields['block_id'], $fields['title'], $fields['work'], $fields['notes'],
                    $fields['edits'], $fields['edits_wordcount'], $fields['edit_notes'],
                    $fields['scoring'] ?? '', $fields['score'], $fields['outof'],
                    $id,
                ]
            );
            $row = $this->find($id);
            return $row && (string) $row['edits'] === (string) $fields['edits']
                && (string) $row['edit_notes'] === (string) $fields['edit_notes'];
        } catch (Throwable $e) {
            return false;
        }
    }

    public function submitReview(int $id, array $fields): bool
    {
        if (!$this->saveEdits($id, $fields)) {
            return false;
        }
        try {
            $this->app->db->run(
                'UPDATE writs SET draft_status=\'reviewed\', edits_status=\'drafting\', edits_date=NOW() WHERE id=?',
                [$id]
            );
            $row = $this->find($id);
            return $row
                && (string) $row['draft_status'] === 'reviewed'
                && (string) $row['edits'] === (string) $fields['edits'];
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Editor redraft: edited text becomes the writer's new starting point. */
    public function sendRedraft(int $id, array $fields): bool
    {
        $w = $this->find($id);
        if (!$w) {
            return false;
        }
        if (!$this->saveEdits($id, $fields)) {
            return false;
        }
        try {
            $drafts = json_arr($w['drafts']);
            $drafts[] = [
                'at' => date('c'),
                'body' => $w['draft'],
                'wordcount' => (int) $w['draft_wordcount'],
                'kind' => 'pre-redraft',
            ];
            $redrafts = json_arr($w['redrafts']);
            $redrafts[] = [
                'at' => date('c'),
                'body' => $fields['edits'],
                'notes' => $fields['edit_notes'] ?? '',
                'wordcount' => (int) $fields['edits_wordcount'],
            ];
            $this->app->db->run(
                'UPDATE writs SET drafts=?, redrafts=?, draft=?, draft_wordcount=?, draft_status=\'redraft\', edits_status=\'drafting\', edits_date=NOW(), score=NULL
                 WHERE id=?',
                [json_enc($drafts), json_enc($redrafts), $fields['edits'], $fields['edits_wordcount'], $id]
            );
            $row = $this->find($id);
            return $row
                && (string) $row['draft_status'] === 'redraft'
                && (string) $row['draft'] === (string) $fields['edits'];
        } catch (Throwable $e) {
            return false;
        }
    }

    public function saveCorrection(int $id, int $writerId, array $fields): bool
    {
        try {
            $this->app->db->run(
                'UPDATE writs SET notes=?, correction=?, correction_wordcount=?, edits_status=\'saved\', corrected_save_date=NOW()
                 WHERE id=? AND writer_id=?',
                [$fields['notes'], $fields['correction'], $fields['correction_wordcount'], $id, $writerId]
            );
            $row = $this->find($id);
            return $row
                && (int) $row['writer_id'] === $writerId
                && (string) $row['correction'] === (string) $fields['correction'];
        } catch (Throwable $e) {
            return false;
        }
    }

    public function submitCorrection(int $id, int $writerId): bool
    {
        try {
            $before = $this->find($id);
            if (!$before || (int) $before['writer_id'] !== $writerId) {
                return false;
            }
            $body = (string) $before['correction'];
            $this->app->db->run(
                'UPDATE writs SET edits_status=\'submitted\', corrected_submit_date=NOW() WHERE id=? AND writer_id=?',
                [$id, $writerId]
            );
            $row = $this->find($id);
            return $row
                && (string) $row['edits_status'] === 'submitted'
                && (string) $row['correction'] === $body;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function score(int $id, array $fields): bool
    {
        try {
            $this->app->db->run(
                'UPDATE writs SET scoring=?, score=?, outof=?, edits_status=\'scored\', scoring_date=NOW() WHERE id=?',
                [$fields['scoring'], $fields['score'], $fields['outof'], $id]
            );
            $row = $this->find($id);
            return $row && (string) $row['edits_status'] === 'scored'
                && (string) $row['scoring'] === (string) $fields['scoring'];
        } catch (Throwable $e) {
            return false;
        }
    }

    public function markViewed(int $id, int $writerId): void
    {
        $this->app->db->run(
            'UPDATE writs SET edits_status=\'viewed\', edits_viewed_date=NOW()
             WHERE id=? AND writer_id=? AND draft_status=\'reviewed\' AND edits_status=\'drafting\'',
            [$id, $writerId]
        );
    }

    public function archive(int $id, string $which = 'term'): void
    {
        $col = $which === 'review' ? 'review_status' : 'term_status';
        $this->app->db->run("UPDATE writs SET {$col} = 'archived' WHERE id = ?", [$id]);
    }

    public function saveTestAnswers(int $id, int $writerId, array $answers, int $auto, int $outof): bool
    {
        try {
            $this->app->db->run(
                'UPDATE writs SET test_answers=?, test_auto_score=?, draft_status=\'submitted\', draft_submit_date=NOW(), outof=?, score=?
                 WHERE id=? AND writer_id=?',
                [json_enc($answers), $auto, $outof, $auto, $id, $writerId]
            );
            $row = $this->find($id);
            return $row
                && (string) $row['draft_status'] === 'submitted'
                && (string) $row['test_answers'] === json_enc($answers);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Writer has not handed the current stage back to the editor. */
    public function isPeek(array $w): bool
    {
        $ds = (string) ($w['draft_status'] ?? '');
        $es = (string) ($w['edits_status'] ?? '');
        if ($ds === 'submitted' || $es === 'submitted' || $es === 'scored') {
            return false;
        }
        return $ds === 'saved' || $ds === 'redraft' || $es === 'saved';
    }

    /**
     * Heal pairs the workflow cannot produce.
     * redraft+drafting is valid (sent back, writer has not saved yet) and is left alone.
     */
    public function repairStatuses(): void
    {
        $this->app->db->run(
            "UPDATE writs SET draft_status='submitted'
             WHERE draft_status IN ('saved','redraft') AND edits_status='submitted'"
        );
        $this->app->db->run(
            "UPDATE writs SET draft_status='reviewed'
             WHERE draft_status IN ('saved','redraft','submitted') AND edits_status='scored'"
        );
        $this->app->db->run(
            "UPDATE writs SET edits_status='drafting'
             WHERE draft_status='redraft' AND edits_status NOT IN ('drafting','saved','submitted','scored')"
        );
    }

    public function comments(int $writId): array
    {
        if (!$this->app->db->tableExists('writ_comments')) {
            return [];
        }
        return $this->app->db->all(
            'SELECT c.*, u.name AS observer_name FROM writ_comments c
             LEFT JOIN users u ON u.id = c.observer_id
             WHERE c.writ_id = ? ORDER BY c.id ASC',
            [$writId]
        );
    }

    public function addComment(int $writId, int $observerId, string $body): int
    {
        $this->app->db->run(
            'INSERT INTO writ_comments (writ_id, observer_id, body) VALUES (?,?,?)',
            [$writId, $observerId, $body]
        );
        return (int) $this->app->db->lastId();
    }

    public function saveComment(int $id, int $observerId, string $body): bool
    {
        $st = $this->app->db->run(
            'UPDATE writ_comments SET body = ?, save_date = NOW() WHERE id = ? AND observer_id = ?',
            [$body, $id, $observerId]
        );
        return $st->rowCount() > 0;
    }

    /** @return array{0:list<array>,1:bool} */
    public function dashList(array $where, array $params, string $sort, int $limit = 25): array
    {
        $order = match ($sort) {
            'creation' => 'w.id DESC',
            'work' => 'w.work ASC',
            'title' => 'w.title ASC',
            'status' => "w.draft_status='submitted' DESC, w.edits_status='submitted' DESC, w.id DESC",
            default => 'GREATEST(
                COALESCE(w.draft_open_date,\'1970-01-01\'),
                COALESCE(w.draft_save_date,\'1970-01-01\'),
                COALESCE(w.draft_submit_date,\'1970-01-01\'),
                COALESCE(w.edits_date,\'1970-01-01\'),
                COALESCE(w.scoring_date,\'1970-01-01\'),
                COALESCE(w.draft_save_date,\'1970-01-01\')
            ) DESC',
        };
        $sql = 'SELECT w.*, u.name AS writer_name FROM writs w LEFT JOIN users u ON u.id = w.writer_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ' . $order . ' LIMIT ' . (int) ($limit + 1);
        $rows = $this->app->db->all($sql, $params);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        return [$rows, $more];
    }
}
