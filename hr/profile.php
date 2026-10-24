<?php
/**
 * ไฟล์: hr/profile.php
 * ทะเบียนประวัติบุคลากร — ข้อมูลส่วนบุคคล ข้อมูลการปฏิบัติงาน วุฒิการศึกษา ประวัติการรับราชการ/ดำรงตำแหน่ง
 *   (ไม่มี)   ทะเบียนประวัติของฉัน     ?id=xx  ของบุคลากรคนอื่น (ผู้ดูแลงานบุคลากร / ผู้บริหาร)
 *   ?edit=1   แก้ไขข้อมูล (เจ้าของ + ผู้ดูแลงานบุคลากร)
 * ผู้บริหารเห็นเฉพาะข้อมูลการปฏิบัติงาน — ข้อมูลส่วนบุคคลเห็นเฉพาะเจ้าของและผู้ดูแลงานบุคลากร (PDPA)
 * เจ้าของแก้ไขข้อมูล = สถานะ "ตรวจสอบแล้ว" ถูกล้าง รอผู้ดูแลงานบุคลากรตรวจสอบใหม่
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$viewerId = (int)$user['id'];
$uid = get_int('id') ?: (int)post('user_id') ?: $viewerId;
$self = 'hr/profile.php';
$isOwn = $uid === $viewerId;

$person = hr_person($uid);
if (!$person || !hr_can_view_profile($user, $uid)) {
    abort_page(404, 'ไม่พบทะเบียนประวัติ', 'ไม่พบบุคลากรนี้ หรือท่านไม่มีสิทธิ์ดูข้อมูล');
}
$canEdit = hr_can_edit_profile($user, $uid);
$showPersonal = hr_can_view_personal($user, $uid);
$isHr = can_manage_hr($user);
$back = $self . ($isOwn ? '' : '?id=' . $uid);
$errors = [];

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    if (!$canEdit) {
        abort_page(403, 'ไม่มีสิทธิ์แก้ไขทะเบียนประวัตินี้');
    }
    $action = post('action');
    $who = $isOwn ? 'ของตนเอง' : 'ของ ' . $person['full_name'];
    // เจ้าของแก้ไขข้อมูล → ต้องตรวจสอบใหม่
    $touch = function () use ($uid, $isOwn, $viewerId): void {
        db_exec('INSERT IGNORE INTO hr_profiles (user_id) VALUES (?)', 'i', [$uid]);
        db_exec('UPDATE hr_profiles SET updated_by = ?, updated_at = NOW()' . ($isOwn ? ', verified_by = NULL, verified_at = NULL' : '') . ' WHERE user_id = ?',
            'ii', [$viewerId, $uid]);
    };

    if ($action === 'save') {
        $d = [
            'staff_type'         => post('staff_type'),
            'citizen_id'         => preg_replace('/\D/', '', post('citizen_id')),
            'birth_date'         => post('birth_date'),
            'gender'             => post('gender'),
            'blood_type'         => post('blood_type'),
            'religion'           => mb_substr(post('religion'), 0, 50),
            'nationality'        => mb_substr(post('nationality'), 0, 50),
            'address'            => mb_substr(post('address'), 0, 500),
            'emergency_name'     => mb_substr(post('emergency_name'), 0, 150),
            'emergency_relation' => mb_substr(post('emergency_relation'), 0, 50),
            'emergency_phone'    => mb_substr(post('emergency_phone'), 0, 20),
            'position_no'        => mb_substr(post('position_no'), 0, 30),
            'service_start_date' => post('service_start_date'),
            'college_start_date' => post('college_start_date'),
            'standing_date'      => post('standing_date'),
            'duties'             => mb_substr(post('duties'), 0, 3000),
        ];
        if (!in_array($d['staff_type'], hr_staff_types(), true)) {
            $errors['staff_type'] = 'กรุณาเลือกประเภทบุคลากร';
        }
        if ($d['citizen_id'] !== '' && !hr_valid_citizen($d['citizen_id'])) {
            $errors['citizen_id'] = 'เลขประจำตัวประชาชนไม่ถูกต้อง';
        }
        foreach (['birth_date' => 'วันเกิด', 'service_start_date' => 'วันที่บรรจุ', 'college_start_date' => 'วันที่เริ่มงาน', 'standing_date' => 'วันที่ได้วิทยฐานะ'] as $k => $label) {
            if ($d[$k] !== '' && (!hr_valid_date($d[$k]) || $d[$k] > date('Y-m-d'))) {
                $errors[$k] = $label . 'ไม่ถูกต้อง';
            }
        }
        if (!in_array($d['gender'], ['', 'ชาย', 'หญิง', 'ไม่ระบุ'], true)) {
            $d['gender'] = '';
        }
        if (!in_array($d['blood_type'], ['', 'A', 'B', 'AB', 'O'], true)) {
            $d['blood_type'] = '';
        }
        if ($d['emergency_phone'] !== '' && !valid_phone($d['emergency_phone'])) {
            $errors['emergency_phone'] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
        }
        if (!$errors) {
            foreach (['citizen_id', 'birth_date', 'service_start_date', 'college_start_date', 'standing_date'] as $k) {
                $d[$k] = $d[$k] === '' ? null : $d[$k];
            }
            $touch();
            db_exec(
                'UPDATE hr_profiles SET staff_type = ?, citizen_id = ?, birth_date = ?, gender = ?, blood_type = ?, religion = ?, nationality = ?,
                        address = ?, emergency_name = ?, emergency_relation = ?, emergency_phone = ?, position_no = ?,
                        service_start_date = ?, college_start_date = ?, standing_date = ?, duties = ?
                 WHERE user_id = ?',
                'ssssssssssssssssi',
                array_merge(array_values($d), [$uid])
            );
            log_activity('hr_profile_update', 'แก้ไขทะเบียนประวัติ' . $who);
            flash('success', 'บันทึกทะเบียนประวัติเรียบร้อยแล้ว' . ($isOwn ? ' — รองานบุคลากรตรวจสอบ' : ''));
            redirect($back);
        }
        $_GET['edit'] = '1';
    } elseif ($action === 'add_edu') {
        $level = post('level');
        $year = (int)post('grad_year');
        if (!in_array($level, hr_education_levels(), true)) {
            flash('error', 'กรุณาเลือกระดับการศึกษา');
        } elseif ($year !== 0 && ($year < 2450 || $year > thai_year())) {
            flash('error', 'ปีที่สำเร็จการศึกษาเป็นปี พ.ศ. เช่น 2550');
        } else {
            $touch();
            db_insert(
                'INSERT INTO hr_educations (user_id, level, degree, major, institution, grad_year) VALUES (?, ?, ?, ?, ?, ?)',
                'issssi',
                [$uid, $level, mb_substr(post('degree'), 0, 150), mb_substr(post('major'), 0, 150), mb_substr(post('institution'), 0, 200), $year ?: null]
            );
            log_activity('hr_profile_update', 'เพิ่มวุฒิการศึกษา' . $who . ': ' . $level . ' ' . post('degree'));
            flash('success', 'เพิ่มวุฒิการศึกษาแล้ว');
        }
        redirect($back . ($isOwn ? '?' : '&') . 'edit=1#edu');
    } elseif ($action === 'del_edu') {
        $touch();
        db_exec('DELETE FROM hr_educations WHERE id = ? AND user_id = ?', 'ii', [(int)post('row_id'), $uid]);
        log_activity('hr_profile_update', 'ลบวุฒิการศึกษา' . $who);
        flash('success', 'ลบวุฒิการศึกษาแล้ว');
        redirect($back . ($isOwn ? '?' : '&') . 'edit=1#edu');
    } elseif ($action === 'add_service') {
        $date = post('record_date');
        $pos = mb_substr(post('position'), 0, 150);
        if (!hr_valid_date($date) || $pos === '') {
            flash('error', 'กรุณาระบุวันที่มีผลและตำแหน่ง');
        } else {
            $touch();
            db_insert(
                'INSERT INTO hr_service_records (user_id, record_date, position, standing, unit, order_ref, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
                'issssss',
                [$uid, $date, $pos, mb_substr(post('standing'), 0, 100), mb_substr(post('unit'), 0, 200),
                    mb_substr(post('order_ref'), 0, 150), mb_substr(post('note'), 0, 300)]
            );
            log_activity('hr_profile_update', 'เพิ่มประวัติการรับราชการ' . $who . ': ' . $pos);
            flash('success', 'เพิ่มประวัติการรับราชการ/ดำรงตำแหน่งแล้ว');
        }
        redirect($back . ($isOwn ? '?' : '&') . 'edit=1#service');
    } elseif ($action === 'del_service') {
        $touch();
        db_exec('DELETE FROM hr_service_records WHERE id = ? AND user_id = ?', 'ii', [(int)post('row_id'), $uid]);
        log_activity('hr_profile_update', 'ลบประวัติการรับราชการ' . $who);
        flash('success', 'ลบรายการแล้ว');
        redirect($back . ($isOwn ? '?' : '&') . 'edit=1#service');
    } elseif ($action === 'verify' && $isHr && !$isOwn) {
        db_exec('INSERT IGNORE INTO hr_profiles (user_id) VALUES (?)', 'i', [$uid]);
        db_exec('UPDATE hr_profiles SET verified_by = ?, verified_at = NOW() WHERE user_id = ?', 'ii', [$viewerId, $uid]);
        log_activity('hr_profile_verify', 'ตรวจสอบทะเบียนประวัติของ ' . $person['full_name']);
        flash('success', 'บันทึกว่าตรวจสอบทะเบียนประวัติของ ' . $person['full_name'] . ' แล้ว');
        redirect($back);
    } else {
        abort_page(400, 'คำขอไม่ถูกต้อง');
    }
}

// =====================================================================
// ข้อมูลสำหรับแสดงผล
// =====================================================================
$p = hr_profile($uid) ?? [];
$edus = db_all('SELECT * FROM hr_educations WHERE user_id = ? ORDER BY grad_year DESC, id DESC', 'i', [$uid]);
$services = db_all('SELECT * FROM hr_service_records WHERE user_id = ? ORDER BY record_date DESC, id DESC', 'i', [$uid]);
$fy = current_fiscal_year();
$used = hr_leave_used($uid, $fy);
$trainHours = (float)db_value("SELECT COALESCE(SUM(hours), 0) FROM hr_trainings WHERE user_id = ? AND fiscal_year = ? AND status = 'approved'", 'ii', [$uid, $fy]);
$verifier = !empty($p['verified_by']) ? db_one("SELECT CONCAT(prefix, first_name, ' ', last_name) AS n FROM users WHERE id = ?", 'i', [(int)$p['verified_by']]) : null;
$editing = $canEdit && get('edit') === '1';
if (!$isOwn && !$editing && !is_post()) {
    log_activity('hr_profile_view', 'ดูทะเบียนประวัติของ ' . $person['full_name']);
}
$v = fn(string $k) => isset($errors) && $errors && is_post() ? post($k) : (string)($p[$k] ?? '');
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$dash = fn($x) => ($x === null || $x === '') ? '-' : e($x);

$pageTitle = $isOwn ? 'ทะเบียนประวัติของฉัน' : 'ทะเบียนประวัติ: ' . $person['full_name'];
$pageIcon = 'bi-person-badge';
$activeMenu = $isOwn ? $self : 'hr/staff.php';
$breadcrumbs = $isOwn ? ['ทะเบียนประวัติของฉัน' => null] : ['ทะเบียนประวัติบุคลากร' => 'hr/staff.php', $person['full_name'] => null];
$pageActions = '';
if ($canEdit && !$editing) {
    $pageActions .= '<a href="' . e(url($back . ($isOwn ? '?' : '&') . 'edit=1')) . '" class="btn btn-accent"><i class="bi bi-pencil-square me-1"></i> แก้ไขข้อมูล</a>';
}
if ($editing) {
    $pageActions .= '<a href="' . e(url($back)) . '" class="btn btn-outline-secondary"><i class="bi bi-check2 me-1"></i> เสร็จสิ้น</a>';
}
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-body text-center">
                <img src="<?= e(avatar_url($person)) ?>" alt="" class="rounded-circle mb-3" style="width:96px;height:96px;object-fit:cover">
                <h2 class="h5 mb-1"><?= e($person['full_name']) ?></h2>
                <div class="text-muted"><?= e($person['position'] ?: '-') ?><?= $person['academic_standing'] ? ' · ' . e($person['academic_standing']) : '' ?></div>
                <div class="small text-muted"><?= e($person['department_name'] ?: 'ไม่สังกัดแผนกวิชา') ?></div>
                <div class="mt-2">
                    <?php if (!$p): ?>
                        <span class="badge bg-secondary">ยังไม่กรอกทะเบียนประวัติ</span>
                    <?php elseif (!empty($p['verified_at'])): ?>
                        <span class="badge bg-success"><i class="bi bi-patch-check me-1"></i>งานบุคลากรตรวจสอบแล้ว</span>
                        <div class="small text-muted mt-1"><?= e(thai_date($p['verified_at'])) ?><?= $verifier ? ' โดย ' . e($verifier['n']) : '' ?></div>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">รองานบุคลากรตรวจสอบ</span>
                    <?php endif; ?>
                </div>
                <?php if ($isHr && !$isOwn && $p && empty($p['verified_at'])): ?>
                    <form method="post" class="mt-3" data-confirm="ยืนยันว่าตรวจสอบข้อมูลทะเบียนประวัติของ <?= e($person['full_name']) ?> แล้ว?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="user_id" value="<?= $uid ?>">
                        <button class="btn btn-success btn-sm"><i class="bi bi-patch-check me-1"></i> ยืนยันตรวจสอบแล้ว</button>
                    </form>
                <?php endif; ?>
            </div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex"><span class="text-muted">อีเมล</span><span class="ms-auto"><?= e($person['email']) ?></span></li>
                <li class="list-group-item d-flex"><span class="text-muted">โทรศัพท์</span><span class="ms-auto"><?= $dash($person['phone']) ?></span></li>
            </ul>
            <?php if ($isOwn): ?>
                <div class="card-footer small text-muted">ชื่อ ตำแหน่ง วิทยฐานะ แผนก อีเมล โทรศัพท์ แก้ไขที่ <a href="<?= e(url('auth/profile.php')) ?>">โปรไฟล์ของฉัน</a></div>
            <?php endif; ?>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>สรุปปีงบประมาณ <?= $fy ?></div>
            <ul class="list-group list-group-flush small">
                <?php foreach (['sick', 'personal', 'vacation'] as $t): ?>
                    <li class="list-group-item d-flex"><span><?= e(hr_leave_type_label($t)) ?></span>
                        <span class="ms-auto"><?= $used[$t]['times'] ?> ครั้ง · <?= hr_days($used[$t]['days']) ?> วัน
                            <?= $t === 'vacation' ? '<span class="text-muted">(สิทธิ์ ' . hr_days(hr_vacation_quota($uid, $fy)) . ' วัน)</span>' : '' ?></span></li>
                <?php endforeach; ?>
                <li class="list-group-item d-flex"><span>ชั่วโมงพัฒนาตนเอง (ตรวจแล้ว)</span><span class="ms-auto"><?= hr_days($trainHours) ?> ชั่วโมง</span></li>
            </ul>
        </div>
    </div>

    <div class="col-xl-8">
        <?php if ($editing): ?>
            <form method="post" novalidate class="card mb-4">
                <div class="card-header"><i class="bi bi-pencil-square me-2"></i>แก้ไขทะเบียนประวัติ</div>
                <div class="card-body">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="user_id" value="<?= $uid ?>">
                    <?php if ($isOwn): ?>
                        <div class="alert alert-light border small"><i class="bi bi-shield-lock me-1"></i>
                            ข้อมูลส่วนบุคคลเห็นเฉพาะท่านและงานบุคลากร ใช้เพื่อการบริหารงานบุคคลของวิทยาลัยเท่านั้น — เมื่อบันทึก งานบุคลากรจะตรวจสอบข้อมูลอีกครั้ง</div>
                    <?php endif; ?>
                    <h3 class="h6 text-primary">ข้อมูลการปฏิบัติงาน</h3>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label" for="staff_type">ประเภทบุคลากร <span class="text-danger">*</span></label>
                            <select class="form-select<?= $inv('staff_type') ?>" name="staff_type" id="staff_type" required>
                                <option value="">-- เลือก --</option>
                                <?php foreach (hr_staff_types() as $t): ?><option <?= $v('staff_type') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                            </select><?= $err('staff_type') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="position_no">เลขที่ตำแหน่ง</label>
                            <input class="form-control" name="position_no" id="position_no" maxlength="30" value="<?= e($v('position_no')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="service_start_date">วันที่บรรจุ/เริ่มรับราชการ</label>
                            <input type="date" class="form-control<?= $inv('service_start_date') ?>" name="service_start_date" id="service_start_date" value="<?= e($v('service_start_date')) ?>"><?= $err('service_start_date') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="college_start_date">วันที่เริ่มปฏิบัติงานที่วิทยาลัย</label>
                            <input type="date" class="form-control<?= $inv('college_start_date') ?>" name="college_start_date" id="college_start_date" value="<?= e($v('college_start_date')) ?>"><?= $err('college_start_date') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="standing_date">วันที่ได้รับวิทยฐานะปัจจุบัน</label>
                            <input type="date" class="form-control<?= $inv('standing_date') ?>" name="standing_date" id="standing_date" value="<?= e($v('standing_date')) ?>"><?= $err('standing_date') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="duties">งาน/หน้าที่ที่ได้รับมอบหมาย</label>
                            <textarea class="form-control" name="duties" id="duties" rows="3" maxlength="3000" placeholder="เช่น ครูที่ปรึกษา ปวช.1/1, เจ้าหน้าที่งานทะเบียน"><?= e($v('duties')) ?></textarea>
                        </div>
                    </div>
                    <h3 class="h6 text-primary">ข้อมูลส่วนบุคคล</h3>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="citizen_id">เลขประจำตัวประชาชน</label>
                            <input class="form-control<?= $inv('citizen_id') ?>" name="citizen_id" id="citizen_id" inputmode="numeric" maxlength="17" autocomplete="off" value="<?= e($v('citizen_id')) ?>"><?= $err('citizen_id') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="birth_date">วันเกิด</label>
                            <input type="date" class="form-control<?= $inv('birth_date') ?>" name="birth_date" id="birth_date" value="<?= e($v('birth_date')) ?>"><?= $err('birth_date') ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="gender">เพศ</label>
                            <select class="form-select" name="gender" id="gender">
                                <?php foreach (['', 'ชาย', 'หญิง', 'ไม่ระบุ'] as $g): ?><option value="<?= e($g) ?>" <?= $v('gender') === $g ? 'selected' : '' ?>><?= $g === '' ? '-' : e($g) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="blood_type">หมู่เลือด</label>
                            <select class="form-select" name="blood_type" id="blood_type">
                                <?php foreach (['', 'A', 'B', 'AB', 'O'] as $g): ?><option value="<?= e($g) ?>" <?= $v('blood_type') === $g ? 'selected' : '' ?>><?= $g === '' ? '-' : e($g) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="religion">ศาสนา</label>
                            <input class="form-control" name="religion" id="religion" maxlength="50" value="<?= e($v('religion')) ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="nationality">สัญชาติ</label>
                            <input class="form-control" name="nationality" id="nationality" maxlength="50" value="<?= e($v('nationality')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address">ที่อยู่ปัจจุบัน</label>
                            <textarea class="form-control" name="address" id="address" rows="2" maxlength="500"><?= e($v('address')) ?></textarea>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="emergency_name">ผู้ติดต่อกรณีฉุกเฉิน</label>
                            <input class="form-control" name="emergency_name" id="emergency_name" maxlength="150" value="<?= e($v('emergency_name')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="emergency_relation">เกี่ยวข้องเป็น</label>
                            <input class="form-control" name="emergency_relation" id="emergency_relation" maxlength="50" value="<?= e($v('emergency_relation')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="emergency_phone">โทรศัพท์</label>
                            <input class="form-control<?= $inv('emergency_phone') ?>" name="emergency_phone" id="emergency_phone" maxlength="20" value="<?= e($v('emergency_phone')) ?>"><?= $err('emergency_phone') ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button class="btn btn-accent" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-briefcase me-2"></i>ข้อมูลการปฏิบัติงาน</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4">ประเภทบุคลากร</dt><dd class="col-sm-8"><?= $dash($p['staff_type'] ?? '') ?></dd>
                        <dt class="col-sm-4">เลขที่ตำแหน่ง</dt><dd class="col-sm-8"><?= $dash($p['position_no'] ?? '') ?></dd>
                        <dt class="col-sm-4">วันที่บรรจุ/เริ่มรับราชการ</dt>
                        <dd class="col-sm-8"><?= e(thai_date($p['service_start_date'] ?? null, false, true)) ?>
                            <?= !empty($p['service_start_date']) ? '<span class="text-muted">(อายุราชการ ' . e(hr_service_length($p['service_start_date'])) . ')</span>' : '' ?></dd>
                        <dt class="col-sm-4">เริ่มปฏิบัติงานที่วิทยาลัย</dt>
                        <dd class="col-sm-8"><?= e(thai_date($p['college_start_date'] ?? null, false, true)) ?>
                            <?= !empty($p['college_start_date']) ? '<span class="text-muted">(' . e(hr_service_length($p['college_start_date'])) . ')</span>' : '' ?></dd>
                        <dt class="col-sm-4">วันที่ได้รับวิทยฐานะปัจจุบัน</dt><dd class="col-sm-8"><?= e(thai_date($p['standing_date'] ?? null, false, true)) ?></dd>
                        <dt class="col-sm-4">งาน/หน้าที่ที่ได้รับมอบหมาย</dt><dd class="col-sm-8" style="white-space:pre-line"><?= $dash($p['duties'] ?? '') ?></dd>
                    </dl>
                </div>
            </div>
            <?php if ($showPersonal): ?>
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-shield-lock me-2"></i>ข้อมูลส่วนบุคคล <span class="small text-muted">(เห็นเฉพาะเจ้าของและงานบุคลากร)</span></div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">เลขประจำตัวประชาชน</dt>
                            <dd class="col-sm-8"><?= $isOwn || $isHr ? $dash($p['citizen_id'] ?? '') : e(hr_mask_citizen($p['citizen_id'] ?? '')) ?></dd>
                            <dt class="col-sm-4">วันเกิด</dt>
                            <dd class="col-sm-8"><?= e(thai_date($p['birth_date'] ?? null, false, true)) ?>
                                <?= !empty($p['birth_date']) ? '<span class="text-muted">(อายุ ' . e(hr_service_length($p['birth_date'])) . ')</span>' : '' ?></dd>
                            <dt class="col-sm-4">เพศ / หมู่เลือด</dt><dd class="col-sm-8"><?= $dash($p['gender'] ?? '') ?> / <?= $dash($p['blood_type'] ?? '') ?></dd>
                            <dt class="col-sm-4">ศาสนา / สัญชาติ</dt><dd class="col-sm-8"><?= $dash($p['religion'] ?? '') ?> / <?= $dash($p['nationality'] ?? '') ?></dd>
                            <dt class="col-sm-4">ที่อยู่ปัจจุบัน</dt><dd class="col-sm-8"><?= $dash($p['address'] ?? '') ?></dd>
                            <dt class="col-sm-4">ผู้ติดต่อกรณีฉุกเฉิน</dt>
                            <dd class="col-sm-8"><?= $dash($p['emergency_name'] ?? '') ?><?= !empty($p['emergency_relation']) ? ' (' . e($p['emergency_relation']) . ')' : '' ?>
                                <?= !empty($p['emergency_phone']) ? ' · ' . e($p['emergency_phone']) : '' ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card mb-4" id="edu">
            <div class="card-header"><i class="bi bi-mortarboard me-2"></i>วุฒิการศึกษา</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>ระดับ</th><th>วุฒิ/ปริญญา</th><th>สาขาวิชา</th><th>สถานศึกษา</th><th>ปีที่สำเร็จ</th><?php if ($editing): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($edus as $r): ?>
                            <tr>
                                <td><?= e($r['level']) ?></td><td><?= $dash($r['degree']) ?></td><td><?= $dash($r['major']) ?></td>
                                <td><?= $dash($r['institution']) ?></td><td><?= $r['grad_year'] ? (int)$r['grad_year'] : '-' ?></td>
                                <?php if ($editing): ?>
                                    <td class="text-end">
                                        <form method="post" data-confirm="ลบวุฒิการศึกษานี้?" data-confirm-icon="warning">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="del_edu"><input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$edus): ?><tr><td colspan="6" class="text-muted text-center">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($editing): ?>
                    <form method="post" class="row g-2 align-items-end border-top pt-3 mt-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_edu"><input type="hidden" name="user_id" value="<?= $uid ?>">
                        <div class="col-md-3"><label class="form-label small mb-1">ระดับ</label>
                            <select class="form-select form-select-sm" name="level" required><option value="">-- เลือก --</option>
                                <?php foreach (hr_education_levels() as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small mb-1">วุฒิ เช่น ค.อ.บ.</label><input class="form-control form-control-sm" name="degree" maxlength="150"></div>
                        <div class="col-md-2"><label class="form-label small mb-1">สาขาวิชา</label><input class="form-control form-control-sm" name="major" maxlength="150"></div>
                        <div class="col-md-3"><label class="form-label small mb-1">สถานศึกษา</label><input class="form-control form-control-sm" name="institution" maxlength="200"></div>
                        <div class="col-md-1"><label class="form-label small mb-1">ปี พ.ศ.</label><input class="form-control form-control-sm" name="grad_year" inputmode="numeric" maxlength="4"></div>
                        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100" title="เพิ่ม"><i class="bi bi-plus-lg"></i></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4" id="service">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>ประวัติการรับราชการ / การดำรงตำแหน่ง</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>วันที่มีผล</th><th>ตำแหน่ง</th><th>วิทยฐานะ/ระดับ</th><th>สังกัด</th><th>เอกสารอ้างอิง</th><?php if ($editing): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($services as $r): ?>
                            <tr>
                                <td class="text-nowrap"><?= e(thai_date($r['record_date'])) ?></td><td><?= e($r['position']) ?></td>
                                <td><?= $dash($r['standing']) ?></td><td><?= $dash($r['unit']) ?></td>
                                <td class="small"><?= $dash($r['order_ref']) ?><?= $r['note'] !== '' ? '<div class="text-muted">' . e($r['note']) . '</div>' : '' ?></td>
                                <?php if ($editing): ?>
                                    <td class="text-end">
                                        <form method="post" data-confirm="ลบรายการนี้?" data-confirm-icon="warning">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="del_service"><input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$services): ?><tr><td colspan="6" class="text-muted text-center">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($editing): ?>
                    <form method="post" class="row g-2 align-items-end border-top pt-3 mt-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_service"><input type="hidden" name="user_id" value="<?= $uid ?>">
                        <div class="col-md-2"><label class="form-label small mb-1">วันที่มีผล</label><input type="date" class="form-control form-control-sm" name="record_date" required></div>
                        <div class="col-md-3"><label class="form-label small mb-1">ตำแหน่ง</label><input class="form-control form-control-sm" name="position" maxlength="150" required></div>
                        <div class="col-md-2"><label class="form-label small mb-1">วิทยฐานะ/ระดับ</label><input class="form-control form-control-sm" name="standing" maxlength="100"></div>
                        <div class="col-md-2"><label class="form-label small mb-1">สังกัด</label><input class="form-control form-control-sm" name="unit" maxlength="200"></div>
                        <div class="col-md-2"><label class="form-label small mb-1">คำสั่ง/อ้างอิง</label><input class="form-control form-control-sm" name="order_ref" maxlength="150"></div>
                        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100" title="เพิ่ม"><i class="bi bi-plus-lg"></i></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
