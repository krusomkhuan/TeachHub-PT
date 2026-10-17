<?php
/**
 * ไฟล์: includes/partials/student_eval_page.php
 * การนิเทศการสอนโดยนักเรียน นักศึกษา — หน้ารวมสำหรับ admin (จัดการได้) และหัวหน้าแผนก (ดูผลแผนกตนเองอย่างเดียว)
 *
 * ตัวแปรที่ต้องเตรียม: $user = require_role('admin') หรือ require_role('supervisor')
 *
 * โหมด (admin):  (ไม่มี) รายการ · ?new=1 สร้าง · ?edit=id แก้ไข · ?view=id ผล + QR Code
 * โหมด (หัวหน้าแผนก): (ไม่มี) รายการครูในแผนก · ?view=id ผล (ไม่แสดง QR/ลิงก์)
 */
$isAdmin = $user['role'] === 'admin';
$deptId = (int)$user['department_id'];
$self = $isAdmin ? 'admin/student_eval.php' : 'supervisor/student_eval.php';
$activeMenu = $self;
$errors = [];

// =====================================================================
// POST (admin เท่านั้น)
// =====================================================================
if (is_post()) {
    csrf_verify();
    if (!$isAdmin) {
        abort_page(403, 'เฉพาะผู้ดูแลระบบเป็นผู้สร้าง/แก้ไขแบบประเมินโดยนักเรียน');
    }
    $action = post('action');
    $id = (int)post('id');
    $old = $id ? student_eval_fetch($id) : null;
    if ($id && !$old) {
        abort_page(404, 'ไม่พบรายการประเมิน');
    }

    if ($action === 'save') {
        $d = [
            'teacher_id'       => (int)post('teacher_id'),
            'academic_year_id' => (int)post('academic_year_id'),
            'subject_name'     => mb_substr(post('subject_name'), 0, 200),
            'level'            => post('level'),
            'grade_year'       => (int)post('grade_year'),
            'group_no'         => mb_substr(post('group_no'), 0, 20),
            'open_at'          => strtotime(str_replace('T', ' ', post('open_at'))),
            'close_at'         => strtotime(str_replace('T', ' ', post('close_at'))),
            'max_responses'    => max(0, min(999, (int)post('max_responses'))),
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
            'note'             => mb_substr(post('note'), 0, 500),
        ];
        $teacherOk = db_value(
            "SELECT id FROM users WHERE id = ? AND status = 'active' AND role IN ('teacher','supervisor')",
            'i',
            [$d['teacher_id']]
        );
        if (!$teacherOk) {
            $errors['teacher_id'] = 'กรุณาเลือกครูผู้สอน';
        }
        if (!db_value('SELECT id FROM academic_years WHERE id = ?', 'i', [$d['academic_year_id']])) {
            $errors['academic_year_id'] = 'กรุณาเลือกภาคเรียน';
        }
        // รายวิชาต้องเป็นวิชาที่แผนการจัดการเรียนรู้ "ผ่าน" แล้ว (แก้ไขรายการเดิมโดยไม่เปลี่ยนวิชาได้)
        $keepOld = $old && $old['subject_name'] === $d['subject_name'] && (int)$old['teacher_id'] === $d['teacher_id']
            && (int)$old['academic_year_id'] === $d['academic_year_id'];
        if ($d['subject_name'] === '') {
            $errors['subject_name'] = 'กรุณาเลือกรายวิชา';
        } elseif (!$keepOld && $teacherOk
            && !approved_plan_subject_ok($d['teacher_id'], $d['academic_year_id'], $d['subject_name'])) {
            $errors['subject_name'] = 'รายวิชาที่ประเมินต้องเป็นวิชาที่แผนการจัดการเรียนรู้ของครูผ่านแล้วในภาคเรียนนี้';
        }
        // กลุ่มนักเรียน: ระดับ ปวช. (ชั้นปี 1-3) / ปวส. (ชั้นปี 1-2)
        $levels = student_levels();
        if (!isset($levels[$d['level']])) {
            $errors['level'] = 'กรุณาเลือกระดับ ปวช. หรือ ปวส.';
        } elseif ($d['grade_year'] < 1 || $d['grade_year'] > $levels[$d['level']]) {
            $errors['level'] = 'ชั้นปีของ ' . $d['level'] . ' ต้องอยู่ระหว่าง 1-' . $levels[$d['level']];
        }
        if (!preg_match('/^[\p{Thai}A-Za-z0-9.\-\/ ]*$/u', $d['group_no'])) {
            $errors['level'] = 'กลุ่มใช้ได้เฉพาะตัวอักษร ตัวเลข จุด และขีด';
        }
        if (!$errors) {
            $d['classroom'] = student_group_label($d['level'], $d['grade_year'], trim($d['group_no']));
        }
        if (!$d['open_at'] || !$d['close_at']) {
            $errors['open_at'] = 'กรุณาระบุวันเวลาเปิด-ปิดการประเมิน';
        } elseif ($d['close_at'] <= $d['open_at']) {
            $errors['open_at'] = 'วันเวลาปิดต้องอยู่หลังวันเวลาเปิด';
        }
        if ($old && (int)$old['responses'] > 0 && (int)$old['teacher_id'] !== $d['teacher_id']) {
            $errors['teacher_id'] = 'มีนักเรียนตอบแล้ว ไม่สามารถเปลี่ยนครูผู้ถูกประเมินได้';
        }

        if (!$errors) {
            $subjectId = db_value("SELECT id FROM subjects WHERE CONCAT(code, ' ', name) = ? LIMIT 1", 's', [$d['subject_name']]);
            $params = [$d['academic_year_id'], $d['teacher_id'], $subjectId ? (int)$subjectId : null, $d['subject_name'],
                $d['level'], $d['grade_year'], trim($d['group_no']), $d['classroom'],
                date('Y-m-d H:i:s', $d['open_at']), date('Y-m-d H:i:s', $d['close_at']), $d['max_responses'], $d['is_active'], $d['note']];
            if ($old) {
                db_exec(
                    'UPDATE student_eval_sessions SET academic_year_id = ?, teacher_id = ?, subject_id = ?, subject_name = ?,
                            level = ?, grade_year = ?, group_no = ?, classroom = ?,
                            open_at = ?, close_at = ?, max_responses = ?, is_active = ?, note = ? WHERE id = ?',
                    'iiississssiisi',
                    array_merge($params, [$id])
                );
                log_activity('student_eval_update', 'แก้ไขแบบประเมินโดยนักเรียน #' . $id);
                flash('success', 'บันทึกการแก้ไขแล้ว');
            } else {
                $id = db_insert(
                    'INSERT INTO student_eval_sessions (academic_year_id, teacher_id, subject_id, subject_name, level, grade_year, group_no,
                                                        classroom, open_at, close_at, max_responses, is_active, note, token, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiississssiissi',
                    array_merge($params, [bin2hex(random_bytes(16)), (int)$user['id']])
                );
                log_activity('student_eval_create', 'สร้างแบบประเมินโดยนักเรียน #' . $id . ' ' . $d['subject_name'] . ' ' . $d['classroom']);
                flash('success', 'สร้างแบบประเมินแล้ว ส่ง QR Code ให้นักเรียนสแกนได้ทันที');
            }
            redirect($self . '?view=' . $id);
        }
    }

    if ($action === 'toggle') {
        db_exec('UPDATE student_eval_sessions SET is_active = 1 - is_active WHERE id = ?', 'i', [$id]);
        log_activity('student_eval_update', ($old['is_active'] ? 'ปิด' : 'เปิด') . 'แบบประเมินโดยนักเรียน #' . $id);
        flash('success', $old['is_active'] ? 'ปิดรับการประเมินแล้ว' : 'เปิดรับการประเมินแล้ว');
        redirect($self . '?view=' . $id);
    }

    if ($action === 'new_token') {
        // สร้างลิงก์ใหม่ กรณีลิงก์เดิมรั่วไหล (QR เดิมจะใช้ไม่ได้ทันที)
        db_exec('UPDATE student_eval_sessions SET token = ? WHERE id = ?', 'si', [bin2hex(random_bytes(16)), $id]);
        log_activity('student_eval_update', 'สร้างลิงก์ใหม่ แบบประเมินโดยนักเรียน #' . $id);
        flash('success', 'สร้างลิงก์/QR Code ใหม่แล้ว ลิงก์เดิมใช้ไม่ได้อีก');
        redirect($self . '?view=' . $id);
    }

    if ($action === 'delete') {
        db_exec('DELETE FROM student_eval_sessions WHERE id = ?', 'i', [$id]);
        log_activity('student_eval_delete', 'ลบแบบประเมินโดยนักเรียน #' . $id . ' (ผู้ตอบ ' . (int)$old['responses'] . ' คน)');
        flash('success', 'ลบแบบประเมินและคำตอบทั้งหมดแล้ว');
        redirect($self);
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

// =====================================================================
// ?view= ผลการประเมิน (+ QR สำหรับ admin)
// =====================================================================
if (get_int('view') > 0) {
    $se = student_eval_fetch(get_int('view'));
    if (!$se || !can_view_student_eval($user, $se)) {
        abort_page(404, 'ไม่พบรายการประเมิน');
    }
    $sum = student_eval_summary([(int)$se['id']]);
    $showQr = can_see_student_eval_qr($user, $se);
    $useQr = $showQr;

    $pageTitle = 'ผลการประเมินการสอนโดยนักเรียน';
    $pageIcon = 'bi-people';
    $breadcrumbs = ['นิเทศโดยนักเรียน' => $self, $se['teacher_name'] => null];
    $pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์ผล</button>';
    if ($isAdmin) {
        $pageActions = '<a href="' . e(url($self . '?edit=' . (int)$se['id'])) . '" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>แก้ไข</a>' . $pageActions;
    }
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-<?= $showQr ? '7' : '12' ?>">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center"><i class="bi bi-info-circle me-2"></i>ข้อมูลการประเมิน
                    <span class="ms-auto"><?= student_eval_state_badge($se) ?></span></div>
                <div class="card-body small">
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-muted">ครูผู้ถูกประเมิน</div><div class="fw-semibold fs-6"><?= e($se['teacher_name']) ?></div><?= e($se['department_name'] ?? '-') ?></div>
                        <div class="col-md-6"><div class="text-muted">รายวิชา / ห้อง</div><div class="fw-semibold"><?= e($se['subject_name']) ?></div><?= e($se['classroom'] ?: '-') ?></div>
                        <div class="col-md-6"><div class="text-muted">ช่วงเวลาประเมิน</div><?= e(thai_datetime($se['open_at'])) ?> –<br><?= e(thai_datetime($se['close_at'])) ?></div>
                        <div class="col-md-3"><div class="text-muted">ภาคเรียน</div><?= $se['ay_year'] ? e($se['ay_semester'] . '/' . $se['ay_year']) : '-' ?></div>
                        <div class="col-md-3"><div class="text-muted">ผู้ตอบ</div><span class="fs-5 fw-bold"><?= (int)$se['responses'] ?></span><?= (int)$se['max_responses'] > 0 ? ' / ' . (int)$se['max_responses'] : '' ?> คน</div>
                        <?php if ($se['note']): ?><div class="col-12"><div class="text-muted">หมายเหตุ</div><?= e($se['note']) ?></div><?php endif; ?>
                    </div>
                    <?php if ($isAdmin): ?>
                        <hr>
                        <div class="d-flex flex-wrap gap-2 no-print">
                            <form method="post" data-confirm="<?= $se['is_active'] ? 'ปิดรับการประเมินนี้?' : 'เปิดรับการประเมินนี้?' ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$se['id'] ?>">
                                <button class="btn btn-sm <?= $se['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"><i class="bi <?= $se['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?> me-1"></i><?= $se['is_active'] ? 'ปิดรับ' : 'เปิดรับ' ?></button>
                            </form>
                            <form method="post" data-confirm="สร้างลิงก์/QR Code ใหม่? QR เดิมที่แจกไปแล้วจะใช้ไม่ได้">
                                <?= csrf_field() ?><input type="hidden" name="action" value="new_token"><input type="hidden" name="id" value="<?= (int)$se['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1"></i>สร้างลิงก์ใหม่</button>
                            </form>
                            <form method="post" data-confirm="ลบแบบประเมินนี้และคำตอบของนักเรียนทั้งหมด <?= (int)$se['responses'] ?> คน? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$se['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>ลบ</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($showQr): ?>
            <div class="col-lg-5 no-print"><?php require APP_ROOT . '/includes/partials/student_eval_qr_card.php'; ?></div>
        <?php endif; ?>
    </div>
    <?php
    require APP_ROOT . '/includes/partials/student_eval_result.php';
    echo '<a href="' . e(url($self)) . '" class="btn btn-outline-secondary no-print"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ?new=1 / ?edit= ฟอร์ม (admin)
// =====================================================================
if ($isAdmin && (get('new') === '1' || get_int('edit') > 0 || post('action') === 'save')) {
    $editId = get_int('edit') ?: (int)post('id');
    $editing = $editId ? student_eval_fetch($editId) : null;
    if ($editId && !$editing) {
        abort_page(404, 'ไม่พบรายการประเมิน');
    }
    $teachers = db_all(
        "SELECT u.id, u.prefix, u.first_name, u.last_name, d.name AS department_name FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.status = 'active' AND u.role IN ('teacher','supervisor') ORDER BY d.name, u.first_name"
    );
    $years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
    $current = current_academic_year();
    $fv = function (string $k, string $d = '') use ($editing) {
        if (is_post()) {
            return post($k);
        }
        if ($editing) {
            $v = (string)($editing[$k] ?? '');
            return in_array($k, ['open_at', 'close_at'], true) ? date('Y-m-d\TH:i', strtotime($v)) : $v;
        }
        return $d;
    };

    $pageTitle = $editing ? 'แก้ไขแบบประเมินโดยนักเรียน' : 'สร้างแบบประเมินโดยนักเรียน';
    $pageIcon = 'bi-qr-code';
    $breadcrumbs = ['นิเทศโดยนักเรียน' => $self, $pageTitle => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
        สร้าง 1 รายการต่อ ครู 1 คน + วิชา/ห้องเรียน 1 ชุด (วิชาที่ประเมินต้องเป็นวิชาที่แผนการจัดการเรียนรู้ผ่านแล้ว) ระบบจะออก QR Code ให้นักเรียนสแกนประเมินได้โดยไม่ต้องเป็นสมาชิก
        ครูเห็นเฉพาะ QR Code และผลของตนเอง · หัวข้อประเมินใช้ชุด “นักเรียนประเมิน” ที่เมนูหัวข้อแบบประเมิน</div>
    <div class="card">
        <div class="card-body">
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="teacher_id">ครูผู้ถูกประเมิน *</label>
                        <select class="form-select<?= $invalid('teacher_id') ?>" name="teacher_id" id="teacher_id">
                            <option value="">-- เลือกครู --</option>
                            <?php $g = null; foreach ($teachers as $t):
                                if ($g !== $t['department_name']) { if ($g !== null) echo '</optgroup>'; $g = $t['department_name']; echo '<optgroup label="' . e($g ?? 'ไม่ระบุแผนก') . '">'; } ?>
                                <option value="<?= (int)$t['id'] ?>" <?= (int)$fv('teacher_id') === (int)$t['id'] ? 'selected' : '' ?>><?= e(full_name($t)) ?></option>
                            <?php endforeach; if ($g !== null) echo '</optgroup>'; ?>
                        </select><?= $err('teacher_id') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="academic_year_id">ภาคเรียน *</label>
                        <select class="form-select<?= $invalid('academic_year_id') ?>" name="academic_year_id" id="academic_year_id">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y['id'] ?>" <?= (int)$fv('academic_year_id', (string)($current['id'] ?? '')) === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('academic_year_id') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="subject_name">รายวิชา *</label>
                        <select class="form-select<?= $invalid('subject_name') ?>" name="subject_name" id="subject_name" data-value="<?= e($fv('subject_name')) ?>">
                            <option value="">-- เลือกครูและภาคเรียนก่อน --</option>
                        </select>
                        <div class="form-text" id="subjectHint">แสดงเฉพาะรายวิชาที่แผนการจัดการเรียนรู้ของครู “ผ่าน” แล้วในภาคเรียนที่เลือก</div>
                        <?= $err('subject_name') ?>
                    </div>
                    <!-- กลุ่มนักเรียนผู้ประเมิน: ระดับ + ชั้นปี + กลุ่ม -->
                    <div class="col-md-4">
                        <label class="form-label" for="level">ระดับ *</label>
                        <select class="form-select<?= $invalid('level') ?>" name="level" id="level">
                            <option value="">-- เลือกระดับ --</option>
                            <?php foreach (student_levels() as $lv => $maxYear): ?>
                                <option value="<?= e($lv) ?>" data-years="<?= $maxYear ?>" <?= $fv('level') === $lv ? 'selected' : '' ?>><?= e($lv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="grade_year">ชั้นปี *</label>
                        <select class="form-select<?= $invalid('level') ?>" name="grade_year" id="grade_year" data-value="<?= e($fv('grade_year')) ?>"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="group_no">กลุ่ม/ห้อง</label>
                        <input class="form-control<?= $invalid('level') ?>" name="group_no" id="group_no" maxlength="20" value="<?= e($fv('group_no')) ?>" placeholder="เช่น 1, 2 หรือ ชฟ.1">
                    </div>
                    <div class="col-12 mt-1 small">
                        <?= $err('level') ?>
                        <span class="text-muted">กลุ่มนักเรียนที่ประเมิน: </span><strong id="groupPreview">-</strong>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="open_at">เปิดให้ประเมิน *</label>
                        <input type="datetime-local" class="form-control<?= $invalid('open_at') ?>" name="open_at" id="open_at" value="<?= e($fv('open_at', date('Y-m-d\TH:i'))) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="close_at">ปิดการประเมิน *</label>
                        <input type="datetime-local" class="form-control<?= $invalid('open_at') ?>" name="close_at" id="close_at" value="<?= e($fv('close_at', date('Y-m-d\T16:30', strtotime('+7 days')))) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="max_responses">จำนวนผู้ตอบสูงสุด</label>
                        <input type="number" class="form-control" name="max_responses" id="max_responses" min="0" max="999" value="<?= e($fv('max_responses', '0')) ?>">
                        <div class="form-text">0 = ไม่จำกัด (แนะนำใส่จำนวนนักเรียนในห้อง)</div>
                    </div>
                    <?php if (isset($errors['open_at'])): ?><div class="col-12 mt-0"><?= $err('open_at') ?></div><?php endif; ?>
                    <div class="col-md-8">
                        <label class="form-label" for="note">หมายเหตุ</label>
                        <input class="form-control" name="note" id="note" maxlength="500" value="<?= e($fv('note')) ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1"
                                <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <a href="<?= e(url($editing ? $self . '?view=' . (int)$editing['id'] : $self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button type="submit" class="btn btn-accent px-4"><i class="bi bi-save me-1"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    // ชั้นปีเปลี่ยนตามระดับ (ปวช. 1-3, ปวส. 1-2) + แสดงชื่อกลุ่มตัวอย่าง
    $pageScripts = '(function () {
        var level = document.getElementById("level"), year = document.getElementById("grade_year"),
            group = document.getElementById("group_no"), preview = document.getElementById("groupPreview");
        function fillYears() {
            var opt = level.options[level.selectedIndex], max = parseInt((opt && opt.dataset.years) || "0", 10);
            var cur = year.value || year.dataset.value;
            year.innerHTML = "";
            if (!max) { year.add(new Option("-- เลือกระดับก่อน --", "")); }
            for (var i = 1; i <= max; i++) { year.add(new Option("ปี " + i, String(i), false, String(i) === cur)); }
            showPreview();
        }
        function showPreview() {
            if (!level.value || !year.value) { preview.textContent = "-"; return; }
            var g = group.value.trim();
            preview.textContent = level.value + " " + year.value + (g ? "/" + g : "");
        }
        level.addEventListener("change", fillYears);
        year.addEventListener("change", showPreview);
        group.addEventListener("input", showPreview);
        fillYears();
    })();' . approved_plan_subject_js(approved_plan_subject_map());
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ
// =====================================================================
$fAy = get_int('ay', (int)(current_academic_year()['id'] ?? 0));
$fDept = $isAdmin ? get_int('dept') : $deptId;
$fLevel = isset(student_levels()[get('level')]) ? get('level') : '';
$rows = db_all(
    "SELECT s.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, d.name AS department_name,
            (SELECT COUNT(*) FROM student_eval_responses r WHERE r.session_id = s.id) AS responses,
            (SELECT ROUND(AVG(r.avg_score), 2) FROM student_eval_responses r WHERE r.session_id = s.id) AS avg_score
     FROM student_eval_sessions s
     JOIN users u ON u.id = s.teacher_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE (? = 0 OR s.academic_year_id = ?) AND (? = 0 OR u.department_id = ?) AND (? = 1 OR u.department_id = ?)
       AND (? = '' OR s.level = ?)
     ORDER BY s.close_at DESC",
    'iiiiiiss',
    [$fAy, $fAy, $fDept, $fDept, $isAdmin ? 1 : 0, $deptId, $fLevel, $fLevel]
);
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$departments = $isAdmin ? db_all('SELECT id, name FROM departments ORDER BY name') : [];
$totalResp = array_sum(array_column($rows, 'responses'));
$openCount = count(array_filter($rows, fn($r) => student_eval_state($r) === 'open'));
$overall = student_eval_summary(array_column($rows, 'id'), false);

$pageTitle = 'นิเทศการสอนโดยนักเรียน นักศึกษา';
$pageIcon = 'bi-people';
$breadcrumbs = ['นิเทศโดยนักเรียน' => null];
$pageActions = $isAdmin
    ? '<a href="' . e(url('admin/supervision_criteria.php?audience=student')) . '" class="btn btn-outline-primary"><i class="bi bi-list-check me-1"></i>หัวข้อประเมิน</a>'
      . '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-qr-code me-1"></i>สร้างแบบประเมิน</a>'
    : '';
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$isAdmin): ?>
    <div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>
        แสดงผลการประเมินของครูใน<?= e($user['department_name'] ?? 'แผนก') ?> (ผู้ดูแลระบบเป็นผู้สร้างแบบประเมินและ QR Code)</div>
<?php elseif (site_url_is_local()): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
        ระบบกำลังใช้ที่อยู่ <strong>localhost</strong> — QR Code จะเปิดบนโทรศัพท์นักเรียนไม่ได้
        <a href="<?= e(url('admin/settings.php')) ?>">ตั้งค่า URL หลักของระบบ</a></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-qr-code"></i></div><div><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">แบบประเมิน</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-door-open"></i></div><div><div class="stat-value"><?= $openCount ?></div><div class="stat-label">เปิดรับอยู่</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-orange"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= number_format($totalResp) ?></div><div class="stat-label">นักเรียนตอบแล้ว (คน)</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-star"></i></div><div><div class="stat-value"><?= $overall['avg'] !== null ? number_format($overall['avg'], 2) : '-' ?></div><div class="stat-label">คะแนนเฉลี่ยรวม</div><div class="stat-sub"><?= $overall['avg'] !== null ? e(quality_level($overall['avg'])) : '' ?></div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <option value="0">ทุกภาคเรียน</option>
                    <?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= $fAy === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="level">ระดับ</label>
                <select name="level" id="level" class="form-select">
                    <option value="">ทุกระดับ</option>
                    <?php foreach (array_keys(student_levels()) as $lv): ?><option value="<?= e($lv) ?>" <?= $fLevel === $lv ? 'selected' : '' ?>><?= e($lv) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($isAdmin): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>ครูผู้ถูกประเมิน</th><th>วิชา / ห้อง</th><th>ช่วงเวลา</th><th>สถานะ</th><th>ผู้ตอบ</th><th>คะแนนเฉลี่ย</th><th class="no-sort text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['teacher_name']) ?><br><small class="text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></small></td>
                        <td class="small"><?= e($r['subject_name']) ?><br><span class="text-muted"><?= e($r['classroom']) ?></span></td>
                        <td class="small text-nowrap" data-order="<?= e($r['close_at']) ?>"><?= e(thai_datetime($r['open_at'])) ?><br>ถึง <?= e(thai_datetime($r['close_at'])) ?></td>
                        <td><?= student_eval_state_badge($r) ?></td>
                        <td><?= (int)$r['responses'] ?><?= (int)$r['max_responses'] > 0 ? '/' . (int)$r['max_responses'] : '' ?></td>
                        <td data-order="<?= e((string)$r['avg_score']) ?>"><?= $r['avg_score'] !== null ? quality_badge((float)$r['avg_score']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url($self . '?view=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> <?= $isAdmin ? 'ผล/QR' : 'ดูผล' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
