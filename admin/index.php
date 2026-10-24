<?php
/**
 * ไฟล์: admin/index.php
 * แดชบอร์ดผู้ดูแลระบบ: ภาพรวมทั้งวิทยาลัย, ผู้ใช้รออนุมัติ, ความคืบหน้ารอบที่เปิด, กราฟแยกแผนก, Log ล่าสุด
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
// เจ้าหน้าที่ดูแลระบบ: ส่วนข้อมูลผู้ใช้/บันทึกการใช้งาน แสดงเฉพาะเมื่อได้รับสิทธิ์
$canUsers = admin_can($user, 'users');
$canLogs = admin_can($user, 'logs');
// สำรองข้อมูลอัตโนมัติวันละครั้ง (ถ้าเปิดใช้ในหน้า สำรอง / กู้คืนข้อมูล)
backup_auto_maybe();
$ay = current_academic_year();
$ayId = $ay ? (int)$ay['id'] : 0;

// ---------- ตัวเลขสรุป ----------
$teacherCount = (int)db_value("SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('teacher','supervisor')");
$pendingUsers = db_all(
    "SELECT u.id, u.prefix, u.first_name, u.last_name, u.username, u.position, u.created_at, d.name AS department_name
     FROM users u LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.status = 'pending' ORDER BY u.created_at"
);
$resetRequests = (int)db_value("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'");
$summary = stat_status_summary($ayId);
$pendingReviews = $summary['lesson_plan']['pending'] + $summary['pa']['pending'] + $summary['research']['pending'];
$rounds = active_rounds();
$supStats = db_one(
    "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'completed'), 0) AS done
     FROM supervision_schedules WHERE (? = 0 OR academic_year_id = ?) AND status <> 'cancelled'",
    'ii',
    [$ayId, $ayId]
);

// ---------- ความคืบหน้ารอบที่เปิด ----------
$progress = [];
foreach ($rounds as $r) {
    $progress[] = ['round' => $r, 'p' => stat_round_progress($r)];
}

// ---------- กราฟแยกแผนก ----------
$deptStats = stat_department_submission($ayId);

// ---------- Log ล่าสุด ----------
$logs = !$canLogs ? [] : db_all(
    "SELECT l.action, l.detail, l.ip_address, l.created_at, u.username
     FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.id DESC LIMIT 8"
);

$pageTitle = 'แดชบอร์ดผู้ดูแลระบบ';
$pageIcon = 'bi-speedometer2';
$pageActions = $canUsers ? '<a href="' . e(url('admin/users.php')) . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-people me-1"></i>จัดการผู้ใช้</a>' : '';
require APP_ROOT . '/includes/header.php';
?>
<?php require APP_ROOT . '/includes/partials/report_alert.php'; ?>
<?php require APP_ROOT . '/includes/partials/user_cheer.php'; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl">
        <a href="<?= $canUsers ? e(url('admin/users.php')) : '#' ?>" class="stat-card">
            <div class="stat-icon ic-navy"><i class="bi bi-people"></i></div>
            <div><div class="stat-value"><?= $teacherCount ?></div><div class="stat-label">ครูผู้สอนที่ใช้งาน</div></div>
        </a>
    </div>
    <?php if ($canUsers): ?>
    <div class="col-sm-6 col-xl">
        <a href="<?= e(url('admin/users.php?status=pending')) ?>" class="stat-card">
            <div class="stat-icon ic-red"><i class="bi bi-person-exclamation"></i></div>
            <div><div class="stat-value"><?= count($pendingUsers) ?></div><div class="stat-label">รออนุมัติสมาชิก</div>
                <div class="stat-sub">คำขอรีเซ็ตรหัส <?= $resetRequests ?></div></div>
        </a>
    </div>
    <?php endif; ?>
    <div class="col-sm-6 col-xl">
        <div class="stat-card">
            <div class="stat-icon ic-yellow"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="stat-value"><?= $pendingReviews ?></div><div class="stat-label">งานรอตรวจ</div>
                <div class="stat-sub">แผน <?= $summary['lesson_plan']['pending'] ?> · PA <?= $summary['pa']['pending'] ?> · วิจัย <?= $summary['research']['pending'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="stat-card">
            <div class="stat-icon ic-orange"><i class="bi bi-door-open"></i></div>
            <div><div class="stat-value"><?= count($rounds) ?></div><div class="stat-label">รอบที่เปิดรับอยู่</div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="stat-card">
            <div class="stat-icon ic-purple"><i class="bi bi-person-video3"></i></div>
            <div><div class="stat-value"><?= (int)$supStats['done'] ?>/<?= (int)$supStats['total'] ?></div><div class="stat-label">นิเทศแล้ว/ตามแผน</div></div>
        </div>
    </div>
</div>

<?php if ($pendingUsers && $canUsers): ?>
    <div class="card mb-4 border-start border-4 border-danger">
        <div class="card-header d-flex align-items-center">
            <i class="bi bi-person-plus me-2 text-danger"></i>สมาชิกใหม่รออนุมัติ
            <a href="<?= e(url('admin/users.php?status=pending')) ?>" class="ms-auto small fw-normal">ดูทั้งหมด</a>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>ชื่อ-สกุล</th><th>ชื่อผู้ใช้</th><th>แผนก</th><th>ตำแหน่ง</th><th>สมัครเมื่อ</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($pendingUsers, 0, 5) as $pu): ?>
                    <tr>
                        <td><?= e(full_name($pu)) ?></td>
                        <td><?= e($pu['username']) ?></td>
                        <td class="small"><?= e($pu['department_name'] ?? '-') ?></td>
                        <td class="small"><?= e($pu['position']) ?></td>
                        <td class="small"><?= e(thai_datetime($pu['created_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <form method="post" action="<?= e(url('admin/users.php')) ?>" class="d-inline"
                                  data-confirm="อนุมัติให้ <?= e(full_name($pu)) ?> เข้าใช้งานระบบ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= (int)$pu['id'] ?>">
                                <input type="hidden" name="return" value="admin/index.php">
                                <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> อนุมัติ</button>
                            </form>
                            <a href="<?= e(url('admin/user_edit.php?id=' . (int)$pu['id'])) ?>" class="btn btn-sm btn-outline-secondary">ดูข้อมูล</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart-line me-2"></i>ร้อยละครูที่ส่งงานแยกตามแผนก (ภาคเรียน <?= e(academic_label($ay)) ?>)</div>
            <div class="card-body"><div class="chart-box"><canvas id="deptChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>สถานะงานทั้งหมด (ภาคเรียนนี้)</div>
            <div class="card-body"><div class="chart-box"><canvas id="statusChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="<?= $canLogs ? 'col-xl-7' : 'col-12' ?>">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-hourglass-split me-2"></i>ความคืบหน้ารอบที่เปิดรับ</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>รอบการส่ง</th><th>เหลือเวลา</th><th style="min-width:160px">ส่งแล้ว</th><th>รอตรวจ</th></tr></thead>
                    <tbody>
                    <?php foreach ($progress as ['round' => $r, 'p' => $p]): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($r['name']) ?></div>
                                <div class="small text-muted"><?= e(submission_type_label($r['type'])) ?> · <?= round_state_badge($r) ?></div>
                            </td>
                            <td class="small countdown-box">
                                <?php if (round_state($r) === 'open'): ?>
                                    <span data-countdown="<?= strtotime($r['close_at']) * 1000 ?>"><?= e(remaining_text(strtotime($r['close_at']) - time())) ?></span>
                                <?php else: ?>เลยกำหนด<?php endif; ?>
                            </td>
                            <td>
                                <div class="small"><?= $p['sent'] ?>/<?= $p['teachers'] ?> คน (<?= $p['percent'] ?>%)
                                    <?php if ($p['late']): ?><span class="text-warning">· ช้า <?= $p['late'] ?></span><?php endif; ?></div>
                                <div class="progress progress-thin"><div class="progress-bar bg-accent" style="width: <?= $p['percent'] ?>%"></div></div>
                            </td>
                            <td><?= $p['pending'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$progress): ?><tr><td colspan="4" class="empty-state">ไม่มีรอบที่เปิดรับ</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($canLogs): ?>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header d-flex"><i class="bi bi-clock-history me-2"></i>การใช้งานล่าสุด
                <a href="<?= e(url('admin/logs.php')) ?>" class="ms-auto small fw-normal">ทั้งหมด</a></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($logs as $l): ?>
                    <li class="list-group-item">
                        <div class="d-flex">
                            <span class="fw-semibold"><?= e($l['username'] ?? 'ผู้เยี่ยมชม') ?></span>
                            <span class="ms-auto text-muted"><?= e(thai_datetime($l['created_at'])) ?></span>
                        </div>
                        <div><?= e(log_action_label($l['action'])) ?> <span class="text-muted">— <?= e(str_limit((string)$l['detail'], 60)) ?></span></div>
                        <div class="text-muted">IP <?= e($l['ip_address']) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$deptChart = ['labels' => [], 'lp' => [], 'pa' => [], 'rs' => []];
foreach ($deptStats as $d) {
    $deptChart['labels'][] = preg_replace('/^แผนกวิชา/u', '', $d['name']);
    $deptChart['lp'][] = percent((int)$d['lesson_plan'], (int)$d['teachers']);
    $deptChart['pa'][] = percent((int)$d['pa'], (int)$d['teachers']);
    $deptChart['rs'][] = percent((int)$d['research'], (int)$d['teachers']);
}
$statusTotals = [
    array_sum(array_column($summary, 'pending')),
    array_sum(array_column($summary, 'approved')),
    array_sum(array_column($summary, 'revision')),
];
$pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
    var d = ' . json_encode($deptChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', p = TeachHub.palette, c = TeachHub.statusColors;
    new Chart(document.getElementById("deptChart"), {
        type: "bar",
        data: { labels: d.labels, datasets: [
            { label: "แผนการสอน", data: d.lp, backgroundColor: p[0], borderRadius: 4 },
            { label: "PA", data: d.pa, backgroundColor: p[1], borderRadius: 4 },
            { label: "วิจัย", data: d.rs, backgroundColor: p[2], borderRadius: 4 }
        ]},
        options: { scales: { y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + "%"; } } } },
                   plugins: { tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ": " + ctx.parsed.y + "%"; } } } } }
    });
    var s = ' . json_encode($statusTotals) . ';
    new Chart(document.getElementById("statusChart"), {
        type: "doughnut",
        data: { labels: ["รอตรวจ", "ผ่าน", "ให้แก้ไข"], datasets: [{ data: s, backgroundColor: [c.pending, c.approved, c.revision] }] },
        options: { cutout: "62%", plugins: { legend: { position: "bottom" } } }
    });
});';
require APP_ROOT . '/includes/footer.php';
