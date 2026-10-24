<?php
/**
 * ไฟล์: hr/leaves.php
 * การลาของฉัน — ยื่นใบลาออนไลน์ ติดตามการพิจารณา พิมพ์ใบลา และสรุปวันลารายปีงบประมาณ
 *   (ไม่มี)  รายการใบลา   ?fy= ปีงบประมาณ
 *   ?new=1   ยื่นใบลา      ?id=xx ดูใบลา
 *   ?new=1&for=xx  ผู้ดูแลงานบุคลากรบันทึกใบลาแทนบุคลากร (ใบลากระดาษ/ย้อนหลัง — อนุญาตแล้ว)
 * ลำดับการพิจารณา: หัวหน้าแผนก → งานบุคลากร → รองผู้อำนวยการ → ผู้อำนวยการ (ดู includes/hr.php)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$self = 'hr/leaves.php';
$activeMenu = $self;
$isHr = can_manage_hr($user);
$errors = [];

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    if ($action === 'cancel') {
        $leave = hr_leave_fetch((int)post('id'));
        $own = $leave && (int)$leave['user_id'] === $uid;
        // เจ้าของยกเลิกได้ระหว่างรอพิจารณา / งานบุคลากรยกเลิกได้ทุกสถานะ (เช่น ยกเลิกวันลาที่อนุญาตแล้ว)
        if (!$leave || in_array($leave['status'], ['cancelled', 'rejected'], true)
            || !(($own && in_array($leave['status'], hr_leave_pending_statuses(), true)) || ($isHr && !$own))) {
            abort_page(403, 'ไม่สามารถยกเลิกใบลานี้ได้');
        }
        $reason = mb_substr(post('cancel_reason'), 0, 500);
        if (!$own && $reason === '') {
            flash('error', 'กรุณาระบุเหตุผลที่ยกเลิก');
            redirect('hr/leave_review.php?id=' . (int)$leave['id']);
        }
        db_exec("UPDATE hr_leaves SET status = 'cancelled' WHERE id = ?", 'i', [(int)$leave['id']]);
        hr_leave_log_action((int)$leave['id'], 'cancel', '', $reason);
        log_activity('hr_leave_cancel', 'ยกเลิกใบลา #' . (int)$leave['id'] . ' ' . hr_leave_type_label($leave['leave_type']) . ' ของ ' . $leave['user_name']);
        flash('success', 'ยกเลิกใบลาแล้ว');
        redirect($own ? $self . '?id=' . (int)$leave['id'] : 'hr/leave_review.php?id=' . (int)$leave['id']);
    }

    if ($action !== 'submit') {
        abort_page(400, 'คำขอไม่ถูกต้อง');
    }
    // ผู้ดูแลงานบุคลากรบันทึกแทนผู้อื่น
    $forId = (int)post('for');
    $recordFor = $forId > 0 && $forId !== $uid;
    if ($recordFor && !$isHr) {
        abort_page(403, 'ไม่มีสิทธิ์บันทึกใบลาแทนผู้อื่น');
    }
    $owner = hr_person($recordFor ? $forId : $uid);
    if (!$owner) {
        abort_page(404, 'ไม่พบบุคลากร');
    }
    $d = [
        'leave_type' => post('leave_type'),
        'start_date' => post('start_date'),
        'end_date'   => post('end_date') ?: post('start_date'),
        'half_day'   => post('half_day'),
        'reason'     => mb_substr(post('reason'), 0, 1000),
        'contact'    => mb_substr(post('contact'), 0, 300),
        'substitute' => mb_substr(post('substitute'), 0, 150),
    ];
    if (!isset(hr_leave_types()[$d['leave_type']])) {
        $errors['leave_type'] = 'กรุณาเลือกประเภทการลา';
    }
    if (!hr_valid_date($d['start_date'])) {
        $errors['start_date'] = 'กรุณาเลือกวันที่เริ่มลา';
    } elseif (!hr_valid_date($d['end_date']) || $d['end_date'] < $d['start_date']) {
        $errors['end_date'] = 'วันที่สิ้นสุดต้องไม่ก่อนวันเริ่มลา';
    } elseif ($d['start_date'] < date('Y-m-d', strtotime('-1 year')) || $d['end_date'] > date('Y-m-d', strtotime('+1 year'))) {
        $errors['start_date'] = 'วันที่ลาต้องอยู่ภายใน 1 ปีจากวันนี้';
    } elseif ((strtotime($d['end_date']) - strtotime($d['start_date'])) / 86400 > 180) {
        $errors['end_date'] = 'ลาได้ไม่เกิน 180 วันต่อใบลา';
    }
    if (!in_array($d['half_day'], ['', 'morning', 'afternoon'], true) || ($d['half_day'] !== '' && $d['start_date'] !== $d['end_date'])) {
        $d['half_day'] = '';
    }
    if ($d['reason'] === '' && in_array($d['leave_type'], ['sick', 'personal', 'other'], true)) {
        $errors['reason'] = 'กรุณาระบุเหตุผลการลา';
    }
    $days = 0.0;
    if (!$errors) {
        $days = hr_count_leave_days($d['leave_type'], $d['start_date'], $d['end_date'], $d['half_day']);
        if ($days <= 0) {
            $errors['end_date'] = 'ช่วงวันที่เลือกไม่มีวันทำการ (เสาร์–อาทิตย์)';
        }
        $overlap = db_value(
            "SELECT id FROM hr_leaves WHERE user_id = ? AND status NOT IN ('cancelled', 'rejected') AND start_date <= ? AND end_date >= ?
             AND NOT (start_date = end_date AND start_date = ? AND half_day <> '' AND ? <> '' AND half_day <> ?) LIMIT 1",
            'isssss',
            [(int)$owner['id'], $d['end_date'], $d['start_date'], $d['start_date'], $d['half_day'], $d['half_day']]
        );
        if ($overlap) {
            $errors['start_date'] = 'มีใบลาในช่วงวันที่นี้แล้ว (ใบลาเลขที่ ' . (int)$overlap . ')';
        }
    }
    $up = hr_take_upload('attachment');
    if (!$up['ok']) {
        $errors['attachment'] = $up['error'];
    }
    if ($errors) {
        if (!empty($up['stored_name'])) {
            delete_upload(HR_UPLOAD_DIR, $up['stored_name']);
        }
        $_GET['new'] = '1';
        $_GET['for'] = $recordFor ? (string)$forId : '';
    } else {
        $leaveRow = ['user_id' => (int)$owner['id'], 'user_role' => $owner['role'], 'department_id' => $owner['department_id']];
        $status = $recordFor ? 'approved' : hr_leave_next_status('pending_head', $leaveRow);
        $id = db_insert(
            'INSERT INTO hr_leaves (user_id, fiscal_year, leave_type, start_date, end_date, half_day, days, reason, contact, substitute,
                                    attach_stored, attach_original, status, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iissssdssssssi',
            [(int)$owner['id'], hr_fiscal_year_of($d['start_date']), $d['leave_type'], $d['start_date'], $d['end_date'], $d['half_day'], $days,
                $d['reason'], $d['contact'], $d['substitute'], $up['stored_name'], $up['original_name'], $status, $recordFor ? $uid : null]
        );
        hr_leave_log_action($id, $recordFor ? 'record' : 'submit', '', $recordFor ? mb_substr(post('record_note'), 0, 500) : '');
        $desc = hr_leave_type_label($d['leave_type']) . ' ' . hr_leave_range_text($d) . ' (' . hr_days($days) . ' วัน)';
        if ($recordFor) {
            log_activity('hr_leave_record', 'บันทึกใบลาแทน ' . $owner['full_name'] . ': ' . $desc);
            flash('success', 'บันทึกใบลาของ ' . $owner['full_name'] . ' แล้ว (สถานะ: อนุญาต)');
            redirect('hr/leave_review.php?id=' . $id);
        }
        log_activity('hr_leave_submit', 'ยื่นใบลา ' . $desc);
        flash('success', 'ยื่นใบลาเรียบร้อยแล้ว — สถานะ: ' . hr_leave_status_labels()[$status]);
        redirect($self . '?id=' . $id);
    }
}

// =====================================================================
// ยื่นใบลา (?new=1)
// =====================================================================
if (get('new') === '1') {
    $forId = get_int('for');
    $recordFor = $isHr && $forId > 0 && $forId !== $uid;
    $owner = $recordFor ? hr_person($forId) : hr_person($uid);
    if (!$owner) {
        abort_page(404, 'ไม่พบบุคลากร');
    }
    $fy = current_fiscal_year();
    $used = hr_leave_used((int)$owner['id'], $fy);
    $quota = hr_vacation_quota((int)$owner['id'], $fy);
    $flow = [];
    if (!$recordFor) {
        $row = ['user_id' => (int)$owner['id'], 'user_role' => $owner['role'], 'department_id' => $owner['department_id']];
        $order = hr_leave_pending_statuses();
        $st = 'pending_head';
        while (($st = hr_leave_next_status($st, $row)) !== 'approved') {
            $flow[] = hr_leave_step_labels()[hr_leave_status_step($st)];
            $st = $order[array_search($st, $order, true) + 1] ?? null;
            if ($st === null) {
                break;
            }
        }
    }
    $val = fn(string $k, string $def = '') => is_post() ? post($k) : $def;
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
    $inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';

    $pageTitle = $recordFor ? 'บันทึกใบลาแทน: ' . $owner['full_name'] : 'ยื่นใบลา';
    $pageIcon = 'bi-calendar-plus';
    $breadcrumbs = $recordFor ? ['ตรวจสอบ/พิจารณาใบลา' => 'hr/leave_review.php', 'บันทึกใบลาแทน' => null] : ['การลา' => $self, 'ยื่นใบลา' => null];
    if ($recordFor) {
        $activeMenu = 'hr/leave_review.php';
    }
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <form method="post" enctype="multipart/form-data" novalidate class="card" data-confirm="ยืนยัน<?= $recordFor ? 'บันทึกใบลาแทน' : 'ยื่นใบลา' ?>?">
                <div class="card-header"><i class="bi bi-calendar-plus me-2"></i><?= $recordFor ? 'บันทึกใบลา (อนุญาตแล้ว)' : 'แบบยื่นใบลา' ?></div>
                <div class="card-body">
                    <?= csrf_field() ?><input type="hidden" name="action" value="submit">
                    <?php if ($recordFor): ?><input type="hidden" name="for" value="<?= (int)$owner['id'] ?>">
                        <div class="alert alert-info small">บันทึกใบลาของ <b><?= e($owner['full_name']) ?></b> ที่ยื่นเป็นกระดาษ/ได้รับอนุญาตแล้ว — ระบบบันทึกเป็นสถานะ “อนุญาต” ทันที</div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="leave_type">ประเภทการลา <span class="text-danger">*</span></label>
                            <select class="form-select<?= $inv('leave_type') ?>" name="leave_type" id="leave_type" required>
                                <option value="">-- เลือก --</option>
                                <?php foreach (hr_leave_types() as $k => [$label]): ?>
                                    <option value="<?= $k ?>" <?= $val('leave_type') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select><?= $err('leave_type') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="half_day">ช่วงเวลา (ลา 1 วัน)</label>
                            <select class="form-select" name="half_day" id="half_day">
                                <option value="">เต็มวัน</option>
                                <option value="morning" <?= $val('half_day') === 'morning' ? 'selected' : '' ?>>ครึ่งวันเช้า</option>
                                <option value="afternoon" <?= $val('half_day') === 'afternoon' ? 'selected' : '' ?>>ครึ่งวันบ่าย</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="start_date">ตั้งแต่วันที่ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control<?= $inv('start_date') ?>" name="start_date" id="start_date" value="<?= e($val('start_date')) ?>" required><?= $err('start_date') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="end_date">ถึงวันที่</label>
                            <input type="date" class="form-control<?= $inv('end_date') ?>" name="end_date" id="end_date" value="<?= e($val('end_date')) ?>"><?= $err('end_date') ?>
                            <div class="form-text" id="dayCount">ลาวันเดียว ไม่ต้องระบุ</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="reason">เหตุผลการลา</label>
                            <textarea class="form-control<?= $inv('reason') ?>" name="reason" id="reason" rows="2" maxlength="1000" placeholder="เช่น ป่วยเป็นไข้หวัด / ไปทำธุระส่วนตัวที่..."><?= e($val('reason')) ?></textarea><?= $err('reason') ?>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="contact">ระหว่างลาติดต่อได้ที่</label>
                            <input class="form-control" name="contact" id="contact" maxlength="300" value="<?= e($val('contact', (string)$owner['phone'])) ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="substitute">ผู้ปฏิบัติงาน/สอนแทน</label>
                            <input class="form-control" name="substitute" id="substitute" maxlength="150" value="<?= e($val('substitute')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="attachment">ไฟล์แนบ (ถ้ามี เช่น ใบรับรองแพทย์)</label>
                            <input type="file" class="form-control<?= $inv('attachment') ?>" name="attachment" id="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp"><?= $err('attachment') ?>
                            <div class="form-text">PDF หรือรูปภาพ ไม่เกิน <?= format_bytes(min(HR_MAX_FILE_BYTES, max_upload_bytes())) ?> · ลาป่วยตั้งแต่ 30 วันขึ้นไปต้องมีใบรับรองแพทย์</div>
                        </div>
                        <?php if ($recordFor): ?>
                            <div class="col-12">
                                <label class="form-label" for="record_note">หมายเหตุการบันทึก</label>
                                <input class="form-control" name="record_note" id="record_note" maxlength="500" placeholder="เช่น ใบลากระดาษ ผู้อำนวยการอนุญาตเมื่อ ...">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <a href="<?= e(url($recordFor ? 'hr/leave_review.php' : $self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button class="btn btn-accent ms-auto" data-loading-text="กำลังบันทึก..."><i class="bi bi-send me-1"></i> <?= $recordFor ? 'บันทึกใบลา' : 'ยื่นใบลา' ?></button>
                </div>
            </form>
        </div>
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-graph-up me-2"></i>วันลาปีงบประมาณ <?= $fy ?> (อนุญาตแล้ว)</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach (hr_leave_types() as $k => [$label]): if ($used[$k]['times'] === 0 && !in_array($k, ['sick', 'personal', 'vacation'], true)) continue; ?>
                        <li class="list-group-item d-flex"><span><?= e($label) ?></span><span class="ms-auto"><?= $used[$k]['times'] ?> ครั้ง · <?= hr_days($used[$k]['days']) ?> วัน</span></li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex text-primary"><span>ลาพักผ่อนคงเหลือ</span><span class="ms-auto"><?= hr_days(max(0, $quota - $used['vacation']['days'])) ?> / <?= hr_days($quota) ?> วัน</span></li>
                </ul>
            </div>
            <?php if ($flow): ?>
                <div class="card">
                    <div class="card-header"><i class="bi bi-diagram-2 me-2"></i>ลำดับการพิจารณา</div>
                    <div class="card-body small"><?= e(implode(' → ', $flow)) ?> → อนุญาต</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $pageScripts = '
(function () {
    var s = document.getElementById("start_date"), en = document.getElementById("end_date"), h = document.getElementById("half_day"),
        t = document.getElementById("leave_type"), out = document.getElementById("dayCount");
    var cal = ' . json_encode(array_keys(array_filter(hr_leave_types(), fn($x) => $x[1] === 'calendar'))) . ';
    function upd() {
        if (!s.value) { out.textContent = "ลาวันเดียว ไม่ต้องระบุ"; return; }
        var a = new Date(s.value + "T00:00:00"), b = new Date((en.value || s.value) + "T00:00:00");
        if (b < a) { out.textContent = "วันที่สิ้นสุดต้องไม่ก่อนวันเริ่มลา"; return; }
        var n = 0, all = cal.indexOf(t.value) >= 0;
        for (var d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) { var w = d.getDay(); if (all || (w > 0 && w < 6)) n++; }
        if (+a === +b && h.value) n = 0.5;
        h.disabled = +a !== +b; if (h.disabled) h.value = "";
        out.textContent = "รวม " + n + " วัน" + (all ? " (นับวันต่อเนื่อง)" : " (นับเฉพาะวันจันทร์–ศุกร์ งานบุคลากรปรับวันหยุดราชการได้)");
    }
    [s, en, h, t].forEach(function (el) { el.addEventListener("change", upd); });
    upd();
})();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ดูใบลา (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $leave = hr_leave_fetch($viewId);
    if (!$leave || (int)$leave['user_id'] !== $uid) {
        abort_page(404, 'ไม่พบใบลา');
    }
    $actions = hr_leave_actions($viewId);
    $pageTitle = 'ใบลา: ' . hr_leave_type_label($leave['leave_type']);
    $pageIcon = 'bi-calendar-x';
    $breadcrumbs = ['การลา' => $self . '?fy=' . (int)$leave['fiscal_year'], 'ใบลาเลขที่ ' . $viewId => null];
    $pageActions = '<a href="' . e(url('hr/leave_print.php?id=' . $viewId)) . '" target="_blank" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i> พิมพ์ใบลา</a>';
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4">
        <div class="col-xl-8"><?php require APP_ROOT . '/includes/partials/hr_leave_detail.php'; ?></div>
        <div class="col-xl-4">
            <?php if (in_array($leave['status'], hr_leave_pending_statuses(), true)): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header text-danger"><i class="bi bi-x-circle me-2"></i>ยกเลิกใบลา</div>
                    <div class="card-body">
                        <form method="post" data-confirm="ยกเลิกใบลานี้?" data-confirm-icon="warning">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $viewId ?>">
                            <input class="form-control form-control-sm mb-2" name="cancel_reason" maxlength="500" placeholder="เหตุผล (ถ้ามี)">
                            <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i> ยกเลิกใบลา</button>
                        </form>
                        <div class="form-text">ยกเลิกได้ระหว่างรอพิจารณา — ถ้าอนุญาตแล้วต้องการยกเลิก ให้ติดต่องานบุคลากร</div>
                    </div>
                </div>
            <?php endif; ?>
            <a href="<?= e(url($self . '?fy=' . (int)$leave['fiscal_year'])) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการใบลาของฉัน
// =====================================================================
$fy = hr_fiscal_param();
$rows = db_all('SELECT * FROM hr_leaves WHERE user_id = ? AND fiscal_year = ? ORDER BY start_date DESC, id DESC', 'ii', [$uid, $fy]);
$used = hr_leave_used($uid, $fy);
$quota = hr_vacation_quota($uid, $fy);

$pageTitle = 'การลา';
$pageIcon = 'bi-calendar-x';
$breadcrumbs = ['การลา' => null];
$pageActions = '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-calendar-plus me-1"></i> ยื่นใบลา</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <?php foreach (['sick' => 'ic-red', 'personal' => 'ic-blue', 'vacation' => 'ic-green'] as $t => $ic): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon <?= $ic ?>"><i class="bi <?= e(hr_leave_types()[$t][2]) ?>"></i></div>
                <div>
                    <div class="stat-value"><?= hr_days($used[$t]['days']) ?> <small class="fs-6 fw-normal">วัน</small></div>
                    <div class="stat-label"><?= e(hr_leave_type_label($t)) ?> (<?= $used[$t]['times'] ?> ครั้ง)</div>
                    <?php if ($t === 'vacation'): ?><div class="stat-sub">คงเหลือ <?= hr_days(max(0, $quota - $used['vacation']['days'])) ?> จาก <?= hr_days($quota) ?> วัน</div><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-xl-3">
        <form method="get" class="stat-card">
            <div class="stat-icon ic-navy"><i class="bi bi-calendar3"></i></div>
            <div class="flex-fill">
                <label class="stat-label d-block" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่ลา</th><th class="text-end">วัน</th><th>เหตุผล</th><th>สถานะ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= e(hr_leave_type_label($r['leave_type'])) ?></td>
                        <td class="text-nowrap" data-order="<?= e($r['start_date']) ?>"><?= e(hr_leave_range_text($r)) ?></td>
                        <td class="text-end"><?= hr_days((float)$r['days']) ?></td>
                        <td class="small"><?= e(str_limit($r['reason'], 60)) ?></td>
                        <td data-order="<?= e($r['status']) ?>"><?= hr_leave_status_badge($r['status']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> ดู</a>
                            <a href="<?= e(url('hr/leave_print.php?id=' . (int)$r['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="พิมพ์ใบลา"><i class="bi bi-printer"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
