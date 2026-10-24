<?php
/**
 * ไฟล์: supervisor/index.php
 * แดชบอร์ดหัวหน้าแผนก/ผู้นิเทศ: งานรอตรวจในแผนก, ความคืบหน้าการส่งของแผนก, ตารางนิเทศที่ต้องไป, งานของตนเอง
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
$uid = (int)$user['id'];
$deptId = (int)$user['department_id'];
$ay = current_academic_year();
$ayId = $ay ? (int)$ay['id'] : 0;

// ---------- ตัวเลขสรุป ----------
$deptTeachers = (int)db_value(
    "SELECT COUNT(*) FROM users WHERE department_id = ? AND status = 'active' AND role IN ('teacher','supervisor')",
    'i',
    [$deptId]
);
$summary = stat_status_summary($ayId, $deptId);
$pendingTotal = $summary['lesson_plan']['pending'] + $summary['pa']['pending'] + $summary['research']['pending'];

$mySchedules = db_all(
    "SELECT sc.*, CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name
     FROM supervision_schedules sc JOIN users t ON t.id = sc.teacher_id
     WHERE sc.supervisor_id = ? AND sc.status = 'scheduled'
     ORDER BY sc.supervise_date, sc.start_time LIMIT 6",
    'i',
    [$uid]
);
$supDone = (int)db_value(
    "SELECT COUNT(*) FROM supervision_schedules WHERE supervisor_id = ? AND status = 'completed'",
    'i',
    [$uid]
);

// ---------- งานรอตรวจล่าสุดในแผนก ----------
$pendingList = db_all(
    "SELECT 'lesson_plan' AS type, s.id, CONCAT(s.subject_code, ' ', s.subject_name) AS title, s.submitted_at, s.is_late,
            CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name
       FROM lesson_plans s JOIN users u ON u.id = s.teacher_id
      WHERE u.department_id = ? AND s.status = 'pending'
     UNION ALL
     SELECT 'pa', s.id, CONCAT('PA ปีงบประมาณ ', s.fiscal_year), s.submitted_at, s.is_late,
            CONCAT(u.prefix, u.first_name, ' ', u.last_name)
       FROM pa_submissions s JOIN users u ON u.id = s.teacher_id
      WHERE u.department_id = ? AND s.status = 'pending'
     UNION ALL
     SELECT 'research', s.id, s.title, s.submitted_at, s.is_late,
            CONCAT(u.prefix, u.first_name, ' ', u.last_name)
       FROM research_submissions s JOIN users u ON u.id = s.teacher_id
      WHERE u.department_id = ? AND s.status = 'pending'
     ORDER BY submitted_at ASC LIMIT 10",
    'iii',
    [$deptId, $deptId, $deptId]
);

// ---------- ความคืบหน้ารอบที่เปิดอยู่ (เฉพาะแผนก) ----------
$rounds = active_rounds();
$progress = [];
foreach ($rounds as $r) {
    $progress[] = ['round' => $r, 'p' => stat_round_progress($r, $deptId)];
}

$reviewPage = ['lesson_plan' => 'supervisor/lesson_plans.php', 'pa' => 'supervisor/pa.php', 'research' => 'supervisor/research.php'];

$pageTitle = 'แดชบอร์ดหัวหน้าแผนก';
$pageIcon = 'bi-speedometer2';
require APP_ROOT . '/includes/header.php';
?>
<?php require APP_ROOT . '/includes/partials/report_alert.php'; ?>
<?php require APP_ROOT . '/includes/partials/user_cheer.php'; ?>

<div class="alert alert-light border mb-4">
    <i class="bi bi-diagram-3 me-1 text-accent"></i>
    <strong><?= e($user['department_name'] ?? 'ยังไม่ได้กำหนดแผนก') ?></strong>
    · ภาคเรียน <?= e(academic_label($ay)) ?>
    <?php if (!$deptId): ?>
        <span class="text-danger ms-2">กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกของท่าน</span>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon ic-navy"><i class="bi bi-people"></i></div>
            <div><div class="stat-value"><?= $deptTeachers ?></div><div class="stat-label">ครูในแผนก</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon ic-yellow"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="stat-value"><?= $pendingTotal ?></div><div class="stat-label">งานรอตรวจ</div>
                <div class="stat-sub">แผน <?= $summary['lesson_plan']['pending'] ?> · PA <?= $summary['pa']['pending'] ?> · วิจัย <?= $summary['research']['pending'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon ic-green"><i class="bi bi-check2-circle"></i></div>
            <div><div class="stat-value"><?= $summary['lesson_plan']['approved'] + $summary['pa']['approved'] + $summary['research']['approved'] ?></div>
                <div class="stat-label">งานที่ผ่านแล้ว (ภาคเรียนนี้)</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= e(url('supervisor/supervision.php')) ?>" class="stat-card">
            <div class="stat-icon ic-purple"><i class="bi bi-person-video3"></i></div>
            <div><div class="stat-value"><?= count($mySchedules) ?></div><div class="stat-label">นิเทศที่ต้องดำเนินการ</div>
                <div class="stat-sub">นิเทศแล้ว <?= $supDone ?> ครั้ง</div></div>
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- งานรอตรวจ -->
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-inbox me-2"></i>งานรอตรวจในแผนก (เรียงตามส่งก่อน)</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>ครู</th><th>งาน</th><th>ส่งเมื่อ</th><th class="text-end">ตรวจ</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingList as $p): ?>
                        <tr>
                            <td class="small"><?= e($p['teacher_name']) ?></td>
                            <td>
                                <span class="small text-muted d-block"><?= e(submission_type_label($p['type'])) ?></span>
                                <?= e(str_limit($p['title'], 60)) ?>
                                <?php if ($p['is_late']): ?><?= status_badge('late') ?><?php endif; ?>
                            </td>
                            <td class="small text-nowrap"><?= e(thai_datetime($p['submitted_at'])) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-accent" href="<?= e(url($reviewPage[$p['type']] . '?id=' . (int)$p['id'])) ?>">ตรวจ</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$pendingList): ?>
                        <tr><td colspan="4" class="empty-state"><i class="bi bi-check2-all"></i>ไม่มีงานรอตรวจ</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- กราฟสถานะ -->
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart me-2"></i>สถานะงานของแผนก (ภาคเรียนนี้)</div>
            <div class="card-body"><div class="chart-box"><canvas id="deptStatusChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- ความคืบหน้ารอบที่เปิด -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up-arrow me-2"></i>ความคืบหน้าการส่งงานของแผนก</div>
            <div class="card-body">
                <?php foreach ($progress as ['round' => $r, 'p' => $p]): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span class="fw-semibold"><?= e($r['name']) ?></span>
                            <span><?= $p['sent'] ?>/<?= $p['teachers'] ?> คน (<?= $p['percent'] ?>%)</span>
                        </div>
                        <div class="progress progress-thin mt-1" role="progressbar" aria-valuenow="<?= $p['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-accent" style="width: <?= $p['percent'] ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1">ปิดรับ <?= e(thai_datetime($r['close_at'])) ?>
                            <?php if ($p['late']): ?> · ส่งช้า <?= $p['late'] ?> คน<?php endif; ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$progress): ?><div class="empty-state">ไม่มีรอบที่เปิดรับ</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ตารางนิเทศของฉัน -->
    <div class="col-xl-6">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-calendar-week me-2"></i>ตารางนิเทศที่ต้องดำเนินการ</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($mySchedules as $sc):
                    $overdue = $sc['supervise_date'] < date('Y-m-d'); ?>
                    <li class="list-group-item d-flex align-items-center gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e($sc['teacher_name']) ?></div>
                            <div class="small text-muted"><?= e(thai_date($sc['supervise_date'])) ?> <?= e(substr($sc['start_time'], 0, 5)) ?> น. · <?= e($sc['subject_name']) ?> · <?= e($sc['classroom']) ?></div>
                        </div>
                        <?php if ($overdue): ?><span class="badge bg-danger">ยังไม่บันทึกผล</span><?php endif; ?>
                        <a href="<?= e(url('supervisor/supervision.php?record=' . (int)$sc['id'])) ?>" class="btn btn-sm btn-outline-primary">บันทึกผล</a>
                    </li>
                <?php endforeach; ?>
                <?php if (!$mySchedules): ?><li class="list-group-item empty-state">ไม่มีตารางนิเทศ</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<!-- งานของตนเอง (หัวหน้าแผนกก็ต้องส่งงานสอนของตนเอง) -->
<h5 class="fw-bold text-primary-th mt-4 mb-3"><i class="bi bi-person-workspace me-1"></i> งานของฉัน</h5>
<div class="row g-4">
    <div class="col-12"><?php require APP_ROOT . '/includes/partials/my_rounds.php'; ?></div>
</div>

<?php
$chart = [
    'labels'   => ['แผนการสอน', 'PA', 'วิจัย'],
    'pending'  => [$summary['lesson_plan']['pending'], $summary['pa']['pending'], $summary['research']['pending']],
    'approved' => [$summary['lesson_plan']['approved'], $summary['pa']['approved'], $summary['research']['approved']],
    'revision' => [$summary['lesson_plan']['revision'], $summary['pa']['revision'], $summary['research']['revision']],
];
$pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
    var d = ' . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', c = TeachHub.statusColors;
    new Chart(document.getElementById("deptStatusChart"), {
        type: "bar",
        data: { labels: d.labels, datasets: [
            { label: "รอตรวจ", data: d.pending, backgroundColor: c.pending, borderRadius: 4 },
            { label: "ผ่าน", data: d.approved, backgroundColor: c.approved, borderRadius: 4 },
            { label: "ให้แก้ไข", data: d.revision, backgroundColor: c.revision, borderRadius: 4 }
        ]},
        options: { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
    });
});';
require APP_ROOT . '/includes/footer.php';
