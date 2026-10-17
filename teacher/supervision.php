<?php
/**
 * ไฟล์: teacher/supervision.php
 * ผลการนิเทศของฉัน (ครู / หัวหน้าแผนกในฐานะผู้รับการนิเทศ) แบ่ง 2 รูปแบบ
 *   1) นิเทศโดยหัวหน้าแผนก : ดูตาราง/ผล กด "รับทราบผล" พิมพ์แบบบันทึก PDF   (?view=id)
 *   2) ประเมินโดยนักเรียน    : ดู QR Code ของตนเองเพื่อให้นักเรียนสแกน และดูผลสรุป (?tab=student, ?student=id)
 *      ครูสร้างแบบประเมินเองไม่ได้ (admin เป็นผู้สร้าง) และเห็นเฉพาะรายการของตนเอง
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$activeMenu = 'teacher/supervision.php';

// ---------- กดรับทราบผลการนิเทศ ----------
if (is_post()) {
    csrf_verify();
    $sc = schedule_fetch((int)post('schedule_id'));
    if (!$sc || (int)$sc['teacher_id'] !== $uid || !$sc['result_id']) {
        abort_page(404, 'ไม่พบผลการนิเทศ');
    }
    if (!$sc['acknowledged_at']) {
        db_exec('UPDATE supervision_results SET acknowledged_at = NOW() WHERE id = ? AND acknowledged_at IS NULL', 'i', [(int)$sc['result_id']]);
        log_activity('supervision_ack', 'รับทราบผลการนิเทศ #' . (int)$sc['id']);
        flash('success', 'รับทราบผลการนิเทศเรียบร้อยแล้ว');
    }
    redirect('teacher/supervision.php?view=' . (int)$sc['id']);
}

// ---------- ผลการประเมินโดยนักเรียน (รายการของตนเองเท่านั้น) ----------
if (get_int('student') > 0) {
    $se = student_eval_fetch(get_int('student'));
    if (!$se || (int)$se['teacher_id'] !== $uid) {
        abort_page(404, 'ไม่พบแบบประเมิน', 'รายการนี้ไม่มีอยู่ หรือไม่ใช่ของท่าน');
    }
    $sum = student_eval_summary([(int)$se['id']]);
    $useQr = true;
    $pageTitle = 'ผลการประเมินโดยนักเรียน';
    $pageIcon = 'bi-people';
    $breadcrumbs = ['ผลการนิเทศของฉัน' => 'teacher/supervision.php?tab=student', $se['subject_name'] => null];
    $pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์ผล</button>';
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex"><i class="bi bi-info-circle me-2"></i>ข้อมูลการประเมิน <span class="ms-auto"><?= student_eval_state_badge($se) ?></span></div>
                <div class="card-body small row g-3">
                    <div class="col-md-6"><div class="text-muted">รายวิชา / ห้อง</div><div class="fw-semibold fs-6"><?= e($se['subject_name']) ?></div><?= e($se['classroom'] ?: '-') ?></div>
                    <div class="col-md-6"><div class="text-muted">ภาคเรียน</div><?= $se['ay_year'] ? e($se['ay_semester'] . '/' . $se['ay_year']) : '-' ?></div>
                    <div class="col-md-6"><div class="text-muted">ช่วงเวลาประเมิน</div><?= e(thai_datetime($se['open_at'])) ?> –<br><?= e(thai_datetime($se['close_at'])) ?></div>
                    <div class="col-md-6"><div class="text-muted">นักเรียนตอบแล้ว</div><span class="fs-4 fw-bold"><?= (int)$se['responses'] ?></span><?= (int)$se['max_responses'] > 0 ? ' / ' . (int)$se['max_responses'] : '' ?> คน</div>
                    <div class="col-12 text-muted"><i class="bi bi-shield-check text-success me-1"></i>ผลเป็นแบบไม่ระบุตัวนักเรียน · ครูไม่สามารถประเมินตนเองได้</div>
                </div>
            </div>
        </div>
        <div class="col-lg-5 no-print"><?php require APP_ROOT . '/includes/partials/student_eval_qr_card.php'; ?></div>
    </div>
    <?php
    require APP_ROOT . '/includes/partials/student_eval_result.php';
    echo '<a href="' . e(url('teacher/supervision.php?tab=student')) . '" class="btn btn-outline-secondary no-print"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ---------- ดูผล ----------
if (get_int('view') > 0) {
    $sc = schedule_fetch(get_int('view'));
    if (!$sc || (int)$sc['teacher_id'] !== $uid) {
        abort_page(404, 'ไม่พบตารางนิเทศ', 'รายการนี้ไม่มีอยู่ หรือไม่ใช่การนิเทศของท่าน');
    }
    $pageTitle = 'ผลการนิเทศของฉัน';
    $pageIcon = 'bi-person-video3';
    $breadcrumbs = ['ผลการนิเทศของฉัน' => 'teacher/supervision.php', thai_date($sc['supervise_date']) => null];
    $pageActions = $sc['result_id']
        ? '<a href="' . e(url('reports/supervision_print.php?id=' . (int)$sc['id'])) . '" target="_blank" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i>พิมพ์ / บันทึก PDF</a>'
        : '';
    require APP_ROOT . '/includes/header.php';

    if ($sc['result_id'] && !$sc['acknowledged_at']): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-bell-fill fs-5"></i>
            <span>กรุณาอ่านผลการนิเทศด้านล่าง แล้วกด "รับทราบผลการนิเทศ"</span>
            <form method="post" class="ms-auto" data-confirm="ยืนยันว่าท่านได้อ่านและรับทราบผลการนิเทศแล้ว?">
                <?= csrf_field() ?>
                <input type="hidden" name="schedule_id" value="<?= (int)$sc['id'] ?>">
                <button class="btn btn-warning"><i class="bi bi-check2-square me-1"></i> รับทราบผลการนิเทศ</button>
            </form>
        </div>
    <?php endif;

    require APP_ROOT . '/includes/partials/supervision_result.php';
    echo '<a href="' . e(url('teacher/supervision.php')) . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ---------- รายการ ----------
$rows = db_all(
    "SELECT sc.*, CONCAT(s.prefix, s.first_name, ' ', s.last_name) AS supervisor_name, ay.year, ay.semester,
            res.id AS result_id, res.avg_score, res.acknowledged_at
     FROM supervision_schedules sc
     JOIN users s ON s.id = sc.supervisor_id
     LEFT JOIN academic_years ay ON ay.id = sc.academic_year_id
     LEFT JOIN supervision_results res ON res.schedule_id = sc.id
     WHERE sc.teacher_id = ? AND sc.status <> 'cancelled'
     ORDER BY sc.supervise_date DESC, sc.start_time DESC",
    'i',
    [$uid]
);
$done = array_filter($rows, fn($r) => $r['result_id']);
$avgAll = $done ? array_sum(array_column($done, 'avg_score')) / count($done) : null;
$upcoming = array_filter($rows, fn($r) => !$r['result_id'] && $r['supervise_date'] >= date('Y-m-d'));
$unack = array_filter($done, fn($r) => !$r['acknowledged_at']);

$tab = get('tab') === 'student' ? 'student' : 'supervisor';
$myEvals = db_all(
    "SELECT s.*, ay.year, ay.semester,
            (SELECT COUNT(*) FROM student_eval_responses r WHERE r.session_id = s.id) AS responses,
            (SELECT ROUND(AVG(r.avg_score), 2) FROM student_eval_responses r WHERE r.session_id = s.id) AS avg_score
     FROM student_eval_sessions s LEFT JOIN academic_years ay ON ay.id = s.academic_year_id
     WHERE s.teacher_id = ? ORDER BY s.close_at DESC",
    'i',
    [$uid]
);

$pageTitle = 'ผลการนิเทศของฉัน';
$pageIcon = 'bi-person-video3';
$breadcrumbs = ['ผลการนิเทศของฉัน' => null];
require APP_ROOT . '/includes/header.php';
?>
<ul class="nav nav-pills mb-4 gap-1">
    <li class="nav-item"><a class="nav-link <?= $tab === 'supervisor' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?tab=supervisor">
        <i class="bi bi-person-video3 me-1"></i>1. นิเทศโดยหัวหน้าแผนก <span class="badge bg-light text-dark ms-1"><?= count($rows) ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'student' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?tab=student">
        <i class="bi bi-people me-1"></i>2. ประเมินโดยนักเรียน นักศึกษา <span class="badge bg-light text-dark ms-1"><?= count($myEvals) ?></span></a></li>
</ul>

<?php if ($tab === 'student'):
    $stuSum = student_eval_summary(array_column($myEvals, 'id'), false); ?>
    <div class="row g-3 mb-4">
        <div class="col-sm-4"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-qr-code"></i></div><div><div class="stat-value"><?= count($myEvals) ?></div><div class="stat-label">แบบประเมินของฉัน</div></div></div></div>
        <div class="col-sm-4"><div class="stat-card"><div class="stat-icon ic-orange"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= $stuSum['responses'] ?></div><div class="stat-label">นักเรียนตอบแล้ว (คน)</div></div></div></div>
        <div class="col-sm-4"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-star"></i></div><div><div class="stat-value"><?= $stuSum['avg'] !== null ? number_format($stuSum['avg'], 2) : '-' ?></div><div class="stat-label">คะแนนเฉลี่ยจากนักเรียน</div><div class="stat-sub"><?= $stuSum['avg'] !== null ? e(quality_level($stuSum['avg'])) : '' ?></div></div></div></div>
    </div>
    <div class="card">
        <div class="card-header"><i class="bi bi-list-ul me-2"></i>แบบประเมินโดยนักเรียนของฉัน
            <span class="small text-muted fw-normal ms-2">(ผู้ดูแลระบบเป็นผู้สร้าง · กด “QR / ผล” เพื่อเปิด QR Code ให้นักเรียนสแกน)</span></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable" data-order="[]">
                    <thead><tr><th>วิชา / ห้อง</th><th>ภาคเรียน</th><th>ช่วงเวลา</th><th>สถานะ</th><th>ผู้ตอบ</th><th>คะแนนเฉลี่ย</th><th class="no-sort"></th></tr></thead>
                    <tbody>
                    <?php foreach ($myEvals as $r): ?>
                        <tr>
                            <td><?= e($r['subject_name']) ?><br><small class="text-muted"><?= e($r['classroom']) ?></small></td>
                            <td><?= $r['year'] ? e($r['semester'] . '/' . $r['year']) : '-' ?></td>
                            <td class="small text-nowrap" data-order="<?= e($r['close_at']) ?>"><?= e(thai_datetime($r['open_at'])) ?><br>ถึง <?= e(thai_datetime($r['close_at'])) ?></td>
                            <td><?= student_eval_state_badge($r) ?></td>
                            <td><?= (int)$r['responses'] ?><?= (int)$r['max_responses'] > 0 ? '/' . (int)$r['max_responses'] : '' ?></td>
                            <td data-order="<?= e((string)$r['avg_score']) ?>"><?= $r['avg_score'] !== null ? quality_badge((float)$r['avg_score']) : '<span class="text-muted">-</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= e(url('teacher/supervision.php?student=' . (int)$r['id'])) ?>" class="btn btn-sm btn-accent"><i class="bi bi-qr-code"></i> QR / ผล</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php require APP_ROOT . '/includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-blue"><i class="bi bi-calendar-week"></i></div><div><div class="stat-value"><?= count($upcoming) ?></div><div class="stat-label">ตารางนิเทศที่จะถึง</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= count($done) ?></div><div class="stat-label">ได้รับการนิเทศแล้ว</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-star"></i></div><div><div class="stat-value"><?= $avgAll !== null ? number_format($avgAll, 2) : '-' ?></div><div class="stat-label">คะแนนเฉลี่ยรวม</div><div class="stat-sub"><?= $avgAll !== null ? e(quality_level($avgAll)) : '' ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-yellow"><i class="bi bi-bell"></i></div><div><div class="stat-value"><?= count($unack) ?></div><div class="stat-label">รอกดรับทราบ</div></div></div></div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2"></i>ตารางและผลการนิเทศ</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>วันเวลา</th><th>ภาคเรียน</th><th>วิชา/ห้อง</th><th>ผู้นิเทศ</th><th>ผลการนิเทศ</th><th>การรับทราบ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['result_id'] && !$r['acknowledged_at'] ? 'table-warning' : '' ?>">
                        <td data-order="<?= e($r['supervise_date']) ?>" class="text-nowrap"><?= e(thai_date($r['supervise_date'])) ?><br><small class="text-muted"><?= e(substr($r['start_time'], 0, 5)) ?>–<?= e(substr($r['end_time'], 0, 5)) ?> น.</small></td>
                        <td><?= $r['year'] ? e($r['semester'] . '/' . $r['year']) : '-' ?></td>
                        <td class="small"><?= e($r['subject_name']) ?><br><span class="text-muted"><?= e($r['classroom']) ?></span></td>
                        <td class="small"><?= e($r['supervisor_name']) ?></td>
                        <td><?= $r['result_id'] ? quality_badge((float)$r['avg_score']) : schedule_status_badge($r['status']) ?></td>
                        <td class="small">
                            <?php if (!$r['result_id']): ?>-
                            <?php elseif ($r['acknowledged_at']): ?><span class="text-success"><i class="bi bi-check2"></i> <?= e(thai_date($r['acknowledged_at'])) ?></span>
                            <?php else: ?><span class="text-warning">ยังไม่รับทราบ</span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url('teacher/supervision.php?view=' . (int)$r['id'])) ?>" class="btn btn-sm <?= $r['result_id'] && !$r['acknowledged_at'] ? 'btn-warning' : 'btn-outline-primary' ?>"><i class="bi bi-eye"></i> ดู</a>
                            <?php if ($r['result_id']): ?>
                                <a href="<?= e(url('reports/supervision_print.php?id=' . (int)$r['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="พิมพ์"><i class="bi bi-printer"></i></a>
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
