<?php
/**
 * ไฟล์: review/advisor_reports.php
 * ตรวจรายงานการพบนักเรียนของครูที่ปรึกษา — admin และงานครูที่ปรึกษาและแนะแนว (หัวหน้างาน/เจ้าหน้าที่ ตามตำแหน่ง)
 *   ?id=      ตรวจรายงาน (ผ่าน / ให้แก้ไข)
 *   ?ay= ?date= ?status= ?dept=   ตัวกรองหน้ารายการ
 * ผู้ตรวจตามตำแหน่งตรวจได้ทุกแผนก ยกเว้นรายงานของตนเอง / admin เปลี่ยนผลการตรวจได้ทุกเวลา
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_advisor($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลระบบ และ ' . implode(' / ', advisor_staff_positions()));
}
$isAdmin = $user['role'] === 'admin';
$uid = (int)$user['id'];
$self = 'review/advisor_reports.php';
$activeMenu = $self;
$errors = [];

// =====================================================================
// POST: บันทึกผลการตรวจ
// =====================================================================
if (is_post()) {
    csrf_verify();
    $rep = advisor_report_fetch((int)post('id'));
    if (!$rep || !can_review_advisor_report($user, $rep)) {
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
    if (!$isAdmin && $rep['status'] !== 'pending') {
        $errors['result'] = 'รายงานนี้ถูกตรวจแล้ว (เปลี่ยนผลได้เฉพาะผู้ดูแลระบบ)';
    }
    if (!$errors) {
        db_exec(
            'UPDATE advisor_reports SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            'ssii',
            [$result, $comment, $uid, (int)$rep['id']]
        );
        log_activity('advisor_report_review', ($result === 'approved' ? 'ผ่าน' : 'ให้แก้ไข') . ': รายงานครูที่ปรึกษา '
            . $rep['group_name'] . ' วันพุธที่ ' . thai_date($rep['meet_date']) . ' ของ ' . $rep['teacher_name']);
        flash('success', 'บันทึกผลการตรวจ "' . status_labels()[$result] . '" เรียบร้อยแล้ว');

        // ไปรายงานรอตรวจรายการถัดไป (ถ้ามี)
        $next = db_value(
            "SELECT id FROM advisor_reports WHERE status = 'pending' AND id <> ? AND (? = 1 OR teacher_id <> ?) ORDER BY meet_date, submitted_at LIMIT 1",
            'iii',
            [(int)$rep['id'], $isAdmin ? 1 : 0, $uid]
        );
        redirect($self . ($next ? '?id=' . (int)$next : '?status=pending'));
    }
    $_GET['id'] = (string)$rep['id'];
}

// =====================================================================
// หน้าตรวจรายงาน (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $rep = advisor_report_fetch($viewId);
    if (!$rep || (!$isAdmin && (int)$rep['teacher_id'] === $uid)) {
        abort_page(404, 'ไม่พบรายงาน', 'รายงานนี้ไม่มีอยู่ หรือเป็นรายงานของท่านเอง (ตรวจโดยผู้ตรวจท่านอื่น)');
    }
    $canReview = can_review_advisor_report($user, $rep) && ($isAdmin || $rep['status'] === 'pending');

    $pageTitle = 'ตรวจรายงานครูที่ปรึกษา';
    $pageIcon = 'bi-clipboard-check';
    $breadcrumbs = ['ตรวจรายงานครูที่ปรึกษา' => $self, $rep['teacher_name'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4">
        <div class="col-xl-8"><?php require APP_ROOT . '/includes/partials/advisor_report_detail.php'; ?></div>
        <div class="col-xl-4">
            <div class="card mb-4 border-top border-4 border-warning">
                <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>บันทึกผลการตรวจ</div>
                <div class="card-body">
                    <?php if ($canReview): ?>
                        <?php if ($rep['status'] !== 'pending'): ?>
                            <div class="alert alert-info small">รายงานนี้ตรวจแล้ว (<?= e(status_labels()[$rep['status']]) ?>) การบันทึกใหม่จะเปลี่ยนผลการตรวจ</div>
                        <?php endif; ?>
                        <form method="post" novalidate data-confirm="ยืนยันบันทึกผลการตรวจ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$rep['id'] ?>">
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
                            <?= $rep['status'] === 'pending' ? 'ท่านไม่มีสิทธิ์ตรวจรายงานนี้' : 'รายงานนี้ตรวจแล้ว: ' . e(status_labels()[$rep['status']]) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>ติดต่อครูที่ปรึกษา</div>
                <div class="card-body small">
                    <div class="fw-semibold"><?= e($rep['teacher_name']) ?></div>
                    <div><i class="bi bi-envelope me-1"></i><?= e($rep['teacher_email']) ?></div>
                    <div><i class="bi bi-telephone me-1"></i><?= e($rep['teacher_phone'] ?: '-') ?></div>
                    <a class="d-inline-block mt-2" href="<?= e(url('reports/advisor_teacher.php?teacher=' . (int)$rep['teacher_id'] . '&ay=' . (int)$rep['academic_year_id'])) ?>">
                        <i class="bi bi-bar-chart-line me-1"></i>สรุปการส่งรายงานของครูท่านนี้</a>
                </div>
            </div>
            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ
// =====================================================================
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ay = advisor_academic_year(get_int('ay'));
$ayId = (int)($ay['id'] ?? 0);
$fStatus = in_array(get('status'), ['pending', 'approved', 'revision', 'late'], true) ? get('status') : '';
$fDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('date')) ? get('date') : '';
$fDept = get_int('dept');
$departments = db_all('SELECT id, name FROM departments ORDER BY name');
$weekOptions = array_reverse(advisor_wednesdays($ay), true);

$where = ['g.academic_year_id = ?'];
$types = 'i';
$params = [$ayId];
if ($fDate !== '') {
    $where[] = 'r.meet_date = ?';
    $types .= 's';
    $params[] = $fDate;
}
if ($fDept > 0) {
    $where[] = 'g.department_id = ?';
    $types .= 'i';
    $params[] = $fDept;
}
if (!$isAdmin) {
    $where[] = 'r.teacher_id <> ?';
    $types .= 'i';
    $params[] = $uid;
}
$rows = db_all(
    "SELECT r.id, r.meet_date, r.status, r.is_late, r.submitted_at, r.updated_at, r.total_students, r.present_count, r.late_count,
            g.group_name, d.name AS department_name, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name
     FROM advisor_reports r
     JOIN advisor_groups g ON g.id = r.group_id
     JOIN users u ON u.id = r.teacher_id
     LEFT JOIN departments d ON d.id = g.department_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(r.status, 'pending', 'revision', 'approved'), r.meet_date DESC, r.submitted_at",
    $types,
    $params
);
// นับตามสถานะ (ในขอบเขตตัวกรองอื่น) แล้วค่อยกรองสถานะใน PHP
$counts = ['all' => count($rows), 'pending' => 0, 'approved' => 0, 'revision' => 0, 'late' => 0];
foreach ($rows as $r) {
    $counts[$r['status']]++;
    $counts['late'] += (int)$r['is_late'];
}
if ($fStatus !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $fStatus === 'late' ? (int)$r['is_late'] === 1 : $r['status'] === $fStatus));
}
$q = function (array $over) use ($ayId, $fStatus, $fDate, $fDept): string {
    $base = ['ay' => $ayId, 'status' => $fStatus, 'date' => $fDate, 'dept' => $fDept ?: null];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
};

$pageTitle = 'ตรวจรายงานครูที่ปรึกษา';
$pageIcon = 'bi-clipboard-check';
$breadcrumbs = ['ตรวจรายงานครูที่ปรึกษา' => null];
$pageActions = '<a href="' . e(url('reports/advisor_summary.php?ay=' . $ayId)) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> สรุปภาพรวม</a>';
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$isAdmin): ?>
    <div class="alert alert-light border small"><i class="bi bi-person-check me-1"></i>
        ท่านเป็นผู้ตรวจรายงานครูที่ปรึกษาตามตำแหน่ง “<?= e($user['position']) ?>” — แสดงรายงานของครูทุกแผนก (ยกเว้นรายงานของท่านเอง)</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $chips = ['' => ['ทั้งหมด', 'all', 'btn-outline-primary'], 'pending' => ['รอตรวจ', 'pending', 'btn-outline-warning'],
        'revision' => ['ให้แก้ไข', 'revision', 'btn-outline-danger'], 'approved' => ['ผ่าน', 'approved', 'btn-outline-success'],
        'late' => ['ส่งช้า', 'late', 'btn-outline-secondary']];
    foreach ($chips as $k => [$label, $ck, $cls]): ?>
        <a href="<?= e($q(['status' => $k])) ?>" class="btn btn-sm <?= $fStatus === $k ? str_replace('outline-', '', $cls) : $cls ?>">
            <?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$ck] ?></span></a>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="<?= e($fStatus) ?>">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $ayId === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="date">สัปดาห์ (วันพุธ)</label>
                <select name="date" id="date" class="form-select">
                    <option value="">ทุกสัปดาห์</option>
                    <?php foreach ($weekOptions as $d => $skip): ?>
                        <option value="<?= e($d) ?>" <?= $fDate === $d ? 'selected' : '' ?>><?= e(advisor_date_label($d)) ?><?= $skip !== null ? ' (งดพบ)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
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
                <tr><th>วันพุธ</th><th>ครูที่ปรึกษา</th><th>กลุ่มเรียน</th><th>แผนก</th><th class="text-center">เข้าพบ</th><th>สถานะ</th><th>ส่งเมื่อ</th><th class="no-sort"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap" data-order="<?= e($r['meet_date']) ?>"><?= e(advisor_date_label($r['meet_date'])) ?></td>
                        <td><?= e($r['teacher_name']) ?></td>
                        <td><?= e($r['group_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td>
                        <td class="text-center small"><?= (int)$r['present_count'] + (int)$r['late_count'] ?>/<?= (int)$r['total_students'] ?></td>
                        <td data-order="<?= e($r['status']) ?>"><?= status_badge($r['status'], (bool)$r['is_late']) ?></td>
                        <td class="small" data-order="<?= e($r['submitted_at']) ?>"><?= e(thai_datetime($r['updated_at'] ?? $r['submitted_at'])) ?></td>
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
