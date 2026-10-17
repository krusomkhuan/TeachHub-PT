<?php
/**
 * ไฟล์: admin/departments.php
 * จัดการแผนกวิชา — เพิ่ม/แก้ไข/เปิด-ปิดใช้งาน/ลบ (ลบได้เฉพาะแผนกที่ไม่มีผู้ใช้และรายวิชา)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $code = strtoupper(post('code'));
        $name = mb_substr(post('name'), 0, 150);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            $errors['code'] = 'รหัสแผนก: ตัวอักษรอังกฤษ/ตัวเลข 2-20 ตัว';
        } elseif (db_value('SELECT id FROM departments WHERE code = ? AND id <> ?', 'si', [$code, $id])) {
            $errors['code'] = 'รหัสแผนกนี้มีแล้ว';
        }
        if ($name === '') {
            $errors['name'] = 'กรุณากรอกชื่อแผนก';
        } elseif (mb_strpos($name, 'แผนกวิชา') !== 0) {
            $name = 'แผนกวิชา' . $name; // ให้ชื่อเป็นรูปแบบเดียวกันทั้งระบบ
        }
        if (!$errors) {
            if ($id) {
                db_exec('UPDATE departments SET code = ?, name = ?, is_active = ? WHERE id = ?', 'ssii', [$code, $name, $active, $id]);
            } else {
                db_insert('INSERT INTO departments (code, name, is_active) VALUES (?, ?, ?)', 'ssi', [$code, $name, $active]);
            }
            log_activity('department_save', ($id ? 'แก้ไข' : 'เพิ่ม') . $name);
            flash('success', 'บันทึก' . $name . 'แล้ว');
            redirect('admin/departments.php');
        }
    }
    if ($action === 'delete') {
        $used = (int)db_value('SELECT (SELECT COUNT(*) FROM users WHERE department_id = ?) + (SELECT COUNT(*) FROM subjects WHERE department_id = ?)', 'ii', [$id, $id]);
        if ($used > 0) {
            flash('error', 'ลบไม่ได้ เพราะมีผู้ใช้หรือรายวิชาในแผนกนี้ (ใช้การปิดใช้งานแทน)');
        } else {
            db_exec('DELETE FROM departments WHERE id = ?', 'i', [$id]);
            log_activity('department_delete', 'ลบแผนก #' . $id);
            flash('success', 'ลบแผนกแล้ว');
        }
        redirect('admin/departments.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM departments WHERE id = ?', 'i', [$editId]) : null;
$rows = db_all(
    "SELECT d.*,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.status = 'active' AND u.role IN ('teacher','supervisor')) AS teachers,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'supervisor' AND u.status = 'active') AS heads,
        (SELECT COUNT(*) FROM subjects s WHERE s.department_id = d.id) AS subjects,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS all_users
     FROM departments d ORDER BY d.name"
);
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);

$pageTitle = 'แผนกวิชา';
$pageIcon = 'bi-diagram-3';
$breadcrumbs = ['แผนกวิชา' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขแผนก' : 'เพิ่มแผนกวิชา' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="code">รหัสแผนก *</label>
                        <input class="form-control<?= isset($errors['code']) ? ' is-invalid' : '' ?>" name="code" id="code" maxlength="20" value="<?= e($fv('code')) ?>" placeholder="เช่น MECH">
                        <?php if (isset($errors['code'])): ?><div class="invalid-feedback"><?= e($errors['code']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="name">ชื่อแผนกวิชา *</label>
                        <input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" name="name" id="name" maxlength="150" value="<?= e($fv('name')) ?>" placeholder="เช่น แผนกวิชาช่างกลโรงงาน">
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">เปิดใช้งาน (แสดงในหน้าสมัครสมาชิก)</label>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($editing): ?><a href="<?= e(url('admin/departments.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>แผนกวิชาทั้งหมด (<?= count($rows) ?>)</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" data-order="[]">
                        <thead><tr><th>รหัส</th><th>ชื่อแผนก</th><th>ครู</th><th>หัวหน้าแผนก</th><th>รายวิชา</th><th>สถานะ</th><th class="no-sort text-end"></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= e($r['code']) ?></td>
                                <td><?= e($r['name']) ?></td>
                                <td><a href="<?= e(url('admin/users.php?dept=' . (int)$r['id'])) ?>"><?= (int)$r['teachers'] ?></a></td>
                                <td><?= (int)$r['heads'] ?: '<span class="text-danger small">ยังไม่มี</span>' ?></td>
                                <td><?= (int)$r['subjects'] ?></td>
                                <td><?= $r['is_active'] ? '<span class="badge bg-success">ใช้งาน</span>' : '<span class="badge bg-secondary">ปิด</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int)$r['all_users'] === 0 && (int)$r['subjects'] === 0): ?>
                                        <form method="post" class="d-inline" data-confirm="ลบ <?= e($r['name']) ?>?" data-confirm-icon="warning">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
