<?php
/**
 * ไฟล์: admin/announcements.php
 * จัดการประกาศข่าวสาร (แสดงในหน้าบ้านและแดชบอร์ด) — เพิ่ม/แก้ไข/ปักหมุด/เผยแพร่-ซ่อน/ลบ
 * เนื้อหาแสดงผลแบบข้อความล้วน (escape ทุกครั้ง) เว้นบรรทัดได้
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $title = mb_substr(post('title'), 0, 255);
        $content = mb_substr(post('content'), 0, 20000);
        $pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $published = isset($_POST['is_published']) ? 1 : 0;
        $pubAt = post('published_at');
        $pubTs = $pubAt !== '' ? strtotime(str_replace('T', ' ', $pubAt)) : time();
        if ($title === '') {
            $errors['title'] = 'กรุณากรอกหัวข้อประกาศ';
        }
        if ($content === '') {
            $errors['content'] = 'กรุณากรอกเนื้อหา';
        }
        if (!$pubTs) {
            $errors['published_at'] = 'วันเวลาเผยแพร่ไม่ถูกต้อง';
        }
        if (!$errors) {
            $pubAtSql = date('Y-m-d H:i:s', $pubTs);
            if ($id) {
                db_exec('UPDATE announcements SET title = ?, content = ?, is_pinned = ?, is_published = ?, published_at = ? WHERE id = ?',
                    'ssiisi', [$title, $content, $pinned, $published, $pubAtSql, $id]);
            } else {
                db_insert('INSERT INTO announcements (title, content, is_pinned, is_published, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                    'ssiisi', [$title, $content, $pinned, $published, $pubAtSql, (int)$user['id']]);
            }
            log_activity('announcement_save', ($id ? 'แก้ไข' : 'เพิ่ม') . 'ประกาศ: ' . $title);
            flash('success', 'บันทึกประกาศแล้ว');
            redirect('admin/announcements.php');
        }
    }
    if ($action === 'toggle') {
        db_exec('UPDATE announcements SET is_published = 1 - is_published WHERE id = ?', 'i', [$id]);
        $ann = db_one('SELECT title, is_published FROM announcements WHERE id = ?', 'i', [$id]);
        if ($ann) {
            log_activity('announcement_toggle', ($ann['is_published'] ? 'เผยแพร่' : 'ซ่อน') . 'ประกาศ #' . $id . ': ' . $ann['title']);
        }
        flash('success', 'เปลี่ยนสถานะการเผยแพร่แล้ว');
        redirect('admin/announcements.php');
    }
    if ($action === 'delete') {
        $annTitle = (string)db_value('SELECT title FROM announcements WHERE id = ?', 'i', [$id]);
        db_exec('DELETE FROM announcements WHERE id = ?', 'i', [$id]);
        log_activity('announcement_delete', 'ลบประกาศ #' . $id . ($annTitle !== '' ? ': ' . $annTitle : ''));
        flash('success', 'ลบประกาศแล้ว');
        redirect('admin/announcements.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM announcements WHERE id = ?', 'i', [$editId]) : null;
$showForm = $editing || get('new') === '1' || $errors;
$rows = db_all(
    "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS author FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by ORDER BY a.is_pinned DESC, a.published_at DESC"
);
$fv = function (string $k, string $d = '') use ($editing) {
    if (is_post()) {
        return post($k);
    }
    if ($editing && $k === 'published_at') {
        return date('Y-m-d\TH:i', strtotime($editing['published_at']));
    }
    return (string)($editing[$k] ?? $d);
};
$fChk = fn(string $k, int $d) => (is_post() ? isset($_POST[$k]) : (int)($editing[$k] ?? $d) === 1) ? 'checked' : '';

$pageTitle = 'ประกาศข่าวสาร';
$pageIcon = 'bi-megaphone';
$breadcrumbs = ['ประกาศข่าวสาร' => null];
$pageActions = $showForm ? '' : '<a href="?new=1" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i>เพิ่มประกาศ</a>';
require APP_ROOT . '/includes/header.php';
?>
<?php if ($showForm): ?>
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-header"><i class="bi bi-pencil-square me-2"></i><?= $editing ? 'แก้ไขประกาศ' : 'เพิ่มประกาศ' ?></div>
        <div class="card-body">
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="mb-3">
                    <label class="form-label" for="title">หัวข้อ *</label>
                    <input class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" name="title" id="title" maxlength="255" value="<?= e($fv('title')) ?>">
                    <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?= e($errors['title']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="content">เนื้อหา *</label>
                    <textarea class="form-control<?= isset($errors['content']) ? ' is-invalid' : '' ?>" name="content" id="content" rows="8" maxlength="20000"><?= e($fv('content')) ?></textarea>
                    <?php if (isset($errors['content'])): ?><div class="invalid-feedback"><?= e($errors['content']) ?></div><?php endif; ?>
                </div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="published_at">วันเวลาเผยแพร่</label>
                        <input type="datetime-local" class="form-control" name="published_at" id="published_at" value="<?= e($fv('published_at', date('Y-m-d\TH:i'))) ?>">
                        <div class="form-text">ตั้งเวลาล่วงหน้าได้ ประกาศจะแสดงเมื่อถึงเวลา</div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="is_pinned" id="is_pinned" value="1" <?= $fChk('is_pinned', 0) ?>><label class="form-check-label" for="is_pinned">ปักหมุด (ประกาศสำคัญ)</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="is_published" id="is_published" value="1" <?= $fChk('is_published', 1) ?>><label class="form-check-label" for="is_published">เผยแพร่</label></div>
                    </div>
                    <div class="col-md-4 d-flex gap-2 justify-content-end">
                        <a href="<?= e(url('admin/announcements.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                        <button class="btn btn-accent px-4"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>หัวข้อ</th><th>เผยแพร่</th><th>ผู้ประกาศ</th><th>อ่าน</th><th>สถานะ</th><th class="no-sort text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $scheduled = strtotime($r['published_at']) > time(); ?>
                    <tr>
                        <td><?= $r['is_pinned'] ? '<i class="bi bi-pin-angle-fill text-accent me-1"></i>' : '' ?><?= e($r['title']) ?><div class="small text-muted"><?= e(str_limit($r['content'], 90)) ?></div></td>
                        <td class="small text-nowrap" data-order="<?= e($r['published_at']) ?>"><?= e(thai_datetime($r['published_at'])) ?></td>
                        <td class="small"><?= e($r['author'] ?? '-') ?></td>
                        <td><?= number_format((int)$r['views']) ?></td>
                        <td><?= !$r['is_published'] ? '<span class="badge bg-secondary">ซ่อน</span>' : ($scheduled ? '<span class="badge bg-info text-dark">ตั้งเวลา</span>' : '<span class="badge bg-success">เผยแพร่</span>') ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url('public/announcements.php?id=' . (int)$r['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="ดูหน้าเว็บ"><i class="bi bi-box-arrow-up-right"></i></a>
                            <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="เผยแพร่/ซ่อน"><i class="bi <?= $r['is_published'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                            </form>
                            <form method="post" class="d-inline" data-confirm="ลบประกาศ &quot;<?= e($r['title']) ?>&quot;?" data-confirm-icon="warning">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
