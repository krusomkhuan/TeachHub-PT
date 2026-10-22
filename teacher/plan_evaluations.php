<?php
/**
 * ไฟล์: teacher/plan_evaluations.php
 * ผลการประเมินแผนการจัดการเรียนรู้ของฉัน (ครู และหัวหน้าแผนกที่ส่งแผนของตนเอง)
 *   (ไม่มีพารามิเตอร์)  รายการแผนที่ส่งและผลการประเมิน
 *   ?view=lesson_plan_id  ดูผลการประเมิน + กดรับทราบผล
 * ครูเห็นผลเมื่อผู้ประเมินกด "ส่งผลการประเมิน" แล้วเท่านั้น (ผลที่ยังเป็นร่างไม่แสดง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$self = 'teacher/plan_evaluations.php';

// ---------- รับทราบผลการประเมิน (เฉพาะแผนของตนเอง) ----------
if (is_post()) {
    csrf_verify();
    $plan = plan_eval_fetch_plan((int)post('plan_id'));
    if (!$plan || (int)$plan['teacher_id'] !== $uid || !$plan['eval_id']) {
        abort_page(404, 'ไม่พบผลการประเมิน');
    }
    if (db_exec("UPDATE plan_evaluations SET acknowledged_at = NOW() WHERE id = ? AND status = 'final' AND acknowledged_at IS NULL", 'i', [(int)$plan['eval_id']])) {
        log_activity('plan_eval_ack', 'รับทราบผลประเมินแผนฯ ' . $plan['subject_code']);
        flash('success', 'รับทราบผลการประเมินแผนการจัดการเรียนรู้เรียบร้อยแล้ว');
    }
    redirect($self . '?view=' . (int)$plan['id']);
}

// ---------- ดูผล ----------
if (get_int('view') > 0) {
    $plan = plan_eval_fetch_plan(get_int('view'));
    if (!$plan || (int)$plan['teacher_id'] !== $uid || $plan['doc_type'] !== 'plan') {
        abort_page(404, 'ไม่พบแผนการจัดการเรียนรู้', 'รายการนี้ไม่มีอยู่ หรือไม่ใช่ของท่าน');
    }
    $ev = $plan['eval_id'] ? plan_eval_fetch((int)$plan['eval_id']) : null;
    if (!plan_eval_is_final($ev)) {
        $ev = null; // ร่างของผู้ประเมิน ครูยังไม่เห็น
    }
    $pageTitle = 'ผลการประเมินแผนการจัดการเรียนรู้';
    $pageIcon = 'bi-clipboard-data';
    $breadcrumbs = ['ผลประเมินแผนของฉัน' => $self, $plan['subject_code'] => null];
    $activeMenu = $self;
    $pageActions = $ev ? '<a href="' . e(url('reports/plan_eval_print.php?plan=' . (int)$plan['id'])) . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i> พิมพ์แบบประเมิน</a>' : '';
    require APP_ROOT . '/includes/header.php';
    if ($ev && !$ev['acknowledged_at']): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-bell-fill fs-5"></i>
            <span>กรุณาอ่านผลการประเมินด้านล่าง แล้วกด "รับทราบผลการประเมิน"</span>
            <form method="post" class="ms-auto" data-confirm="ยืนยันว่าท่านได้อ่านและรับทราบผลการประเมินแผนการจัดการเรียนรู้แล้ว?">
                <?= csrf_field() ?>
                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                <button class="btn btn-warning"><i class="bi bi-check2-square me-1"></i> รับทราบผลการประเมิน</button>
            </form>
        </div>
    <?php endif;
    require APP_ROOT . '/includes/partials/plan_eval_result.php';
    echo '<a href="' . e(url($self)) . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ---------- รายการ ----------
$ay = plan_eval_ay_sql();
$rows = db_all(
    "SELECT lp.id, lp.subject_code, lp.subject_name, lp.level, lp.status, lp.is_late, lp.current_version, lp.submitted_at,
            ay.year, ay.semester, pe.id AS eval_id, pe.avg_score, pe.is_passed, pe.decision, pe.resubmit_by, pe.acknowledged_at, pe.plan_version
     FROM lesson_plans lp
     JOIN submission_rounds r ON r.id = lp.round_id
     LEFT JOIN academic_years ay ON ay.id = $ay
     LEFT JOIN plan_evaluations pe ON pe.lesson_plan_id = lp.id AND pe.status = 'final'
     WHERE lp.teacher_id = ? AND lp.doc_type = 'plan'
     ORDER BY lp.submitted_at DESC",
    'i',
    [$uid]
);
$done = array_filter($rows, fn($r) => $r['eval_id']);
$avgAll = $done ? array_sum(array_column($done, 'avg_score')) / count($done) : null;
$unack = array_filter($done, fn($r) => !$r['acknowledged_at']);

$pageTitle = 'ผลการประเมินแผนการจัดการเรียนรู้ของฉัน';
$pageIcon = 'bi-clipboard-data';
$breadcrumbs = ['ผลประเมินแผนของฉัน' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-journal-text"></i></div><div><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">แผนที่ส่งในระบบ</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-clipboard-check"></i></div><div><div class="stat-value"><?= count($done) ?></div><div class="stat-label">ได้รับการประเมินแล้ว</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-star"></i></div><div><div class="stat-value"><?= $avgAll !== null ? number_format($avgAll, 2) : '-' ?></div><div class="stat-label">ค่าเฉลี่ยรวม</div><div class="stat-sub"><?= $avgAll !== null ? e(plan_eval_level($avgAll)) : '' ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-yellow"><i class="bi bi-bell"></i></div><div><div class="stat-value"><?= count($unack) ?></div><div class="stat-label">รอกดรับทราบ</div></div></div></div>
</div>

<?php if (!$rows): ?>
    <div class="alert alert-info">ยังไม่มีแผนการจัดการเรียนรู้ที่ส่งในระบบ (ส่งได้ที่เมนู โครงการสอน/แผนการจัดการเรียนรู้)</div>
<?php endif; ?>
<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2"></i>แผนการจัดการเรียนรู้ของฉันและผลการประเมิน</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>รายวิชา</th><th>ภาคเรียน</th><th>ส่งเมื่อ</th><th>ผลประเมิน</th><th>ผลการพิจารณา</th><th>การรับทราบ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['eval_id'] && !$r['acknowledged_at'] ? 'table-warning' : '' ?>">
                        <td><span class="fw-semibold"><?= e($r['subject_code']) ?></span> <?= e($r['subject_name']) ?> <span class="small text-muted">(<?= e($r['level']) ?>)</span></td>
                        <td><?= $r['year'] ? e($r['semester'] . '/' . $r['year']) : '-' ?></td>
                        <td class="small text-nowrap" data-order="<?= e($r['submitted_at']) ?>"><?= e(thai_date($r['submitted_at'])) ?></td>
                        <td><?= $r['eval_id'] ? plan_eval_level_badge((float)$r['avg_score']) . ' ' . plan_eval_pass_badge((bool)$r['is_passed']) : '<span class="badge bg-secondary">ยังไม่ประเมิน</span>' ?></td>
                        <td class="small"><?= $r['eval_id'] ? e(plan_eval_decision_text($r)) : '-' ?></td>
                        <td class="small">
                            <?php if (!$r['eval_id']): ?>-
                            <?php elseif ($r['acknowledged_at']): ?><span class="text-success"><i class="bi bi-check2"></i> <?= e(thai_date($r['acknowledged_at'])) ?></span>
                            <?php else: ?><span class="text-warning">ยังไม่รับทราบ</span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['eval_id']): ?>
                                <a href="<?= e(url($self . '?view=' . (int)$r['id'])) ?>" class="btn btn-sm <?= $r['acknowledged_at'] ? 'btn-outline-primary' : 'btn-warning' ?>"><i class="bi bi-eye"></i> ดูผล</a>
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
