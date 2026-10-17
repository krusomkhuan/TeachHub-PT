<?php
/**
 * ไฟล์: reports/supervision_reports.php
 * รายงานการนิเทศครูผู้สอนจากหัวหน้าแผนกวิชา — สำหรับผู้พิจารณา/ผู้บริหาร/admin
 *   ?tab=mine (ค่าเริ่มต้นของผู้พิจารณา) : รายงานที่รอท่านพิจารณา
 *   ?tab=all                            : รายงานทั้งหมดที่เสนอแล้ว (กรองภาคเรียน/สถานะ/แผนก)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin', 'executive', 'supervisor');
if ($user['role'] === 'supervisor') {
    redirect('supervisor/supervision_reports.php');
}
$uid = (int)$user['id'];
$mySteps = report_user_steps($uid);
$tab = get('tab') ?: ($mySteps ? 'mine' : 'all');
$chain = report_approval_chain();

$fAy = get_int('ay', (int)(current_academic_year()['id'] ?? 0));
$fStatus = get('status');
$fDept = get_int('dept');

if ($tab === 'mine') {
    $in = $mySteps ? implode(',', array_fill(0, count($mySteps), '?')) : '0';
    $rows = db_all(
        "SELECT r.*, d.name AS department_name, ay.year, ay.semester, CONCAT(a.prefix, a.first_name, ' ', a.last_name) AS author_name
         FROM supervision_reports r JOIN departments d ON d.id = r.department_id
         JOIN academic_years ay ON ay.id = r.academic_year_id JOIN users a ON a.id = r.author_id
         WHERE r.status = 'pending' AND r.current_step IN ($in) ORDER BY r.submitted_at",
        str_repeat('i', count($mySteps)),
        $mySteps
    );
} else {
    $where = ["r.submitted_at IS NOT NULL", '(? = 0 OR r.academic_year_id = ?)', '(? = 0 OR r.department_id = ?)'];
    $types = 'iiii';
    $params = [$fAy, $fAy, $fDept, $fDept];
    if (in_array($fStatus, ['pending', 'returned', 'approved'], true)) {
        $where[] = 'r.status = ?';
        $types .= 's';
        $params[] = $fStatus;
    }
    $rows = db_all(
        "SELECT r.*, d.name AS department_name, ay.year, ay.semester, CONCAT(a.prefix, a.first_name, ' ', a.last_name) AS author_name
         FROM supervision_reports r JOIN departments d ON d.id = r.department_id
         JOIN academic_years ay ON ay.id = r.academic_year_id JOIN users a ON a.id = r.author_id
         WHERE " . implode(' AND ', $where) . ' ORDER BY r.submitted_at DESC',
        $types,
        $params
    );
}
$pendingMine = report_pending_count($uid);
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$departments = db_all('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name');

// แผนกที่ยังไม่ส่งรายงานในภาคเรียนที่เลือก (ช่วยติดตาม)
$missing = $tab === 'all' && $fAy ? db_all(
    "SELECT d.name FROM departments d
     WHERE d.is_active = 1 AND EXISTS (SELECT 1 FROM users u WHERE u.department_id = d.id AND u.role = 'supervisor' AND u.status = 'active')
       AND NOT EXISTS (SELECT 1 FROM supervision_reports r WHERE r.department_id = d.id AND r.academic_year_id = ? AND r.submitted_at IS NOT NULL)
     ORDER BY d.name",
    'i',
    [$fAy]
) : [];

$pageTitle = 'รายงานการนิเทศจากหัวหน้าแผนก';
$pageIcon = 'bi-file-earmark-check';
$breadcrumbs = ['รายงานการนิเทศ' => null];
$activeMenu = 'reports/supervision_reports.php';
require APP_ROOT . '/includes/header.php';
?>
<?php if (!report_chain_ready() && $user['role'] === 'admin'): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>ยังกำหนดผู้พิจารณาไม่ครบ 3 ขั้น หัวหน้าแผนกจะเสนอรายงานไม่ได้
        — <a href="<?= e(url('admin/settings.php')) ?>#approvers">กำหนดที่หน้าตั้งค่าระบบ</a></div>
<?php endif; ?>
<?php if ($mySteps): ?>
    <div class="alert alert-light border small"><i class="bi bi-person-badge me-1 text-accent"></i>ท่านเป็นผู้พิจารณา:
        <?= e(implode(', ', array_map(fn($s) => 'ขั้นที่ ' . $s . ' ' . $chain[$s]['title'], $mySteps))) ?></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3 gap-1">
    <?php if ($mySteps): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === 'mine' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?tab=mine">
            <i class="bi bi-inbox me-1"></i>รอท่านพิจารณา <?php if ($pendingMine): ?><span class="badge bg-danger ms-1"><?= $pendingMine ?></span><?php endif; ?></a></li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link <?= $tab === 'all' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?tab=all">
        <i class="bi bi-collection me-1"></i>รายงานทั้งหมด</a></li>
</ul>

<?php if ($tab === 'all'): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="all">
                <div class="col-md-3"><label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                    <select name="ay" id="ay" class="form-select"><option value="0">ทั้งหมด</option>
                        <?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= $fAy === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label small mb-1" for="status">สถานะ</label>
                    <select name="status" id="status" class="form-select"><option value="">ทั้งหมด</option>
                        <option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>อยู่ระหว่างพิจารณา</option>
                        <option value="returned" <?= $fStatus === 'returned' ? 'selected' : '' ?>>ส่งกลับแก้ไข</option>
                        <option value="approved" <?= $fStatus === 'approved' ? 'selected' : '' ?>>ผู้อำนวยการลงนามแล้ว</option></select></div>
                <div class="col-md-4"><label class="form-label small mb-1" for="dept">แผนก</label>
                    <select name="dept" id="dept" class="form-select"><option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> กรอง</button></div>
            </form>
            <?php if ($missing): ?>
                <div class="small text-danger mt-3"><i class="bi bi-exclamation-circle me-1"></i>แผนกที่ยังไม่เสนอรายงานภาคเรียนนี้ (<?= count($missing) ?>):
                    <?= e(implode(', ', array_map(fn($d) => preg_replace('/^แผนกวิชา/u', '', $d['name']), $missing))) ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>เรื่อง</th><th>แผนก</th><th>ภาคเรียน</th><th>ผู้จัดทำ</th><th>สถานะ</th><th>เสนอเมื่อ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $mine = is_current_report_approver($user, $r); ?>
                    <tr class="<?= $mine ? 'table-warning' : '' ?>">
                        <td><?= e($r['title']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', $r['department_name'])) ?></td>
                        <td><?= e($r['semester'] . '/' . $r['year']) ?></td>
                        <td class="small"><?= e($r['author_name']) ?></td>
                        <td><?= report_status_badge($r) ?></td>
                        <td class="small" data-order="<?= e((string)$r['submitted_at']) ?>"><?= e(thai_datetime($r['submitted_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url('reports/supervision_report.php?id=' . (int)$r['id'])) ?>" class="btn btn-sm <?= $mine ? 'btn-accent' : 'btn-outline-primary' ?>">
                                <i class="bi <?= $mine ? 'bi-pen' : 'bi-eye' ?>"></i> <?= $mine ? 'พิจารณา' : 'ดู' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
