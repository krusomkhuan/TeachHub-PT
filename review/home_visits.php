<?php
/**
 * ไฟล์: review/home_visits.php
 * ตรวจรายงานการเยี่ยมบ้านนักเรียน — admin และงานครูที่ปรึกษาและแนะแนว (หัวหน้างาน/เจ้าหน้าที่ ตามตำแหน่ง)
 *   ?id=   ตรวจรายงาน (ผ่าน / ให้แก้ไข)
 *   ?year= ?status= ?risk= ?dept=   ตัวกรองหน้ารายการ
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_advisor($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลระบบ และ ' . implode(' / ', advisor_staff_positions()));
}
$isAdmin = $user['role'] === 'admin';
$uid = (int)$user['id'];
$self = 'review/home_visits.php';
$activeMenu = $self;
$errors = [];

// =====================================================================
// POST: บันทึกผลการตรวจ
// =====================================================================
if (is_post()) {
    csrf_verify();
    $hv = home_visit_fetch((int)post('id'));

    // ---------- ลบรายงานของครู (ระบุเหตุผล บันทึกใน log) ----------
    if (post('action') === 'delete') {
        if (!$hv || !can_delete_advisor_report($user, $hv)) {
            abort_page(403, 'ไม่มีสิทธิ์ลบรายงานนี้');
        }
        $reason = mb_substr(post('delete_reason'), 0, 300);
        if ($reason === '') {
            flash('error', 'กรุณาระบุเหตุผลที่ลบรายงาน');
            redirect($self . '?id=' . (int)$hv['id']);
        }
        home_visit_delete($hv);
        log_activity('home_visit_delete', 'ลบรายงานเยี่ยมบ้าน ' . $hv['student_name'] . ' (' . $hv['student_code'] . ') ของ '
            . $hv['teacher_name'] . ' (ลบโดยผู้ตรวจ เหตุผล: ' . $reason . ')');
        flash('success', 'ลบรายงานเยี่ยมบ้านของ ' . $hv['student_name'] . ' แล้ว — ครูสามารถบันทึกการเยี่ยมบ้านนักเรียนคนนี้ใหม่ได้');
        redirect($self . '?year=' . (int)$hv['school_year']);
    }

    if (!$hv || !can_review_home_visit($user, $hv)) {
        abort_page(403, 'ไม่มีสิทธิ์ตรวจรายงานนี้');
    }
    $result = post('result');
    $comment = mb_substr(post('comment'), 0, 5000);
    if (!in_array($result, ['approved', 'revision'], true)) {
        $errors['result'] = 'กรุณาเลือกผลการตรวจ';
    }
    if ($result === 'revision' && $comment === '') {
        $errors['comment'] = 'กรุณาระบุสิ่งที่ต้องแก้ไข เพื่อให้ครูแก้ได้ถูกต้อง';
    }
    if (!$isAdmin && $hv['status'] !== 'pending') {
        $errors['result'] = 'รายงานนี้ถูกตรวจแล้ว (เปลี่ยนผลได้เฉพาะผู้ดูแลระบบ)';
    }
    if (!$errors) {
        db_exec(
            'UPDATE home_visits SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            'ssii',
            [$result, $comment, $uid, (int)$hv['id']]
        );
        log_activity('home_visit_review', ($result === 'approved' ? 'ผ่าน' : 'ให้แก้ไข') . ': รายงานเยี่ยมบ้าน '
            . $hv['student_name'] . ' (' . $hv['student_code'] . ') ของ ' . $hv['teacher_name']);
        flash('success', 'บันทึกผลการตรวจ "' . status_labels()[$result] . '" เรียบร้อยแล้ว');
        $next = db_value(
            "SELECT id FROM home_visits WHERE status = 'pending' AND id <> ? AND (? = 1 OR teacher_id <> ?) ORDER BY submitted_at LIMIT 1",
            'iii',
            [(int)$hv['id'], $isAdmin ? 1 : 0, $uid]
        );
        redirect($self . ($next ? '?id=' . (int)$next : '?status=pending&year=' . (int)$hv['school_year']));
    }
    $_GET['id'] = (string)$hv['id'];
}

// =====================================================================
// หน้าตรวจรายงาน (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $hv = home_visit_fetch($viewId);
    if (!$hv || (!$isAdmin && (int)$hv['teacher_id'] === $uid)) {
        abort_page(404, 'ไม่พบรายงาน', 'รายงานนี้ไม่มีอยู่ หรือเป็นรายงานของท่านเอง (ตรวจโดยผู้ตรวจท่านอื่น)');
    }
    $canReview = can_review_home_visit($user, $hv) && ($isAdmin || $hv['status'] === 'pending');

    $pageTitle = 'ตรวจรายงานการเยี่ยมบ้าน';
    $pageIcon = 'bi-house-check';
    $breadcrumbs = ['ตรวจรายงานการเยี่ยมบ้าน' => $self . '?year=' . (int)$hv['school_year'], $hv['student_name'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4">
        <div class="col-xl-8"><?php require APP_ROOT . '/includes/partials/home_visit_detail.php'; ?></div>
        <div class="col-xl-4">
            <div class="card mb-4 border-top border-4 border-warning">
                <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>บันทึกผลการตรวจ</div>
                <div class="card-body">
                    <?php if ($canReview): ?>
                        <?php if ($hv['status'] !== 'pending'): ?>
                            <div class="alert alert-info small">รายงานนี้ตรวจแล้ว (<?= e(status_labels()[$hv['status']]) ?>) การบันทึกใหม่จะเปลี่ยนผลการตรวจ</div>
                        <?php endif; ?>
                        <form method="post" novalidate data-confirm="ยืนยันบันทึกผลการตรวจ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$hv['id'] ?>">
                            <div class="mb-3">
                                <div class="btn-group w-100" role="group" aria-label="ผลการตรวจ">
                                    <input type="radio" class="btn-check" name="result" id="rApproved" value="approved" <?= post('result') === 'approved' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success" for="rApproved"><i class="bi bi-check-circle me-1"></i>ผ่าน</label>
                                    <input type="radio" class="btn-check" name="result" id="rRevision" value="revision" <?= post('result') === 'revision' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-danger" for="rRevision"><i class="bi bi-pencil-square me-1"></i>ให้แก้ไข</label>
                                </div>
                                <?php if (isset($errors['result'])): ?><div class="text-danger small mt-1"><?= e($errors['result']) ?></div><?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comment">ความเห็น / สิ่งที่ต้องแก้ไข</label>
                                <textarea class="form-control<?= isset($errors['comment']) ? ' is-invalid' : '' ?>" name="comment" id="comment" rows="5"
                                          maxlength="5000" placeholder="ระบุข้อเสนอแนะ (จำเป็นเมื่อเลือก &quot;ให้แก้ไข&quot;)"><?= old('comment') ?></textarea>
                                <?php if (isset($errors['comment'])): ?><div class="invalid-feedback"><?= e($errors['comment']) ?></div><?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-accent w-100" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกผลการตรวจ</button>
                        </form>
                    <?php else: ?>
                        <div class="text-muted"><i class="bi bi-lock me-1"></i>
                            <?= $hv['status'] === 'pending' ? 'ท่านไม่มีสิทธิ์ตรวจรายงานนี้' : 'รายงานนี้ตรวจแล้ว: ' . e(status_labels()[$hv['status']]) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>ครูที่ปรึกษา</div>
                <div class="card-body small">
                    <div class="fw-semibold"><?= e($hv['teacher_name']) ?></div>
                    <div><i class="bi bi-envelope me-1"></i><?= e($hv['teacher_email']) ?></div>
                    <div><i class="bi bi-telephone me-1"></i><?= e($hv['teacher_phone'] ?: '-') ?></div>
                    <a class="d-inline-block mt-2" href="<?= e(url('reports/home_visit_teacher.php?teacher=' . (int)$hv['teacher_id'] . '&year=' . (int)$hv['school_year'])) ?>">
                        <i class="bi bi-bar-chart-line me-1"></i>สรุปการเยี่ยมบ้านของครูท่านนี้</a>
                </div>
            </div>
            <?php if (can_delete_advisor_report($user, $hv)): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header text-danger"><i class="bi bi-trash me-2"></i>ลบรายงาน</div>
                    <div class="card-body">
                        <form method="post" data-confirm="ลบรายงานเยี่ยมบ้านของ <?= e($hv['student_name']) ?> พร้อมภาพถ่าย? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$hv['id'] ?>">
                            <input class="form-control form-control-sm mb-2" name="delete_reason" maxlength="300" required placeholder="เหตุผล เช่น รหัสนักเรียนผิด / บันทึกซ้ำ">
                            <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash me-1"></i> ลบรายงาน</button>
                        </form>
                        <div class="form-text">ใช้เมื่อครูบันทึกผิด — ลบแล้วครูบันทึกการเยี่ยมบ้านนักเรียนคนนี้ใหม่ได้ (บันทึกเหตุผลไว้ใน Log)</div>
                    </div>
                </div>
            <?php endif; ?>
            <a href="<?= e(url($self . '?year=' . (int)$hv['school_year'])) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ
// =====================================================================
$years = home_visit_years();
$year = home_visit_year(get_int('year'));
$fStatus = in_array(get('status'), ['pending', 'approved', 'revision'], true) ? get('status') : '';
$fRisk = isset(home_visit_risk_labels()[get('risk')]) ? get('risk') : '';
$fDept = get_int('dept');
$departments = db_all('SELECT id, name FROM departments ORDER BY name');

$where = ['v.school_year = ?'];
$types = 'i';
$params = [$year];
if ($fRisk !== '') {
    $where[] = 'v.risk_level = ?';
    $types .= 's';
    $params[] = $fRisk;
}
if ($fDept > 0) {
    $where[] = 'g.department_id = ?';
    $types .= 'i';
    $params[] = $fDept;
}
if (!$isAdmin) {
    $where[] = 'v.teacher_id <> ?';
    $types .= 'i';
    $params[] = $uid;
}
$rows = db_all(
    "SELECT v.id, v.student_code, v.student_name, v.visit_date, v.risk_level, v.status, v.submitted_at, v.updated_at,
            g.group_name, d.name AS department_name, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name
     FROM home_visits v
     JOIN advisor_groups g ON g.id = v.group_id
     JOIN users u ON u.id = v.teacher_id
     LEFT JOIN departments d ON d.id = g.department_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(v.status, 'pending', 'revision', 'approved'), v.submitted_at",
    $types,
    $params
);
$counts = ['all' => count($rows), 'pending' => 0, 'approved' => 0, 'revision' => 0];
foreach ($rows as $r) {
    $counts[$r['status']]++;
}
if ($fStatus !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $fStatus));
}
$q = function (array $over) use ($year, $fStatus, $fRisk, $fDept): string {
    $base = ['year' => $year, 'status' => $fStatus, 'risk' => $fRisk, 'dept' => $fDept ?: null];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
};

$pageTitle = 'ตรวจรายงานการเยี่ยมบ้าน';
$pageIcon = 'bi-house-check';
$breadcrumbs = ['ตรวจรายงานการเยี่ยมบ้าน' => null];
$pageActions = '<a href="' . e(url('reports/home_visit_summary.php?year=' . $year)) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> สรุปภาพรวม</a>';
// ผู้ตรวจที่เป็นครูที่ปรึกษาด้วย: ทางลัดไปหน้าบันทึกการเยี่ยมบ้านของตนเอง
if (in_array($user['role'], teacher_roles(), true) && is_advisor_teacher($uid)) {
    $pageActions = '<a href="' . e(url('teacher/home_visits.php')) . '" class="btn btn-accent"><i class="bi bi-house-add me-1"></i> บันทึกการเยี่ยมบ้านของฉัน</a> ' . $pageActions;
}
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$isAdmin): ?>
    <div class="alert alert-light border small"><i class="bi bi-person-check me-1"></i>
        ท่านเป็นผู้ตรวจรายงานการเยี่ยมบ้านตามตำแหน่ง “<?= e($user['position']) ?>” — แสดงรายงานของครูทุกแผนก (ยกเว้นรายงานของท่านเอง — บันทึก/ดูรายงานของท่านที่เมนู “ครูที่ปรึกษา (รายงานของฉัน)”)</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $chips = ['' => ['ทั้งหมด', 'all', 'btn-outline-primary'], 'pending' => ['รอตรวจ', 'pending', 'btn-outline-warning'],
        'revision' => ['ให้แก้ไข', 'revision', 'btn-outline-danger'], 'approved' => ['ผ่าน', 'approved', 'btn-outline-success']];
    foreach ($chips as $k => [$label, $ck, $cls]): ?>
        <a href="<?= e($q(['status' => $k])) ?>" class="btn btn-sm <?= $fStatus === $k ? str_replace('outline-', '', $cls) : $cls ?>">
            <?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$ck] ?></span></a>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="<?= e($fStatus) ?>">
            <div class="col-md-2">
                <label class="form-label small mb-1" for="year">ปีการศึกษา</label>
                <select name="year" id="year" class="form-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="risk">ผลการคัดกรอง</label>
                <select name="risk" id="risk" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach (home_visit_risk_labels() as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $fRisk === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                <select name="dept" id="dept" class="form-select">
                    <option value="0">ทุกแผนก</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead>
                <tr><th>วันที่เยี่ยม</th><th>นักเรียน</th><th>กลุ่ม</th><th>ครูที่ปรึกษา</th><th>แผนก</th><th>ผลคัดกรอง</th><th>สถานะ</th><th class="no-sort"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap" data-order="<?= e($r['visit_date']) ?>"><?= e(thai_date($r['visit_date'])) ?></td>
                        <td><?= e($r['student_name']) ?> <span class="small text-muted">(<?= e($r['student_code']) ?>)</span></td>
                        <td class="small"><?= e($r['group_name']) ?></td>
                        <td><?= e($r['teacher_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td>
                        <td data-order="<?= e($r['risk_level']) ?>"><?= home_visit_risk_badge($r['risk_level']) ?></td>
                        <td data-order="<?= e($r['status']) ?>"><?= status_badge($r['status']) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm <?= $r['status'] === 'pending' ? 'btn-accent' : 'btn-outline-primary' ?> text-nowrap">
                                <?= $r['status'] === 'pending' ? '<i class="bi bi-clipboard-check"></i> ตรวจ' : '<i class="bi bi-eye"></i> ดู' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
