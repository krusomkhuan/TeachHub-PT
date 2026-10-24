<?php
/**
 * ไฟล์: board/topic.php?id=xx
 * อ่านกระทู้ ตอบกระทู้ แก้ไข/ลบข้อความของตนเอง และเครื่องมือผู้ดูแลระบบ (ปักหมุด เปลี่ยนสถานะ ลบ)
 *   &edit=1          แก้ไขกระทู้ (เจ้าของ/ผู้ดูแลระบบ)
 *   &edit_reply=xx   แก้ไขคำตอบ (เจ้าของ/ผู้ดูแลระบบ)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$isMod = board_can_moderate($user);
$id = get_int('id') ?: (int)post('topic_id');
$topic = board_topic_fetch($id);
if (!$topic) {
    abort_page(404, 'ไม่พบกระทู้', 'กระทู้นี้อาจถูกลบไปแล้ว');
}
$self = 'board/topic.php?id=' . $id;
$isOwner = (int)$topic['user_id'] === $uid;
$closed = $topic['status'] === 'closed';
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- ตอบกระทู้ ----------
    if ($action === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($closed && !$isMod) {
            $errors['reply'] = 'กระทู้นี้ปิดแล้ว ไม่สามารถตอบได้';
        } else {
            $errors += array_intersect_key(board_validate('', $body, false), ['body' => 1]);
            if (isset($errors['body'])) {
                $errors['reply'] = $errors['body'];
            } elseif (board_rate_limited($uid)) {
                $errors['reply'] = 'โพสต์ถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
            }
        }
        if (!$errors) {
            $rid = db_insert('INSERT INTO board_replies (topic_id, user_id, body) VALUES (?, ?, ?)', 'iis', [$id, $uid, $body]);
            board_refresh_counts($id);
            // มีผู้อื่นตอบกระทู้ที่ "รอตอบ" → เปลี่ยนเป็น "ตอบแล้ว"
            if (!$isOwner && $topic['status'] === 'open') {
                db_exec("UPDATE board_topics SET status = 'answered' WHERE id = ?", 'i', [$id]);
            }
            log_activity('board_reply', 'ตอบกระทู้ #' . $id);
            flash('success', 'ตอบกระทู้เรียบร้อยแล้ว');
            redirect($self . '#reply-' . $rid);
        }
    }

    // ---------- แก้ไขกระทู้ ----------
    if ($action === 'edit_topic') {
        if (!$isOwner && !$isMod) {
            abort_page(403, 'ไม่มีสิทธิ์แก้ไขกระทู้นี้');
        }
        $title = mb_substr(post('title'), 0, 200);
        $body = trim((string)($_POST['body'] ?? ''));
        $cat = isset(board_categories()[post('category')]) ? post('category') : $topic['category'];
        $errors = board_validate($title, $body);
        if (!$errors) {
            db_exec('UPDATE board_topics SET title = ?, body = ?, category = ? WHERE id = ?', 'sssi', [$title, $body, $cat, $id]);
            log_activity('board_edit', 'แก้ไขกระทู้ #' . $id);
            flash('success', 'บันทึกการแก้ไขแล้ว');
            redirect($self);
        }
        $_GET['edit'] = '1';
    }

    // ---------- แก้ไขคำตอบ ----------
    if ($action === 'edit_reply') {
        $reply = db_one('SELECT * FROM board_replies WHERE id = ? AND topic_id = ?', 'ii', [(int)post('reply_id'), $id]);
        if (!$reply || ((int)$reply['user_id'] !== $uid && !$isMod)) {
            abort_page(403, 'ไม่มีสิทธิ์แก้ไขคำตอบนี้');
        }
        $body = trim((string)($_POST['body'] ?? ''));
        $errors = board_validate('', $body, false);
        if (!$errors) {
            db_exec('UPDATE board_replies SET body = ? WHERE id = ?', 'si', [$body, (int)$reply['id']]);
            log_activity('board_edit', 'แก้ไขคำตอบ #' . (int)$reply['id'] . ' ในกระทู้ #' . $id
                . ((int)$reply['user_id'] !== $uid ? ' (ของ ' . log_user_name((int)$reply['user_id']) . ')' : ''));
            flash('success', 'บันทึกการแก้ไขแล้ว');
            redirect($self . '#reply-' . (int)$reply['id']);
        }
        $_GET['edit_reply'] = (string)$reply['id'];
    }

    // ---------- ลบคำตอบ ----------
    if ($action === 'delete_reply') {
        $reply = db_one('SELECT * FROM board_replies WHERE id = ? AND topic_id = ?', 'ii', [(int)post('reply_id'), $id]);
        if (!$reply || ((int)$reply['user_id'] !== $uid && !$isMod)) {
            abort_page(403, 'ไม่มีสิทธิ์ลบคำตอบนี้');
        }
        db_exec('DELETE FROM board_replies WHERE id = ?', 'i', [(int)$reply['id']]);
        board_refresh_counts($id);
        log_activity('board_delete', 'ลบคำตอบ #' . (int)$reply['id'] . ' ในกระทู้ #' . $id
            . ((int)$reply['user_id'] !== $uid ? ' (ของ ' . log_user_name((int)$reply['user_id']) . ')' : ''));
        flash('success', 'ลบคำตอบแล้ว');
        redirect($self);
    }

    // ---------- ลบกระทู้ ----------
    if ($action === 'delete_topic') {
        if (!$isMod && !($isOwner && (int)$topic['reply_count'] === 0)) {
            flash('error', 'ลบกระทู้ได้เฉพาะเจ้าของเมื่อยังไม่มีผู้ตอบ หรือผู้ดูแลระบบ');
            redirect($self);
        }
        db_exec('DELETE FROM board_topics WHERE id = ?', 'i', [$id]);
        log_activity('board_delete', 'ลบกระทู้ #' . $id . ' ' . $topic['title']);
        flash('success', 'ลบกระทู้แล้ว');
        redirect('board/index.php');
    }

    // ---------- ผู้ดูแลระบบ: สถานะ / ปักหมุด ----------
    if ($action === 'moderate') {
        if (!$isMod) {
            abort_page(403, 'เฉพาะผู้ดูแลระบบ');
        }
        $status = isset(board_statuses()[post('status')]) ? post('status') : $topic['status'];
        $pin = isset($_POST['is_pinned']) ? 1 : 0;
        db_exec('UPDATE board_topics SET status = ?, is_pinned = ? WHERE id = ?', 'sii', [$status, $pin, $id]);
        log_activity('board_moderate', 'กระทู้ #' . $id . ' สถานะ ' . board_statuses()[$status][0] . ($pin ? ' (ปักหมุด)' : ''));
        flash('success', 'บันทึกสถานะกระทู้แล้ว');
        redirect($self);
    }
}

// นับผู้เข้าชม (ครั้งเดียวต่อ session)
$_SESSION['board_viewed'] = $_SESSION['board_viewed'] ?? [];
if (!in_array($id, $_SESSION['board_viewed'], true)) {
    db_exec('UPDATE board_topics SET views = views + 1 WHERE id = ?', 'i', [$id]);
    $_SESSION['board_viewed'][] = $id;
    $topic['views']++;
}

$replies = db_all(
    'SELECT r.*, u.prefix, u.first_name, u.last_name, u.role AS author_role, u.position AS author_position, u.avatar, u.id AS author_id
     FROM board_replies r LEFT JOIN users u ON u.id = r.user_id
     WHERE r.topic_id = ? ORDER BY r.id',
    'i',
    [$id]
);
$editTopic = get('edit') === '1' && ($isOwner || $isMod);
$editReply = get_int('edit_reply');
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

/** ป้ายผู้โพสต์ */
$authorLine = function (array $row) use ($topic): string {
    $html = '<span class="fw-semibold">' . e(board_author($row)) . '</span>';
    if (($row['author_role'] ?? '') === 'admin') {
        $html .= ' <span class="badge bg-primary">ผู้ดูแลระบบ</span>';
    }
    if (!empty($row['author_id']) && (int)$row['author_id'] === (int)$topic['user_id'] && isset($row['topic_id'])) {
        $html .= ' <span class="badge bg-light text-dark border">เจ้าของกระทู้</span>';
    }
    if (!empty($row['author_position'])) {
        $html .= ' <span class="text-muted small">· ' . e($row['author_position']) . '</span>';
    }
    return $html;
};

