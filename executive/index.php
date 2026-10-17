<?php
/**
 * ไฟล์: executive/index.php
 * แดชบอร์ดผู้บริหาร (ดูอย่างเดียว): ร้อยละการส่งงาน, สถานะงาน, คะแนนนิเทศเฉลี่ยแยกแผนก
 * เลือกภาคเรียนได้ด้วย ?ay=id
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('executive');

// ---------- เลือกภาคเรียน ----------
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$current = current_academic_year();
$ayId = get_int('ay', $current ? (int)$current['id'] : 0);
$ay = null;
foreach ($years as $y) {
    if ((int)$y['id'] === $ayId) {
        $ay = $y;
    }
}
if (!$ay) {
    $ayId = 0; // ไม่พบ = ทุกภาคเรียน
}

// ---------- ข้อมูล ----------
$teacherCount = (int)db_value("SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('teacher','supervisor')");
$summary = stat_status_summary($ayId);
$deptStats = stat_department_submission($ayId);
$supDept = stat_supervision_by_department($ayId);
$supAll = db_one(
    'SELECT COUNT(res.id) AS times, ROUND(AVG(res.avg_score), 2) AS avg_score
     FROM supervision_results res JOIN supervision_schedules sc ON sc.id = res.schedule_id
     WHERE (? = 0 OR sc.academic_year_id = ?)',
    'ii',
    [$ayId, $ayId]
);

// ร้อยละครูที่ส่งแล้ว (ทั้งวิทยาลัย) แยกประเภท
$sentTeachers = ['lesson_plan' => 0, 'pa' => 0, 'research' => 0];
foreach ($deptStats as $d) {
    foreach ($sentTeachers as $k => $v) {
        $sentTeachers[$k] += (int)$d[$k];
    }
}

$pageTitle = 'แดชบอร์ดผู้บริหาร';
$pageIcon = 'bi-speedometer2';
ob_start(); ?>
<form method="get" class="d-flex gap-2 no-print">
    <select name="ay" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="เลือกภาคเรียน">
        <option value="0">ทุกภาคเรียน</option>
        <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id'] === $ayId ? 'selected' : '' ?>>ภาคเรียน <?= e(academic_label($y)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>
</form>
<?php
$pageActions = ob_get_clean();
require APP_ROOT . '/includes/header.php';
?>
<?php require APP_ROOT . '/includes/partials/report_alert.php'; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl">
        <div class="stat-card">
            <div class="stat-icon ic-navy"><i class="bi bi-people"></i></div>
            <div><div class="stat-value"><?= $teacherCount ?></div><div class="stat-label">ครูผู้สอนทั้งหมด</div></div>
        </div>
    </div>
    <?php
    $cards = ['lesson_plan' => ['ic-blue', 'ส่งแผนการสอน'], 'pa' => ['ic-orange', 'ส่ง PA'], 'research' => ['ic-green', 'ส่งวิจัย']];
    foreach ($cards as $t => [$ic, $label]): $pct = percent($sentTeachers[$t], $teacherCount); ?>
        <div class="col-sm-6 col-xl">
            <div class="stat-card">
                <div class="stat-icon <?= $ic ?>"><i class="bi <?= e(submission_type_icon($t)) ?>"></i></div>
                <div class="flex-grow-1">
                    <div class="stat-value"><?= $pct ?>%</div>
                    <div class="stat-label"><?= e($label) ?> (<?= $sentTeachers[$t] ?>/<?= $teacherCount ?> คน)</div>
                    <div class="progress progress-thin mt-1"><div class="progress-bar bg-accent" style="width: <?= $pct ?>%"></div></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-xl">
        <div class="stat-card">
            <div class="stat-icon ic-purple"><i class="bi bi-star"></i></div>
            <div><div class="stat-value"><?= $supAll['avg_score'] !== null ? e(number_format((float)$supAll['avg_score'], 2)) : '-' ?></div>
                <div class="stat-label">คะแนนนิเทศเฉลี่ย</div>
                <div class="stat-sub"><?= $supAll['avg_score'] !== null ? e(quality_level((float)$supAll['avg_score'])) . ' · ' : '' ?><?= (int)$supAll['times'] ?> ครั้ง</div></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart-line me-2"></i>ร้อยละครูที่ส่งงานแยกตามแผนก</div>
            <div class="card-body"><div class="chart-box"><canvas id="deptChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-star-half me-2"></i>คะแนนนิเทศเฉลี่ยแยกแผนก</div>
            <div class="card-body"><div class="chart-box"><canvas id="supChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex"><i class="bi bi-table me-2"></i>สรุปสถานะงานแยกประเภท
        <a href="<?= e(url('reports/index.php')) ?>" class="ms-auto small fw-normal no-print">รายงานละเอียด <i class="bi bi-arrow-right"></i></a></div>
    <div class="table-responsive">
        <table class="table mb-0 text-center">
            <thead>
            <tr><th class="text-start">ประเภทงาน</th><th>ส่งทั้งหมด</th><th>รอตรวจ</th><th>ผ่าน</th><th>ให้แก้ไข</th><th>ส่งช้า</th><th>ร้อยละผ่าน</th></tr>
            </thead>
            <tbody>
            <?php foreach ($summary as $t => $s): ?>
                <tr>
                    <td class="text-start"><i class="bi <?= e(submission_type_icon($t)) ?> me-1"></i><?= e(submission_type_label($t)) ?></td>
                    <td class="fw-semibold"><?= $s['total'] ?></td>
                    <td><span class="badge badge-status badge-pending"><?= $s['pending'] ?></span></td>
                    <td><span class="badge badge-status badge-approved"><?= $s['approved'] ?></span></td>
                    <td><span class="badge badge-status badge-revision"><?= $s['revision'] ?></span></td>
                    <td><span class="badge badge-status badge-late"><?= $s['late'] ?></span></td>
                    <td><?= percent($s['approved'], $s['total']) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$deptChart = ['labels' => [], 'lp' => [], 'pa' => [], 'rs' => []];
foreach ($deptStats as $d) {
    $deptChart['labels'][] = preg_replace('/^แผนกวิชา/u', '', $d['name']);
    $deptChart['lp'][] = percent((int)$d['lesson_plan'], (int)$d['teachers']);
    $deptChart['pa'][] = percent((int)$d['pa'], (int)$d['teachers']);
    $deptChart['rs'][] = percent((int)$d['research'], (int)$d['teachers']);
}
$supChart = ['labels' => [], 'avg' => []];
foreach ($supDept as $d) {
    $supChart['labels'][] = preg_replace('/^แผนกวิชา/u', '', $d['name']);
    $supChart['avg'][] = $d['avg_score'] !== null ? (float)$d['avg_score'] : 0;
}
$pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
    var d = ' . json_encode($deptChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', p = TeachHub.palette;
    new Chart(document.getElementById("deptChart"), {
        type: "bar",
        data: { labels: d.labels, datasets: [
            { label: "แผนการสอน", data: d.lp, backgroundColor: p[0], borderRadius: 4 },
            { label: "PA", data: d.pa, backgroundColor: p[1], borderRadius: 4 },
            { label: "วิจัย", data: d.rs, backgroundColor: p[2], borderRadius: 4 }
        ]},
        options: { scales: { y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + "%"; } } } } }
    });
    var s = ' . json_encode($supChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    new Chart(document.getElementById("supChart"), {
        type: "bar",
        data: { labels: s.labels, datasets: [{ label: "คะแนนเฉลี่ย (เต็ม 5)", data: s.avg, backgroundColor: p[1], borderRadius: 4 }] },
        options: { indexAxis: "y", scales: { x: { beginAtZero: true, max: 5 } }, plugins: { legend: { display: false } } }
    });
});';
require APP_ROOT . '/includes/footer.php';
