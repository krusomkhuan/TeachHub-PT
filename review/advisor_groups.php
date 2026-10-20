<?php
/**
 * ไฟล์: review/advisor_groups.php
 * งานครูที่ปรึกษาและแนะแนว: กำหนดครูที่ปรึกษา/กลุ่มเรียนรายภาคเรียน, วันพุธที่งดพบ และกำหนดส่งรายงาน
 *   ?ay=      ภาคเรียน     ?edit=id  แก้ไขกลุ่ม
 * สิทธิ์: admin และ หัวหน้างาน/เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว (ตามตำแหน่ง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_advisor($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลระบบ และ ' . implode(' / ', advisor_staff_positions()));
}
$self = 'review/advisor_groups.php';
$activeMenu = $self;
$errors = [];
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ay = advisor_academic_year(get_int('ay') ?: (int)post('ay'));
$ayId = (int)($ay['id'] ?? 0);
$back = $self . '?ay=' . $ayId;

if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- เพิ่ม/แก้ไขกลุ่มที่ปรึกษา ----------
    if ($action === 'save_group') {
        $id = (int)post('id');
        $old = $id ? db_one('SELECT * FROM advisor_groups WHERE id = ?', 'i', [$id]) : null;
        if ($id && !$old) {
            abort_page(404, 'ไม่พบกลุ่มที่ปรึกษา');
        }
        $teacherId = (int)post('teacher_id');
        $deptId = (int)post('department_id');
        $name = mb_substr(preg_replace('/\s+/u', ' ', post('group_name')), 0, 100);
        $count = post('student_count');
        $teacher = db_one("SELECT id FROM users WHERE id = ? AND status = 'active' AND role IN ('teacher','supervisor')", 'i', [$teacherId]);
        if (!$teacher) {
            $errors['teacher_id'] = 'กรุณาเลือกครูที่ปรึกษา';
        }
        if ($name === '') {
            $errors['group_name'] = 'กรุณาระบุชื่อกลุ่มเรียน';
        }
        if (!ctype_digit($count) || (int)$count < 1 || (int)$count > 200) {
            $errors['student_count'] = 'จำนวนนักเรียน 1-200 คน';
        }
        if ($deptId > 0 && !db_value('SELECT 1 FROM departments WHERE id = ?', 'i', [$deptId])) {
            $errors['department_id'] = 'แผนกไม่ถูกต้อง';
        }
        if ($old && (int)$old['teacher_id'] !== $teacherId && db_value('SELECT 1 FROM advisor_reports WHERE group_id = ? LIMIT 1', 'i', [$id])) {
            $errors['teacher_id'] = 'กลุ่มนี้มีรายงานแล้ว เปลี่ยนครูที่ปรึกษาไม่ได้ (ให้เพิ่มกลุ่มใหม่สำหรับครูอีกท่าน)';
        }
        $ayForGroup = $old ? (int)$old['academic_year_id'] : $ayId;
        if (!$errors && db_value(
            'SELECT 1 FROM advisor_groups WHERE academic_year_id = ? AND teacher_id = ? AND group_name = ? AND id <> ?',
            'iisi',
            [$ayForGroup, $teacherId, $name, $id]
        )) {
            $errors['group_name'] = 'ครูท่านนี้มีกลุ่มชื่อนี้ในภาคเรียนนี้แล้ว';
        }
        if (!$errors) {
            if ($old) {
                db_exec(
                    'UPDATE advisor_groups SET teacher_id = ?, department_id = ?, group_name = ?, student_count = ? WHERE id = ?',
                    'iisii',
                    [$teacherId, $deptId ?: null, $name, (int)$count, $id]
                );
            } else {
                db_insert(
                    'INSERT INTO advisor_groups (academic_year_id, teacher_id, department_id, group_name, student_count, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                    'iiisii',
                    [$ayId, $teacherId, $deptId ?: null, $name, (int)$count, (int)$user['id']]
                );
            }
            log_activity('advisor_group_save', ($old ? 'แก้ไข' : 'เพิ่ม') . 'กลุ่มที่ปรึกษา ' . $name . ' ภาคเรียน ' . academic_label($ay));
            flash('success', 'บันทึกกลุ่มที่ปรึกษา ' . $name . ' แล้ว');
            redirect($self . '?ay=' . $ayForGroup);
        }
        if ($old) {
            $_GET['edit'] = (string)$id;
        }
    }

    // ---------- ลบกลุ่ม (ยังไม่มีรายงาน) ----------
    if ($action === 'delete_group') {
        $g = db_one('SELECT * FROM advisor_groups WHERE id = ?', 'i', [(int)post('id')]);
        if (!$g) {
            abort_page(404, 'ไม่พบกลุ่มที่ปรึกษา');
        }
        if (db_value('SELECT 1 FROM advisor_reports WHERE group_id = ? LIMIT 1', 'i', [(int)$g['id']])) {
            flash('error', 'ลบไม่ได้ เพราะกลุ่มนี้มีรายงานการพบนักเรียนแล้ว');
        } else {
            db_exec('DELETE FROM advisor_groups WHERE id = ?', 'i', [(int)$g['id']]);
            log_activity('advisor_group_delete', 'ลบกลุ่มที่ปรึกษา ' . $g['group_name']);
            flash('success', 'ลบกลุ่ม ' . $g['group_name'] . ' แล้ว');
        }
        redirect($self . '?ay=' . (int)$g['academic_year_id']);
    }

    // ---------- คัดลอกกลุ่มจากภาคเรียนอื่น ----------
    if ($action === 'copy_groups') {
        $fromAy = (int)post('from_ay');
        if ($fromAy === $ayId || !$fromAy) {
            flash('error', 'กรุณาเลือกภาคเรียนต้นทางที่ไม่ใช่ภาคเรียนปัจจุบัน');
            redirect($back);
        }
        $n = db_exec(
            "INSERT IGNORE INTO advisor_groups (academic_year_id, teacher_id, department_id, group_name, student_count, created_by)
             SELECT ?, g.teacher_id, g.department_id, g.group_name, g.student_count, ?
             FROM advisor_groups g JOIN users u ON u.id = g.teacher_id
             WHERE g.academic_year_id = ? AND u.status = 'active'",
            'iii',
            [$ayId, (int)$user['id'], $fromAy]
        );
        log_activity('advisor_group_save', 'คัดลอกกลุ่มที่ปรึกษา ' . $n . ' กลุ่ม มายังภาคเรียน ' . academic_label($ay));
        flash('success', 'คัดลอกกลุ่มที่ปรึกษามาแล้ว ' . $n . ' กลุ่ม (กลุ่มที่มีอยู่แล้วจะข้ามไป) — อย่าลืมปรับชื่อชั้นปีให้เป็นปัจจุบัน');
        redirect($back);
    }

    // ---------- วันงดพบ (เฉพาะหัวหน้างาน/เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว) ----------
    if (in_array($action, ['add_skip', 'delete_skip'], true) && !can_manage_advisor_skip($user)) {
        abort_page(403, 'ไม่มีสิทธิ์กำหนดวันงดพบ', 'วันงดพบกำหนดได้เฉพาะ ' . implode(' และ ', advisor_staff_positions()));
    }
    if ($action === 'add_skip') {
        $date = post('skip_date');
        $reason = mb_substr(post('reason'), 0, 200);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || (int)date('N', strtotime($date)) !== 3) {
            flash('error', 'วันงดพบต้องเป็นวันพุธ');
        } elseif (db_value('SELECT 1 FROM advisor_reports WHERE meet_date = ? LIMIT 1', 's', [$date])) {
            // ยังเพิ่มได้ รายงานที่ส่งแล้วยังอยู่ แต่สัปดาห์นี้จะไม่นับในสรุป
            db_exec('INSERT IGNORE INTO advisor_skip_dates (skip_date, reason, created_by) VALUES (?, ?, ?)', 'ssi', [$date, $reason, (int)$user['id']]);
            flash('warning', 'กำหนดวันงดพบแล้ว แต่มีครูส่งรายงานของวันนี้ไว้แล้ว — รายงานเหล่านั้นจะไม่ถูกนับในสรุป');
        } else {
            db_exec('INSERT IGNORE INTO advisor_skip_dates (skip_date, reason, created_by) VALUES (?, ?, ?)', 'ssi', [$date, $reason, (int)$user['id']]);
            flash('success', 'กำหนดวันงดพบ ' . thai_date($date) . ' แล้ว');
        }
        log_activity('advisor_skip_save', 'กำหนดวันงดพบ ' . $date . ' ' . $reason);
        redirect($back);
    }
    if ($action === 'delete_skip') {
        db_exec('DELETE FROM advisor_skip_dates WHERE id = ?', 'i', [(int)post('id')]);
        log_activity('advisor_skip_delete', 'ยกเลิกวันงดพบ #' . (int)post('id'));
        flash('success', 'ยกเลิกวันงดพบแล้ว');
        redirect($back);
    }

    // ---------- กำหนดส่ง ----------
    if ($action === 'save_setting') {
        $days = post('advisor_late_days');
        if (!ctype_digit($days) || (int)$days > 6) {
            flash('error', 'จำนวนวันต้องเป็น 0-6');
        } else {
            setting_save('advisor_late_days', $days);
            log_activity('settings_update', 'กำหนดส่งรายงานครูที่ปรึกษา ภายใน ' . $days . ' วันหลังวันพุธ');
            flash('success', 'บันทึกกำหนดส่งแล้ว');
        }
        redirect($back);
    }
}

$editId = get_int('edit');
$editing = $editId ? db_one('SELECT * FROM advisor_groups WHERE id = ?', 'i', [$editId]) : null;
$fv = fn(string $k, string $d = '') => is_post() && post('action') === 'save_group' ? post($k) : (string)($editing[$k] ?? $d);
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

$groups = db_all(
    "SELECT g.*, u.prefix, u.first_name, u.last_name, u.status AS user_status, d.name AS department_name,
            (SELECT COUNT(*) FROM advisor_reports r WHERE r.group_id = g.id) AS report_count
     FROM advisor_groups g JOIN users u ON u.id = g.teacher_id LEFT JOIN departments d ON d.id = g.department_id
     WHERE g.academic_year_id = ? ORDER BY d.name, g.group_name",
    'i',
    [$ayId]
);
$teachers = db_all(
    "SELECT u.id, u.prefix, u.first_name, u.last_name, u.department_id, d.name AS department_name
     FROM users u LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.status = 'active' AND u.role IN ('teacher','supervisor') ORDER BY d.name, u.first_name"
);
$departments = db_all('SELECT id, name FROM departments ORDER BY name');
$skips = $ay && $ay['start_date'] ? db_all(
    'SELECT * FROM advisor_skip_dates WHERE skip_date BETWEEN ? AND ? ORDER BY skip_date',
    'ss',
    [$ay['start_date'], $ay['end_date']]
) : [];
$canSkip = can_manage_advisor_skip($user);
$wedOptions = array_filter(advisor_wednesdays($ay, false), fn($r) => $r === null);

$pageTitle = 'กลุ่มที่ปรึกษา / วันงดพบ';
$pageIcon = 'bi-diagram-3';
$breadcrumbs = ['งานครูที่ปรึกษา' => 'review/advisor_reports.php', 'กลุ่มที่ปรึกษา' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $ayId === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 small text-muted">
                <?php if ($ay && $ay['start_date']): ?>
                    เปิดภาคเรียน <?= e(thai_date($ay['start_date'])) ?> – <?= e(thai_date($ay['end_date'])) ?>
                    · วันพุธทั้งหมด <?= count(advisor_wednesdays($ay, false)) ?> วัน (งดพบ <?= count($skips) ?> วัน)
                <?php else: ?>
                    <span class="text-danger">ภาคเรียนนี้ยังไม่ได้กำหนดวันเปิด-ปิดภาคเรียน (ผู้ดูแลระบบกำหนดที่เมนู ปีการศึกษา/ภาคเรียน)</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center"><i class="bi bi-people me-2"></i>ครูที่ปรึกษาและกลุ่มเรียน ภาคเรียน <?= e(academic_label($ay)) ?>
                <span class="badge bg-light text-dark border ms-2"><?= count($groups) ?> กลุ่ม</span></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" data-order="[]">
                        <thead><tr><th>กลุ่มเรียน</th><th>ครูที่ปรึกษา</th><th>แผนก</th><th class="text-center">นักเรียน</th><th class="text-center">รายงาน</th><th class="no-sort"></th></tr></thead>
                        <tbody>
                        <?php foreach ($groups as $g): ?>
                            <tr class="<?= $editId === (int)$g['id'] ? 'table-warning' : '' ?>">
                                <td class="fw-semibold"><?= e($g['group_name']) ?></td>
                                <td><?= e(full_name($g)) ?><?= $g['user_status'] !== 'active' ? ' <span class="badge bg-secondary">ไม่ใช้งาน</span>' : '' ?></td>
                                <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)($g['department_name'] ?? '-'))) ?></td>
                                <td class="text-center"><?= (int)$g['student_count'] ?></td>
                                <td class="text-center"><a href="<?= e(url('reports/advisor_teacher.php?teacher=' . (int)$g['teacher_id'] . '&ay=' . $ayId)) ?>"><?= (int)$g['report_count'] ?></a></td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= e(url($self . '?ay=' . $ayId . '&edit=' . (int)$g['id'])) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int)$g['report_count'] === 0): ?>
                                        <form method="post" class="d-inline" data-confirm="ลบกลุ่ม <?= e($g['group_name']) ?>?" data-confirm-icon="warning">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="delete_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($years) > 1): ?>
                    <hr>
                    <form method="post" class="row g-2 align-items-end" data-confirm="คัดลอกกลุ่มที่ปรึกษาจากภาคเรียนที่เลือกมายังภาคเรียน <?= e(academic_label($ay)) ?>?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="copy_groups"><input type="hidden" name="ay" value="<?= $ayId ?>">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="from_ay">คัดลอกกลุ่มจากภาคเรียน</label>
                            <select name="from_ay" id="from_ay" class="form-select form-select-sm">
                                <?php foreach ($years as $y): if ((int)$y['id'] === $ayId) continue; ?>
                                    <option value="<?= (int)$y['id'] ?>"><?= e(academic_label($y)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-copy me-1"></i>คัดลอกมาภาคเรียนนี้</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-4 border-top border-4 border-warning">
            <div class="card-header"><i class="bi bi-<?= $editing ? 'pencil' : 'plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขกลุ่มที่ปรึกษา' : 'เพิ่มกลุ่มที่ปรึกษา' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_group">
                    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <input type="hidden" name="ay" value="<?= $ayId ?>">
                    <div class="mb-3">
                        <label class="form-label" for="teacher_id">ครูที่ปรึกษา *</label>
                        <select class="form-select<?= $invalid('teacher_id') ?>" name="teacher_id" id="teacher_id">
                            <option value="">- เลือกครู -</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" data-dept="<?= (int)$t['department_id'] ?>" <?= $fv('teacher_id') === (string)$t['id'] ? 'selected' : '' ?>>
                                    <?= e(full_name($t)) ?> (<?= e(preg_replace('/^แผนกวิชา/u', '', (string)($t['department_name'] ?? '-'))) ?>)</option>
                            <?php endforeach; ?>
                        </select><?= $err('teacher_id') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="group_name">กลุ่มเรียน *</label>
                        <input class="form-control<?= $invalid('group_name') ?>" name="group_name" id="group_name" maxlength="100"
                               value="<?= e($fv('group_name')) ?>" placeholder="เช่น ปวช.1/1 ช่างยนต์"><?= $err('group_name') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="department_id">แผนกวิชา</label>
                        <select class="form-select<?= $invalid('department_id') ?>" name="department_id" id="department_id">
                            <option value="0">- ไม่ระบุ -</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $fv('department_id') === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('department_id') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="student_count">จำนวนนักเรียน (คน) *</label>
                        <input type="number" class="form-control<?= $invalid('student_count') ?>" name="student_count" id="student_count" min="1" max="200"
                               value="<?= e($fv('student_count')) ?>"><?= $err('student_count') ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                        <?php if ($editing): ?><a href="<?= e(url($back)) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-calendar-x me-2"></i>วันพุธที่งดพบ (ไม่นับเป็นขาดส่ง)</div>
            <div class="card-body">
                <?php if ($skips): ?>
                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach ($skips as $s): ?>
                            <li class="list-group-item d-flex align-items-center gap-2 px-0">
                                <div><div class="fw-semibold"><?= e(advisor_date_label($s['skip_date'])) ?></div><div class="small text-muted"><?= e($s['reason'] ?: '-') ?></div></div>
                                <?php if ($canSkip): ?>
                                    <form method="post" class="ms-auto" data-confirm="ยกเลิกวันงดพบ <?= e(thai_date($s['skip_date'])) ?>? (สัปดาห์นี้จะกลับมานับว่าต้องส่ง)">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_skip"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="ay" value="<?= $ayId ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="ยกเลิก"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small text-muted">ยังไม่มีวันงดพบในภาคเรียนนี้</p>
                <?php endif; ?>
                <?php if (!$canSkip): ?>
                    <div class="small text-muted"><i class="bi bi-lock me-1"></i>วันงดพบกำหนดได้เฉพาะ<?= e(implode(' และ', advisor_staff_positions())) ?></div>
                <?php elseif ($wedOptions): ?>
                    <form method="post" class="row g-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_skip"><input type="hidden" name="ay" value="<?= $ayId ?>">
                        <div class="col-12">
                            <select name="skip_date" class="form-select form-select-sm" aria-label="วันพุธ">
                                <?php foreach (array_keys($wedOptions) as $d): ?>
                                    <option value="<?= e($d) ?>"><?= e(advisor_date_label($d)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-8"><input name="reason" class="form-control form-control-sm" maxlength="200" placeholder="เหตุผล เช่น สอบกลางภาค"></div>
                        <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus"></i> เพิ่ม</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-clock me-2"></i>กำหนดส่งรายงาน</div>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_setting"><input type="hidden" name="ay" value="<?= $ayId ?>">
                    <div class="col-7">
                        <label class="form-label small" for="advisor_late_days">ส่งได้ภายในกี่วันหลังวันพุธ</label>
                        <input type="number" class="form-control form-control-sm" name="advisor_late_days" id="advisor_late_days" min="0" max="6" value="<?= advisor_late_days() ?>">
                    </div>
                    <div class="col-5"><button class="btn btn-sm btn-primary w-100">บันทึก</button></div>
                    <div class="col-12 form-text">0 = ต้องส่งภายในวันพุธ, 2 = ภายในวันศุกร์ เลยกำหนดยังส่งได้แต่ติดป้าย “ส่งช้า”</div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
// เลือกครูแล้วตั้งแผนกตามแผนกของครูให้อัตโนมัติ (เฉพาะตอนเพิ่มใหม่)
if (!$editing) {
    $pageScripts = '(function () {
        var t = document.getElementById("teacher_id"), d = document.getElementById("department_id");
        t.addEventListener("change", function () { var o = t.options[t.selectedIndex]; if (o && o.dataset.dept && o.dataset.dept !== "0") d.value = o.dataset.dept; });
    })();';
}
require APP_ROOT . '/includes/footer.php';
