<?php
/**
 * ไฟล์: admin/academic_years.php
 * จัดการปีการศึกษา/ภาคเรียน และกำหนด "ภาคเรียนปัจจุบัน" (ใช้เป็นค่าเริ่มต้นของรายงาน/แดชบอร์ด/ฟอร์ม)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];

/** จำนวนข้อมูลที่อ้างอิงภาคเรียนนี้ */
function academic_year_usage(int $id): int
{
    return (int)db_value(
        'SELECT (SELECT COUNT(*) FROM submission_rounds WHERE academic_year_id = ?)
              + (SELECT COUNT(*) FROM supervision_schedules WHERE academic_year_id = ?)
              + (SELECT COUNT(*) FROM lesson_plans WHERE academic_year_id = ?)
              + (SELECT COUNT(*) FROM research_submissions WHERE academic_year_id = ?)
              + (SELECT COUNT(*) FROM advisor_groups WHERE academic_year_id = ?)',
        'iiiii',
        [$id, $id, $id, $id, $id]
    );
}

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $year = (int)post('year');
        $semester = (int)post('semester');
        $start = post('start_date');
        $end = post('end_date');
        $current = isset($_POST['is_current']) ? 1 : 0;
        if ($year < 2500 || $year > 2700) {
            $errors['year'] = 'ปีการศึกษาเป็น พ.ศ. เช่น ' . thai_year();
        }
        if (!in_array($semester, [1, 2, 3], true)) {
            $errors['semester'] = 'ภาคเรียนไม่ถูกต้อง';
        }
        if (!$errors && db_value('SELECT id FROM academic_years WHERE year = ? AND semester = ? AND id <> ?', 'iii', [$year, $semester, $id])) {
            $errors['year'] = 'ภาคเรียน ' . $semester . '/' . $year . ' มีแล้ว';
        }
        $startOk = $start === '' || DateTime::createFromFormat('Y-m-d', $start);
        $endOk = $end === '' || DateTime::createFromFormat('Y-m-d', $end);
        if (!$startOk || !$endOk) {
            $errors['start_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';
        } elseif ($start !== '' && $end !== '' && $end <= $start) {
            $errors['start_date'] = 'วันสิ้นสุดต้องหลังวันเริ่ม';
        }
        if (!$errors) {
            db()->begin_transaction();
            if ($current) {
                db_exec('UPDATE academic_years SET is_current = 0');
            }
            $s = $start ?: null;
            $e = $end ?: null;
            if ($id) {
                db_exec('UPDATE academic_years SET year = ?, semester = ?, start_date = ?, end_date = ?, is_current = ? WHERE id = ?',
                    'iissii', [$year, $semester, $s, $e, $current, $id]);
            } else {
                db_insert('INSERT INTO academic_years (year, semester, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?)',
                    'iissi', [$year, $semester, $s, $e, $current]);
            }
            db()->commit();
            log_activity('academic_year_save', 'บันทึกภาคเรียน ' . $semester . '/' . $year . ($current ? ' (ปัจจุบัน)' : ''));
            flash('success', 'บันทึกภาคเรียน ' . $semester . '/' . $year . ' แล้ว');
            redirect('admin/academic_years.php');
        }
    }
    if ($action === 'set_current') {
        db()->begin_transaction();
        db_exec('UPDATE academic_years SET is_current = 0');
        db_exec('UPDATE academic_years SET is_current = 1 WHERE id = ?', 'i', [$id]);
        db()->commit();
        log_activity('academic_year_save', 'ตั้งภาคเรียนปัจจุบัน #' . $id);
        flash('success', 'ตั้งเป็นภาคเรียนปัจจุบันแล้ว');
        redirect('admin/academic_years.php');
    }
    if ($action === 'delete') {
        if (academic_year_usage($id) > 0) {
            flash('error', 'ลบไม่ได้ เพราะมีรอบการส่ง/งาน/ตารางนิเทศ/กลุ่มที่ปรึกษาอ้างอิงภาคเรียนนี้');
        } else {
            $ayRow = db_one('SELECT * FROM academic_years WHERE id = ?', 'i', [$id]);
            db_exec('DELETE FROM academic_years WHERE id = ?', 'i', [$id]);
            log_activity('academic_year_delete', 'ลบภาคเรียน #' . $id . ($ayRow ? ' ' . $ayRow['semester'] . '/' . $ayRow['year'] : ''));
            flash('success', 'ลบภาคเรียนแล้ว');
        }
        redirect('admin/academic_years.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM academic_years WHERE id = ?', 'i', [$editId]) : null;
$rows = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);
$semLabel = [1 => 'ภาคเรียนที่ 1', 2 => 'ภาคเรียนที่ 2', 3 => 'ภาคเรียนฤดูร้อน'];

