<?php
/**
 * ไฟล์: admin/downloads.php
 * จัดการเอกสารดาวน์โหลด/แบบฟอร์มต้นฉบับ (แบบฟอร์มแผนการสอน, แบบ PA, เค้าโครงวิจัย, แบบนิเทศ ฯลฯ)
 * ไฟล์เก็บใน uploads/downloads (เข้าตรงไม่ได้) และดาวน์โหลดผ่าน public/download.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];
$docExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');
    $old = $id ? db_one('SELECT * FROM downloads WHERE id = ?', 'i', [$id]) : null;

    if ($action === 'save') {
        $title = mb_substr(post('title'), 0, 255);
        $desc = mb_substr(post('description'), 0, 500);
        $cat = post('category');
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($title === '') {
            $errors['title'] = 'กรุณากรอกชื่อเอกสาร';
        }
        if (!isset(download_categories()[$cat])) {
            $errors['category'] = 'กรุณาเลือกหมวด';
        }
        $hasFile = !empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;
        if (!$old && !$hasFile) {
            $errors['file'] = 'กรุณาแนบไฟล์';
        }
        $up = null;
        if (!$errors && $hasFile) {
            $up = handle_upload($_FILES['file'], 'downloads', $docExt, max_upload_bytes());
            if (!$up['ok']) {
                $errors['file'] = $up['error'];
                $up = null;
            }
        }
        if (!$errors) {
            if ($old) {
                db_exec('UPDATE downloads SET title = ?, description = ?, category = ?, is_active = ? WHERE id = ?',
                    'sssii', [$title, $desc, $cat, $active, $id]);
                if ($up) {
                    db_exec('UPDATE downloads SET original_name = ?, stored_name = ?, mime_type = ?, file_size = ? WHERE id = ?',
                        'sssii', [$up['original_name'], $up['stored_name'], $up['mime'], $up['size'], $id]);
                    delete_upload('downloads', $old['stored_name']);
                }
            } else {
                db_insert(
                    'INSERT INTO downloads (title, description, category, original_name, stored_name, mime_type, file_size, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'ssssssiii',
                    [$title, $desc, $cat, $up['original_name'], $up['stored_name'], $up['mime'], $up['size'], $active, (int)$user['id']]
                );
            }
            log_activity('download_save', ($old ? 'แก้ไข' : 'เพิ่ม') . 'เอกสาร: ' . $title . ($up ? ' (ไฟล์ ' . $up['original_name'] . ')' : ''));
            flash('success', 'บันทึกเอกสาร "' . $title . '" แล้ว');
            redirect('admin/downloads.php');
        }
    }
    if ($action === 'toggle' && $old) {
        db_exec('UPDATE downloads SET is_active = 1 - is_active WHERE id = ?', 'i', [$id]);
        flash('success', 'เปลี่ยนสถานะการเผยแพร่แล้ว');
        redirect('admin/downloads.php');
    }
    if ($action === 'delete' && $old) {
        db_exec('DELETE FROM downloads WHERE id = ?', 'i', [$id]);
        delete_upload('downloads', $old['stored_name']);
        log_activity('download_delete', 'ลบเอกสาร: ' . $old['title']);
        flash('success', 'ลบเอกสารแล้ว');
        redirect('admin/downloads.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM downloads WHERE id = ?', 'i', [$editId]) : null;
$showForm = $editing || get('new') === '1' || $errors;
$rows = db_all('SELECT * FROM downloads ORDER BY category, title');
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);

$pageTitle = 'เอกสารดาวน์โหลด';
$pageIcon = 'bi-cloud-download';
$breadcrumbs = ['เอกสารดาวน์โหลด' => null];
$pageActions = $showForm ? '' : '<a href="?new=1" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i>เพิ่มเอกสาร</a>';
require APP_ROOT . '/includes/header.php';
?>
<?php if ($showForm): ?>
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-header"><i class="bi bi-pencil-square me-2"></i><?= $editing ? 'แก้ไขเอกสาร' : 'เพิ่มเอกสาร' ?></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="title">ชื่อเอกสาร *</label>
                        <input class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" name="title" id="title" maxlength="255" value="<?= e($fv('title')) ?>" placeholder="เช่น แบบฟอร์มแผนการจัดการเรียนรู้ ฐานสมรรถนะ">
                        <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?= e($errors['title']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="category">หมวด *</label>
                        <select class="form-select" name="category" id="category">
                            <?php foreach (download_categories() as $k => $v): ?><option value="<?= e($k) ?>" <?= $fv('category', 'lesson_plan') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">คำอธิบาย</label>
                        <input class="form-control" name="description" id="description" maxlength="500" value="<?= e($fv('description')) ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="file">ไฟล์<?= $editing ? ' (เลือกเฉพาะเมื่อต้องการเปลี่ยนไฟล์)' : ' *' ?></label>
                        <input type="file" class="form-control<?= isset($errors['file']) ? ' is-invalid' : '' ?>" name="file" id="file" accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, $docExt))) ?>">
                        <div class="form-text"><?= e(strtoupper(implode(', ', $docExt))) ?> ไม่เกิน <?= e(format_bytes(max_upload_bytes())) ?>
                            <?= $editing && $editing['original_name'] ? ' · ไฟล์ปัจจุบัน: ' . e($editing['original_name']) : '' ?></div>
                        <?php if (isset($errors['file'])): ?><div class="invalid-feedback d-block"><?= e($errors['file']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1"
                                <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>><label class="form-check-label" for="is_active">เผยแพร่ในหน้าดาวน์โหลด</label></div>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-3">
                    <a href="<?= e(url('admin/downloads.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button class="btn btn-accent px-4" data-loading-text="กำลังอัปโหลด..."><i class="bi bi-save me-1"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>เอกสาร</th><th>หมวด</th><th>ไฟล์</th><th>ดาวน์โหลด</th><th>สถานะ</th><th class="no-sort text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><i class="bi <?= e(file_icon($r['original_name'])) ?> me-1"></i><?= e($r['title']) ?><div class="small text-muted"><?= e((string)$r['description']) ?></div></td>
                        <td class="small"><?= e(download_categories()[$r['category']] ?? '') ?></td>
                        <td class="small"><?= e((string)$r['original_name']) ?><br><span class="text-muted"><?= e(format_bytes((int)$r['file_size'])) ?></span></td>
                        <td><?= number_format((int)$r['download_count']) ?></td>
                        <td><?= $r['is_active'] ? '<span class="badge bg-success">เผยแพร่</span>' : '<span class="badge bg-secondary">ซ่อน</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['stored_name'] && $r['is_active']): ?>
                                <a href="<?= e(url('public/download.php?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="ดาวน์โหลด"><i class="bi bi-download"></i></a>
                            <?php endif; ?>
                            <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="เผยแพร่/ซ่อน"><i class="bi <?= $r['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                            </form>
                            <form method="post" class="d-inline" data-confirm="ลบเอกสาร &quot;<?= e($r['title']) ?>&quot; และไฟล์?" data-confirm-icon="warning">
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
