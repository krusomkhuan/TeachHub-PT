<?php
/**
 * ไฟล์: hr/trainings_review.php
 * ตรวจรายการพัฒนาตนเอง/อบรมของบุคลากร (ผู้ดูแลงานบุคลากร) — ผ่าน / ให้แก้ไข และปรับจำนวนชั่วโมงได้
 *   ?fy= ?status= ?dept=   ตัวกรอง
 * ผู้ตรวจตรวจรายการของตนเองไม่ได้ (ยกเว้นผู้ดูแลระบบเต็มสิทธิ์)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_hr($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลงานบุคลากร');
}
$uid = (int)$user['id'];
$self = 'hr/trainings_review.php';
$activeMenu = $self;
$fullAdmin = is_full_admin($user);

if (is_post()) {
    csrf_verify();
    $t = db_one("SELECT t.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name FROM hr_trainings t JOIN users u ON u.id = t.user_id WHERE t.id = ?",
        'i', [(int)post('id')]);
    if (!$t || ((int)$t['user_id'] === $uid && !$fullAdmin)) {
        abort_page(403, 'ไม่สามารถตรวจรายการนี้ได้');
    }
    $result = post('result');
    $comment = mb_substr(post('comment'), 0, 1000);
    $hours = round((float)post('hours'), 1);
    if (!in_array($result, ['approved', 'revision'], true) || ($result === 'revision' && $comment === '') || $hours <= 0 || $hours > 1000) {
        flash('error', $result === 'revision' && $comment === '' ? 'กรุณาระบุสิ่งที่ต้องแก้ไข' : 'ข้อมูลผลการตรวจไม่ถูกต้อง');
    } else {
        db_exec('UPDATE hr_trainings SET status = ?, review_comment = ?, hours = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            'ssdii', [$result, $comment, $hours, $uid, (int)$t['id']]);
        log_activity('hr_training_review', status_labels()[$result] . ': การอบรม "' . $t['title'] . '" ของ ' . $t['user_name']
            . ($hours !== (float)$t['hours'] ? ' (ปรับชั่วโมง ' . hr_days((float)$t['hours']) . ' → ' . hr_days($hours) . ')' : ''));
        flash('success', 'บันทึกผลการตรวจ "' . status_labels()[$result] . '" แล้ว');
    }
    redirect($self . '?' . http_build_query(['fy' => post('fy'), 'status' => post('status_filter'), 'dept' => post('dept')]));
}

$fy = hr_fiscal_param();
$fStatus = in_array(get('status', 'pending'), ['', 'pending', 'approved', 'revision'], true) ? get('status', 'pending') : 'pending';
$fDept = get_int('dept');
$where = ['t.fiscal_year = ?'];
$types = 'i';
$params = [$fy];
if ($fStatus !== '') {
    $where[] = 't.status = ?';
    $types .= 's';
    $params[] = $fStatus;
}
if ($fDept > 0) {
    $where[] = 'u.department_id = ?';
    $types .= 'i';
    $params[] = $fDept;
}
$rows = db_all(
    "SELECT t.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name, d.name AS department_name
     FROM hr_trainings t JOIN users u ON u.id = t.user_id LEFT JOIN departments d ON d.id = u.department_id
     WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(t.status, 'pending', 'revision', 'approved'), t.created_at",
    $types,
    $params
);
$departments = db_all('SELECT id, name FROM departments ORDER BY name');

$pageTitle = 'ตรวจการพัฒนาตนเอง/อบรม';
$pageIcon = 'bi-award';
$breadcrumbs = ['ตรวจการพัฒนาตนเอง/อบรม' => null];
$pageActions = '<a href="' . e(url('hr/reports.php?tab=training&fy=' . $fy)) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> สรุปชั่วโมงพัฒนา</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select"><?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small mb-1" for="status">สถานะ</label>
                <select name="status" id="status" class="form-select">
                    <?php foreach (['pending' => 'รอตรวจ', 'revision' => 'ให้แก้ไข', 'approved' => 'ผ่าน', '' => 'ทั้งหมด'] as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-5"><label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                <select name="dept" id="dept" class="form-select"><option value="0">ทุกแผนก/งาน</option>
                    <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> กรอง</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" data-order="[]">
                <thead><tr><th>บุคลากร</th><th>เรื่อง/หลักสูตร</th><th>วันที่</th><th>หลักฐาน</th><th>สถานะ</th><th class="no-sort" style="min-width:340px">ผลการตรวจ</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $mine = (int)$r['user_id'] === $uid && !$fullAdmin; ?>
                    <tr>
                        <td><?= e($r['user_name']) ?><div class="small text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></div></td>
                        <td><?= e($r['title']) ?><div class="small text-muted"><?= e($r['category']) ?> · <?= e($r['organizer'] ?: '-') ?></div>
                            <?php if ($r['detail'] !== ''): ?><div class="small"><?= e(str_limit($r['detail'], 120)) ?></div><?php endif; ?></td>
                        <td class="text-nowrap small" data-order="<?= e($r['start_date']) ?>"><?= e(thai_date($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? '<br>– ' . e(thai_date($r['end_date'])) : '' ?></td>
                        <td><?php if ($r['cert_stored']): ?><a href="<?= e(hr_file_url('training', (int)$r['id'])) ?>" target="_blank" rel="noopener"><i class="bi <?= e(file_icon($r['cert_original'])) ?> fs-5"></i></a><?php else: ?><span class="text-muted small">ไม่มี</span><?php endif; ?></td>
                        <td data-order="<?= e($r['status']) ?>"><?= status_badge($r['status']) ?></td>
                        <td>
                            <?php if ($mine): ?>
                                <span class="small text-muted">รายการของท่านเอง (ตรวจโดยผู้ดูแลงานบุคลากรท่านอื่น)</span>
                            <?php else: ?>
                                <form method="post" class="d-flex flex-wrap gap-1 align-items-center">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="fy" value="<?= $fy ?>"><input type="hidden" name="status_filter" value="<?= e($fStatus) ?>"><input type="hidden" name="dept" value="<?= $fDept ?>">
                                    <input type="number" name="hours" class="form-control form-control-sm" style="width:80px" step="0.5" min="0.5" max="1000" value="<?= hr_days((float)$r['hours']) ?>" title="ชั่วโมง">
                                    <input name="comment" class="form-control form-control-sm flex-fill" style="min-width:120px" maxlength="1000" placeholder="ความเห็น" value="<?= e($r['review_comment']) ?>">
                                    <button name="result" value="approved" class="btn btn-sm btn-success" title="ผ่าน"><i class="bi bi-check-lg"></i></button>
                                    <button name="result" value="revision" class="btn btn-sm btn-outline-danger" title="ให้แก้ไข"><i class="bi bi-arrow-counterclockwise"></i></button>
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
<?php require APP_ROOT . '/includes/footer.php'; ?>