$pageTitle = 'ปีการศึกษา/ภาคเรียน';
$pageIcon = 'bi-calendar3';
$breadcrumbs = ['ปีการศึกษา/ภาคเรียน' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขภาคเรียน' : 'เพิ่มภาคเรียน' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="year">ปีการศึกษา (พ.ศ.) *</label>
                            <input type="number" class="form-control<?= isset($errors['year']) ? ' is-invalid' : '' ?>" name="year" id="year" min="2500" max="2700" value="<?= e($fv('year', (string)thai_year())) ?>">
                            <?php if (isset($errors['year'])): ?><div class="invalid-feedback"><?= e($errors['year']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="semester">ภาคเรียน *</label>
                            <select class="form-select" name="semester" id="semester">
                                <?php foreach ($semLabel as $k => $v): ?><option value="<?= $k ?>" <?= (int)$fv('semester', '1') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="start_date">วันเปิดภาคเรียน</label>
                            <input type="date" class="form-control<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>" name="start_date" id="start_date" value="<?= e($fv('start_date')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="end_date">วันปิดภาคเรียน</label>
                            <input type="date" class="form-control" name="end_date" id="end_date" value="<?= e($fv('end_date')) ?>">
                        </div>
                        <?php if (isset($errors['start_date'])): ?><div class="col-12 text-danger small mt-1"><?= e($errors['start_date']) ?></div><?php endif; ?>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_current" id="is_current" value="1" <?= (is_post() ? isset($_POST['is_current']) : (int)($editing['is_current'] ?? 0) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_current">ตั้งเป็นภาคเรียนปัจจุบัน</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <?php if ($editing): ?><a href="<?= e(url('admin/academic_years.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>ภาคเรียนทั้งหมด</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>ภาคเรียน</th><th>ช่วงเวลา</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="<?= $r['is_current'] ? 'table-warning' : '' ?>">
                            <td class="fw-semibold"><?= e($r['semester'] . '/' . $r['year']) ?> <span class="text-muted small fw-normal"><?= e($semLabel[(int)$r['semester']] ?? '') ?></span></td>
                            <td class="small"><?= e(thai_date($r['start_date'])) ?> – <?= e(thai_date($r['end_date'])) ?></td>
                            <td><?= $r['is_current'] ? '<span class="badge bg-accent">ภาคเรียนปัจจุบัน</span>' : '' ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (!$r['is_current']): ?>
                                    <form method="post" class="d-inline" data-confirm="ตั้งภาคเรียน <?= e($r['semester'] . '/' . $r['year']) ?> เป็นภาคเรียนปัจจุบัน?">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="set_current"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-warning" title="ตั้งเป็นปัจจุบัน"><i class="bi bi-star"></i></button>
                                    </form>
                                <?php endif; ?>
                                <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <?php if (!$r['is_current'] && academic_year_usage((int)$r['id']) === 0): ?>
                                    <form method="post" class="d-inline" data-confirm="ลบภาคเรียน <?= e($r['semester'] . '/' . $r['year']) ?>?" data-confirm-icon="warning">
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
<?php require APP_ROOT . '/includes/footer.php'; ?>
