<?php
/**
 * ไฟล์: reports/home_visit_teacher.php
 * สรุปการเยี่ยมบ้านรายครูที่ปรึกษา: เยี่ยมนักเรียนคนใดแล้ว ผลการคัดกรอง สถานะการตรวจ
 *   ?teacher= ครู, ?year= ปีการศึกษา, ?visit= ดูรายงาน 1 ฉบับ (อ่านอย่างเดียว)
 * สิทธิ์: ครูเจ้าของ, admin, ผู้บริหาร, งานครูที่ปรึกษาฯ, หัวหน้าแผนก (ครูในแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];

/** ลิงก์ไปหน้ารายงาน 1 ฉบับที่เหมาะกับผู้ดู */
$visitLink = function (int $visitId, int $teacherId) use ($user, $uid): string {
    if ($teacherId === $uid) {
        return 'teacher/home_visits.php?id=' . $visitId;
    }
    if (can_manage_advisor($user)) {
        return 'review/home_visits.php?id=' . $visitId;
    }
    return 'reports/home_visit_teacher.php?visit=' . $visitId;
};

$visitId = get_int('visit');
if ($visitId > 0) {
    $hv = home_visit_fetch($visitId);
    if (!$hv || !can_view_advisor_teacher($user, (int)$hv['teacher_id'])) {
        abort_page(404, 'ไม่พบรายงาน');
    }
    $pageTitle = 'รายงานการเยี่ยมบ้าน';
    $pageIcon = 'bi-house-heart';
    $breadcrumbs = ['สรุปการเยี่ยมบ้าน' => 'reports/home_visit_summary.php?year=' . (int)$hv['school_year'],
        $hv['teacher_name'] => 'reports/home_visit_teacher.php?teacher=' . (int)$hv['teacher_id'] . '&year=' . (int)$hv['school_year'],
        $hv['student_name'] => null];
    $activeMenu = 'reports/home_visit_summary.php';
    require APP_ROOT . '/includes/header.php';
    require APP_ROOT . '/includes/partials/home_visit_detail.php';
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
$years = home_visit_years();
$year = home_visit_year(get_int('year'));
$sum = home_visit_summary($year, 0, $teacherId);
$t = $sum['teachers'][$teacherId] ?? null;
$rows = db_all(
    'SELECT v.id, v.student_code, v.student_name, v.visit_date, v.risk_level, v.status, g.group_name
     FROM home_visits v JOIN advisor_groups g ON g.id = v.group_id
     WHERE v.teacher_id = ? AND v.school_year = ? ORDER BY g.group_name, v.student_code',
    'ii',
    [$teacherId, $year]
);

$isOwn = $teacherId === $uid;
$ownOnly = $isOwn && !can_view_advisor_overview($user);
$pageTitle = 'สรุปการเยี่ยมบ้าน: ' . full_name($teacher);
$pageIcon = 'bi-person-badge';
$breadcrumbs = $ownOnly
    ? ['รายงานการเยี่ยมบ้าน' => 'teacher/home_visits.php?year=' . $year, 'สรุปของฉัน' => null]
    : ['สรุปการเยี่ยมบ้าน' => 'reports/home_visit_summary.php?year=' . $year, full_name($teacher) => null];
$activeMenu = $ownOnly ? 'teacher/home_visits.php' : 'reports/home_visit_summary.php';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> พิมพ์</button>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4 d-print-none">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="teacher" value="<?= $teacherId ?>">
            <div class="col-md-2">
                <label class="form-label small mb-1" for="year">ปีการศึกษา</label>
                <select name="year" id="year" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-10 small">
                <span class="fw-semibold"><?= e(full_name($teacher)) ?></span> · <?= e($teacher['department_name'] ?? '-') ?>
                <?php if ($t): ?> · กลุ่มที่ปรึกษา: <?= e(implode(', ', $t['group_names'])) ?><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (!$t): ?>
    <div class="alert alert-info">ครูท่านนี้ไม่ได้เป็นครูที่ปรึกษาในปีการศึกษา <?= $year ?></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ([[$t['visits'] . '/' . $t['students'], 'เยี่ยมบ้านแล้ว (คน)', 'ic-blue', 'bi-house-check'],
                        [$t['visit_pct'] . '%', 'ร้อยละ', 'ic-navy', 'bi-percent'],
                        [$t['normal'], 'ปกติ', 'ic-green', 'bi-emoji-smile'],
                        [$t['risk'], 'กลุ่มเสี่ยง', 'ic-yellow', 'bi-exclamation-triangle'],
                        [$t['problem'], 'กลุ่มมีปัญหา', 'ic-red', 'bi-exclamation-octagon'],
                        [$t['approved'] . '/' . $t['pending'] . '/' . $t['revision'], 'ผ่าน/รอตรวจ/แก้ไข', 'ic-orange', 'bi-clipboard-check']] as [$val, $label, $ic, $icon]): ?>
            <div class="col-6 col-md">
                <div class="stat-card">
                    <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                    <div><div class="stat-value" style="font-size:1.3rem"><?= e((string)$val) ?></div><div class="stat-label"><?= e($label) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-list-ul me-2"></i>นักเรียนที่เยี่ยมบ้านแล้ว ปีการศึกษา <?= $year ?> (<?= count($rows) ?> คน)</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover datatable" data-order="[]">
                    <thead><tr><th>กลุ่ม</th><th>รหัส</th><th>ชื่อ-สกุล</th><th>วันที่เยี่ยม</th><th>ผลคัดกรอง</th><th>สถานะ</th><th class="no-sort"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="small"><?= e($r['group_name']) ?></td>
                            <td><?= e($r['student_code']) ?></td>
                            <td><?= e($r['student_name']) ?></td>
                            <td data-order="<?= e($r['visit_date']) ?>"><?= e(thai_date($r['visit_date'])) ?></td>
                            <td data-order="<?= e($r['risk_level']) ?>"><?= home_visit_risk_badge($r['risk_level']) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td class="text-end"><a href="<?= e(url($visitLink((int)$r['id'], $teacherId))) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="d-none d-print-block small mt-4">ผู้ออกรายงาน: <?= e(full_name($user)) ?> · ระบบ <?= e(system_name()) ?> · <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