$pageTitle = 'กระดานข่าว';
$pageIcon = 'bi-chat-square-text';
$breadcrumbs = ['กระดานข่าว' => 'board/index.php', mb_strimwidth($topic['title'], 0, 50, '…') => null];
$activeMenu = 'board/index.php';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <!-- กระทู้ -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <?php if ($editTopic): ?>
                    <form method="post" action="<?= e(url($self)) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit_topic"><input type="hidden" name="topic_id" value="<?= $id ?>">
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <select class="form-select" name="category">
                                    <?php foreach (board_categories() as $k => [$label]): ?>
                                        <option value="<?= e($k) ?>" <?= (post('category') ?: $topic['category']) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <input class="form-control" name="title" maxlength="200" value="<?= e(is_post() ? post('title') : $topic['title']) ?>"><?= $err('title') ?>
                            </div>
                        </div>
                        <textarea class="form-control" name="body" rows="8" maxlength="10000"><?= e(is_post() ? (string)($_POST['body'] ?? '') : $topic['body']) ?></textarea><?= $err('body') ?>
                        <div class="d-flex gap-2 justify-content-end mt-2">
                            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                            <button class="btn btn-accent"><i class="bi bi-save me-1"></i> บันทึก</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php if ($topic['is_pinned']): ?><span class="badge bg-accent"><i class="bi bi-pin-angle-fill me-1"></i>ปักหมุด</span><?php endif; ?>
                        <?= board_category_badge($topic['category']) ?> <?= board_status_badge($topic['status']) ?>
                    </div>
                    <h2 class="h4 fw-bold text-primary-th mb-3"><?= e($topic['title']) ?></h2>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <img src="<?= e(avatar_url(['id' => $topic['author_id'], 'avatar' => $topic['avatar']])) ?>" class="avatar-sm" alt="">
                        <div class="small"><?= $authorLine($topic) ?><br><span class="text-muted"><?= e(thai_datetime($topic['created_at'])) ?>
                            <?= $topic['updated_at'] && $topic['updated_at'] !== $topic['created_at'] ? ' · แก้ไข ' . e(thai_datetime($topic['updated_at'])) : '' ?>
                            · <i class="bi bi-eye"></i> <?= (int)$topic['views'] ?></span></div>
                    </div>
                    <div class="lh-lg"><?= board_render($topic['body']) ?></div>
                    <?php if ($isOwner || $isMod): ?>
                        <div class="d-flex gap-2 mt-3 justify-content-end">
                            <a href="<?= e(url($self . '&edit=1')) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>แก้ไข</a>
                            <?php if ($isMod || (int)$topic['reply_count'] === 0): ?>
                                <form method="post" action="<?= e(url($self)) ?>" class="d-inline" data-confirm="ลบกระทู้นี้และคำตอบทั้งหมด?" data-confirm-icon="warning">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_topic"><input type="hidden" name="topic_id" value="<?= $id ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>ลบ</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- คำตอบ -->
        <h3 class="h6 fw-bold mb-3"><i class="bi bi-chat-left-text me-1"></i> คำตอบ (<?= count($replies) ?>)</h3>
        <?php foreach ($replies as $r): $canEdit = (int)$r['user_id'] === $uid || $isMod; ?>
            <div class="card mb-3 <?= ($r['author_role'] ?? '') === 'admin' ? 'border-start border-4 border-primary' : '' ?>" id="reply-<?= (int)$r['id'] ?>">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <img src="<?= e(avatar_url(['id' => $r['author_id'], 'avatar' => $r['avatar']])) ?>" class="avatar-sm" alt="">
                        <div class="small flex-fill"><?= $authorLine($r) ?><br><span class="text-muted"><?= e(thai_datetime($r['created_at'])) ?></span></div>
                        <?php if ($canEdit && $editReply !== (int)$r['id']): ?>
                            <a href="<?= e(url($self . '&edit_reply=' . (int)$r['id'] . '#reply-' . (int)$r['id'])) ?>" class="btn btn-sm btn-link" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= e(url($self)) ?>" class="d-inline" data-confirm="ลบคำตอบนี้?" data-confirm-icon="warning">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_reply"><input type="hidden" name="topic_id" value="<?= $id ?>">
                                <input type="hidden" name="reply_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-link text-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit && $editReply === (int)$r['id']): ?>
                        <form method="post" action="<?= e(url($self)) ?>" novalidate>
                            <?= csrf_field() ?><input type="hidden" name="action" value="edit_reply"><input type="hidden" name="topic_id" value="<?= $id ?>">
                            <input type="hidden" name="reply_id" value="<?= (int)$r['id'] ?>">
                            <textarea class="form-control" name="body" rows="4" maxlength="10000"><?= e(is_post() ? (string)($_POST['body'] ?? '') : $r['body']) ?></textarea><?= $err('body') ?>
                            <div class="d-flex gap-2 justify-content-end mt-2">
                                <a href="<?= e(url($self)) ?>" class="btn btn-sm btn-outline-secondary">ยกเลิก</a>
                                <button class="btn btn-sm btn-accent">บันทึก</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="lh-lg"><?= board_render($r['body']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- ฟอร์มตอบ -->
        <div class="card" id="replyForm">
            <div class="card-header"><i class="bi bi-reply me-2"></i>ตอบกระทู้</div>
            <div class="card-body">
                <?php if ($closed && !$isMod): ?>
                    <div class="text-muted"><i class="bi bi-lock me-1"></i>กระทู้นี้ปิดแล้ว ไม่สามารถตอบได้</div>
                <?php else: ?>
                    <form method="post" action="<?= e(url($self)) ?>" novalidate>
                        <?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="topic_id" value="<?= $id ?>">
                        <textarea class="form-control<?= isset($errors['reply']) ? ' is-invalid' : '' ?>" name="body" rows="4" maxlength="10000"
                                  placeholder="พิมพ์คำตอบหรือข้อมูลเพิ่มเติม"><?= post('action') === 'reply' ? e((string)($_POST['body'] ?? '')) : '' ?></textarea><?= $err('reply') ?>
                        <div class="text-end mt-2">
                            <button class="btn btn-accent px-4" data-loading-text="กำลังส่ง..."><i class="bi bi-send me-1"></i> ส่งคำตอบ</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <?php if ($isMod): ?>
            <div class="card mb-4 border-top border-4 border-primary">
                <div class="card-header"><i class="bi bi-shield-check me-2"></i>ผู้ดูแลระบบ</div>
                <div class="card-body">
                    <form method="post" action="<?= e(url($self)) ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="moderate"><input type="hidden" name="topic_id" value="<?= $id ?>">
                        <label class="form-label small" for="status">สถานะกระทู้</label>
                        <select class="form-select mb-2" name="status" id="status">
                            <?php foreach (board_statuses() as $k => [$label]): ?>
                                <option value="<?= e($k) ?>" <?= $topic['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="is_pinned" id="is_pinned" value="1" <?= $topic['is_pinned'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_pinned">ปักหมุดไว้ด้านบน</label>
                        </div>
                        <button class="btn btn-primary w-100"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </form>
                    <div class="form-text mt-2">ข้อเสนอพัฒนาระบบ: ใช้ “รับไว้พัฒนา” → “ดำเนินการแล้ว” เพื่อแจ้งความคืบหน้าให้ผู้เสนอทราบ</div>
                </div>
            </div>
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>ความหมายของสถานะ</div>
            <ul class="list-group list-group-flush small">
                <?php $desc = ['open' => 'ยังไม่มีผู้ตอบ', 'answered' => 'มีผู้ตอบแล้ว', 'planned' => 'ผู้ดูแลระบบรับข้อเสนอไว้พัฒนา',
                    'done' => 'พัฒนา/แก้ไขเรียบร้อยแล้ว', 'closed' => 'ปิดกระทู้ ไม่รับคำตอบเพิ่ม']; ?>
                <?php foreach (board_statuses() as $k => $s): ?>
                    <li class="list-group-item d-flex gap-2 align-items-center"><?= board_status_badge($k) ?><span class="text-muted"><?= e($desc[$k]) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <a href="<?= e(url('board/index.php')) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> กลับกระดานข่าว</a>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
