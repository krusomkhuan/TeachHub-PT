<?php
/**
 * ไฟล์: admin/subjects.php
 * จัดการรายวิชา (รหัส ชื่อ ท-ป-น ระดับชั้น แผนก) — ครูเลือกใช้ตอนส่งแผนการสอน/วิจัย/ตารางนิเทศ
 * ลบรายวิชาได้เสมอ เพราะงานที่ส่งแล้วเก็บรหัส/ชื่อวิชาไว้ในตัวงานเอง
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];
$departments = db_all('SELECT id, name FROM departments ORDER BY name');

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $d = [
            'code'          => strtoupper(post('code')),
            'name'          => mb_substr(post('name'), 0, 200),
            'credit'        => post('credit'),
            'level'         => post('level'),
            'department_id' => (int)post('department_id'),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        ];
        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $d['code'])) {
            $errors['code'] = 'รหัสวิชาไม่ถูกต้อง เช่น 20104-2101';
        } elseif (db_value('SELECT id FROM subjects WHERE code = ? AND id <> ?', 'si', [$d['code'], $id])) {
            $errors['code'] = 'รหัสวิชานี้มีแล้ว';
        }
        if ($d['name'] === '') {
            $errors['name'] = 'กรุณากรอกชื่อวิชา';
        }
        if ($d['credit'] !== '' && !preg_match('/^\d{1,2}-\d{1,2}-\d{1,2}$/', $d['credit'])) {
            $errors['credit'] = 'รูปแบบ ท-ป-น เช่น 1-3-2';
        }
        if (!in_array($d['level'], ['ปวช.', 'ปวส.'], true)) {
            $errors['level'] = 'กรุณาเลือกระดับชั้น';
        }
        if (!in_array($d['department_id'], array_map('intval', array_column($departments, 'id')), true)) {
            $errors['department_id'] = 'กรุณาเลือกแผนก';
        }
        if (!$errors) {
            if ($id) {
                db_exec('UPDATE subjects SET code = ?, name = ?, credit = ?, level = ?, department_id = ?, is_active = ? WHERE id = ?',
                    'ssssiii', [$d['code'], $d['name'], $d['credit'], $d['level'], $d['department_id'], $d['is_active'], $id]);
            } else {
                db_insert('INSERT INTO subjects (code, name, credit, level, department_id, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    'ssssii', [$d['code'], $d['name'], $d['credit'], $d['level'], $d['department_id'], $d['is_active']]);
            }
            log_activity('subject_save', ($id ? 'แก้ไข' : 'เพิ่ม') . 'รายวิชา ' . $d['code'] . ' ' . $d['name']);
            flash('success', 'บันทึกรายวิชา ' . $d['code'] . ' แล้ว');
            redirect('admin/subjects.php' . ($id ? '' : '?new=1'));
        }
    }
    if ($action === 'delete') {
        $s = db_one('SELECT code, name FROM subjects WHERE id = ?', 'i', [$id]);
        if ($s) {
            db_exec('DELETE FROM subjects WHERE id = ?', 'i', [$id]);
            log_activity('subject_delete', 'ลบรายวิชา ' . $s['code'] . ' ' . $s['name']);
            flash('success', 'ลบรายวิชา ' . $s['code'] . ' แล้ว');
        }
        redirect('admin/subjects.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM subjects WHERE id = ?', 'i', [$editId]) : null;
$fDept = get_int('dept');
$rows = db_all(
    'SELECT s.*, d.name AS department_name,
        (SELECT COUNT(*) FROM lesson_plans lp WHERE lp.subject_code = s.code) AS plans
     FROM subjects s LEFT JOIN departments d ON d.id = s.department_id
     WHERE (? = 0 OR s.department_id = ?) ORDER BY s.code',
    'ii',
    [$fDept, $fDept]
);
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

$pageTitle = 'รายวิชา';
$pageIcon = 'bi-book';
$breadcrumbs = ['รายวิชา' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขรายวิชา' : 'เพิ่มรายวิชา' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-7">
                            <label class="form-label" for="code">รหัสวิชา *</label>
                            <input class="form-control<?= $invalid('code') ?>" name="code" id="code" maxlength="20" value="<?= e($fv('code')) ?>" placeholder="20104-2101"><?= $err('code') ?>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="credit">ท-ป-น</label>
                            <input class="form-control<?= $invalid('credit') ?>" name="credit" id="credit" maxlength="20" value="<?= e($fv('credit')) ?>" placeholder="1-3-2"><?= $err('credit') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="name">ชื่อวิชา *</label>
                            <input class="form-control<?= $invalid('name') ?>" name="name" id="name" maxlength="200" value="<?= e($fv('name')) ?>"><?= $err('name') ?>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="level">ระดับชั้น *</label>
                            <select class="form-select<?= $invalid('level') ?>" name="level" id="level">
                                <?php foreach (['ปวช.', 'ปวส.'] as $lv): ?><option value="<?= e($lv) ?>" <?= $fv('level', 'ปวช.') === $lv ? 'selected' : '' ?>><?= e($lv) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-7">
                            <label class="form-label" for="department_id">แผนกวิชา *</label>
                            <select class="form-select<?= $invalid('department_id') ?>" name="department_id" id="department_id">
                                <option value="">-- เลือก --</option>
                                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$fv('department_id', (string)$fDept) === (int)$d['id'] ? 'selected' : '' ?>><?= e(preg_replace('/^แผนกวิชา/u', '', $d['name'])) ?></option><?php endforeach; ?>
                            </select><?= $err('department_id') ?>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <?php if ($editing): ?><a href="<?= e(url('admin/subjects.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span><i class="bi bi-list-ul me-2"></i>รายวิชา (<?= count($rows) ?>)</span>
                <form method="get" class="ms-auto">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="กรองแผนก">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" data-order="[]">
                        <thead><tr><th>รหัส</th><th>ชื่อวิชา</th><th>ท-ป-น</th><th>ระดับ</th><th>แผนก</th><th>แผนที่ส่ง</th><th class="no-sort text-end"></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
                                <td class="text-nowrap"><?= e($r['code']) ?></td>
                                <td><?= e($r['name']) ?><?= $r['is_active'] ? '' : ' <span class="badge bg-secondary">ปิด</span>' ?></td>
                                <td><?= e($r['credit']) ?></td>
                                <td><?= e($r['level']) ?></td>
                                <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td>
                                <td><?= (int)$r['plans'] ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <form method="post" class="d-inline" data-confirm="ลบรายวิชา <?= e($r['code']) ?>? (งานที่ส่งแล้วยังเก็บชื่อวิชาไว้)" data-confirm-icon="warning">
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
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
