<?php
/**
 * ไฟล์: reports/advisor_teacher.php
 * สรุปการส่งรายงานการพบนักเรียนรายครูที่ปรึกษา: ส่งกี่สัปดาห์ / ขาดส่งสัปดาห์ใด
 *   ?teacher= ครู, ?ay= ภาคเรียน, ?report= ดูรายงาน 1 ฉบับ (แบบอ่านอย่างเดียว)
 * สิทธิ์: ครูเจ้าของ, admin, ผู้บริหาร, งานครูที่ปรึกษาฯ, หัวหน้าแผนก (ครูในแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];

/** ลิงก์ไปหน้ารายงาน 1 ฉบับที่เหมาะกับผู้ดู */
$reportLink = function (int $reportId, int $teacherId) use ($user, $uid): string {
    if ($teacherId === $uid) {
        return 'teacher/advisor_reports.php?id=' . $reportId;
    }
    if (can_manage_advisor($user)) {
        return 'review/advisor_reports.php?id=' . $reportId;
    }
    return 'reports/advisor_teacher.php?report=' . $reportId;
};

// ---------- ดูรายงาน 1 ฉบับ ----------
$reportId = get_int('report');
if ($reportId > 0) {
    $rep = advisor_report_fetch($reportId);
    if (!$rep || !can_view_advisor_teacher($user, (int)$rep['teacher_id'])) {
        abort_page(404, 'ไม่พบรายงาน');
    }
    $pageTitle = 'รายงานการพบนักเรียน';
    $pageIcon = 'bi-people';
    $breadcrumbs = ['สรุปรายงานครูที่ปรึกษา' => 'reports/advisor_summary.php',
        $rep['teacher_name'] => 'reports/advisor_teacher.php?teacher=' . (int)$rep['teacher_id'] . '&ay=' . (int)$rep['academic_year_id'],
        advisor_date_label($rep['meet_date']) => null];
    $activeMenu = 'reports/advisor_summary.php';
    require APP_ROOT . '/includes/header.php';
    require APP_ROOT . '/includes/partials/advisor_report_detail.php';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$teacherId = get_int('teacher', $uid);
if (!can_view_advisor_teacher($user, $teacherId)) {
    abort_page(403, 'ไม่มีสิทธิ์ดูข้อมูลของครูท่านนี้');
}
$teacher = db_one(
    'SELECT u.id, u.prefix, u.first_name, u.last_name, u.email, u.phone, d.name AS department_name
     FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?',
    'i',
    [$teacherId]
);
if (!$teacher) {
    abort_page(404, 'ไม่พบครู');
}
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ay = advisor_academic_year(get_int('ay'));
$ayId = (int)($ay['id'] ?? 0);
$groups = $ay ? advisor_teacher_groups($teacherId, $ayId) : [];
$sum = advisor_summary($ay, 0, $teacherId);
$t = $sum['teachers'][$teacherId] ?? null;
$weds = advisor_wednesdays($ay); // ถึงวันนี้ รวมวันงดพบ

$reports = [];
foreach (db_all(
    'SELECT r.id, r.group_id, r.meet_date, r.status, r.is_late, r.submitted_at, r.total_students, r.present_count, r.late_count
     FROM advisor_reports r JOIN advisor_groups g ON g.id = r.group_id WHERE r.teacher_id = ? AND g.academic_year_id = ?',
    'ii',
    [$teacherId, $ayId]
) as $r) {
    $reports[(int)$r['group_id']][$r['meet_date']] = $r;
}
$missing = [];
foreach ($groups as $g) {
    foreach ($weds as $d => $skip) {
        if ($skip === null && !isset($reports[(int)$g['id']][$d])) {
            $missing[] = [$g, $d];
        }
    }
}

$isOwn = $teacherId === $uid;
$pageTitle = 'สรุปรายงานครูที่ปรึกษา: ' . full_name($teacher);
$pageIcon = 'bi-person-badge';
$breadcrumbs = $isOwn && !can_view_advisor_overview($user)
    ? ['รายงานครูที่ปรึกษา' => 'teacher/advisor_reports.php?ay=' . $ayId, 'สรุปของฉัน' => null]
    : ['สรุปรายงานครูที่ปรึกษา' => 'reports/advisor_summary.php?ay=' . $ayId, full_name($teacher) => null];
$activeMenu = $isOwn && !can_view_advisor_overview($user) ? 'teacher/advisor_reports.php' : 'reports/advisor_summary.php';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> พิมพ์</button>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4 d-print-none">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="teacher" value="<?= $teacherId ?>">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $ayId === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-9 small">
                <span class="fw-semibold"><?= e(full_name($teacher)) ?></span> · <?= e($teacher['department_name'] ?? '-') ?>
                <?php if (!$isOwn): ?> · <i class="bi bi-envelope"></i> <?= e($teacher['email']) ?> · <i class="bi bi-telephone"></i> <?= e($teacher['phone'] ?: '-') ?><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (!$groups): ?>
    <div class="alert alert-info">ครูท่านนี้ไม่ได้เป็นครูที่ปรึกษาในภาคเรียน <?= e(academic_label($ay)) ?></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ([['expected', 'ต้องส่ง', 'ic-navy', 'bi-calendar-week'], ['submitted', 'ส่งแล้ว', 'ic-blue', 'bi-send-check'],
                        ['on_time', 'ตรงเวลา', 'ic-green', 'bi-check2-circle'], ['late', 'ส่งช้า', 'ic-orange', 'bi-clock-history'],
                        ['missing', 'ขาดส่ง', 'ic-red', 'bi-dash-circle']] as [$k, $label, $ic, $icon]): ?>
            <div class="col-6 col-md">
                <div class="stat-card">
                    <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                    <div><div class="stat-value"><?= (int)($t[$k] ?? 0) ?></div><div class="stat-label"><?= e($label) ?> (สัปดาห์-กลุ่ม)</div></div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-6 col-md">
            <div class="stat-card">
                <div class="stat-icon ic-purple" style="width:40px;height:40px;font-size:1.2rem"><i class="bi bi-percent"></i></div>
                <div><div class="stat-value"><?= $t['submit_pct'] ?? 0 ?>%</div><div class="stat-label">ร้อยละการส่ง</div></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-calendar-week me-2"></i>การส่งรายงานรายสัปดาห์ ภาคเรียน <?= e(academic_label($ay)) ?></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>สัปดาห์</th><th>วันพุธ</th>
                                <?php foreach ($groups as $g): ?><th><?= e($g['group_name']) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                            <?php if (!$weds): ?>
                                <tr><td colspan="<?= count($groups) + 2 ?>" class="text-center text-muted py-3">ยังไม่ถึงวันพุธแรกของภาคเรียน</td></tr>
                            <?php endif; ?>
                            <?php $i = 0; foreach ($weds as $d => $skip): $i++; ?>
                                <tr>
                                    <td class="text-muted small"><?= $i ?></td>
                                    <td class="text-nowrap"><?= e(advisor_date_label($d)) ?></td>
                                    <?php if ($skip !== null): ?>
                                        <td colspan="<?= count($groups) ?>"><span class="badge bg-secondary">งดพบ</span> <span class="small text-muted"><?= e($skip) ?></span></td>
                                    <?php else: ?>
                                        <?php foreach ($groups as $g): $r = $reports[(int)$g['id']][$d] ?? null; ?>
                                            <td>
                                                <?php if ($r): ?>
                                                    <a href="<?= e(url($reportLink((int)$r['id'], $teacherId))) ?>" class="text-decoration-none"><?= status_badge($r['status'], (bool)$r['is_late']) ?></a>
                                                    <span class="small text-muted ms-1"><?= (int)$r['present_count'] + (int)$r['late_count'] ?>/<?= (int)$r['total_students'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-75"><i class="bi bi-x-circle me-1"></i>ขาดส่ง</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-people me-2"></i>กลุ่มที่ปรึกษา</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($groups as $g): ?>
                        <li class="list-group-item d-flex justify-content-between"><span><?= e($g['group_name']) ?></span>
                            <span class="small text-muted"><?= (int)$g['student_count'] ?> คน · <?= e(preg_replace('/^แผนกวิชา/u', '', (string)($g['department_name'] ?? '-'))) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>สัปดาห์ที่ขาดส่ง (<?= count($missing) ?>)</div>
                <div class="card-body small">
                    <?php if (!$missing): ?>
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>ส่งครบทุกสัปดาห์</span>
                    <?php else: ?>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($missing as [$g, $d]): ?>
                                <li><?= e(advisor_date_label($d)) ?><?= count($groups) > 1 ? ' · ' . e($g['group_name']) : '' ?>
                                    <?php if ($isOwn): ?><a href="<?= e(url('teacher/advisor_reports.php?group=' . (int)$g['id'] . '&date=' . $d)) ?>" class="ms-1">ส่งรายงาน</a><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($t && $t['students'] > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-person-check me-2"></i>การเข้าพบของนักเรียน</div>
                    <div class="card-body small">
                        <div class="fs-4 fw-bold text-primary-th"><?= $t['attend_pct'] ?>%</div>
                        <div class="text-muted">มา <?= $t['present'] ?> · มาสาย <?= $t['late_students'] ?> · ลา <?= $t['leave'] ?> · ขาด <?= $t['absent'] ?> (คน-ครั้ง)</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<div class="d-none d-print-block small mt-4">ผู้ออกรายงาน: <?= e(full_name($user)) ?> · ระบบ <?= e(system_name()) ?> · <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
