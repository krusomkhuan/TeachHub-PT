<?php
/**
 * ไฟล์: reports/home_visit_summary.php
 * สรุปรายงานการเยี่ยมบ้านนักเรียน — ภาพรวมทั้งวิทยาลัย / รายแผนก / รายครูที่ปรึกษา
 *   ?year= ปีการศึกษา, ?sem= ภาคเรียน (ไม่ระบุ = ทั้งปี), ?dept= แผนก, ?export=csv
 * สิทธิ์: admin, ผู้บริหาร, งานครูที่ปรึกษาและแนะแนว (ทุกแผนก) / หัวหน้าแผนก (เฉพาะแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_view_advisor_overview($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
}
$scopeAll = can_manage_advisor($user) || $user['role'] === 'executive';
$years = home_visit_years();
$year = home_visit_year(get_int('year'));
$dept = $scopeAll ? get_int('dept') : (int)$user['department_id'];
$departments = $scopeAll ? db_all('SELECT id, name FROM departments ORDER BY name') : [];
$period = home_visit_period($year);
$sum = home_visit_summary($year, $dept, 0, $period['sem']);
$tot = $sum['totals'];
$risk = home_visit_risk_labels();

$byDept = [];
foreach ($sum['teachers'] as $t) {
    $k = $t['department_name'] ?: '-';
    $byDept[$k] = $byDept[$k] ?? ['teachers' => 0, 'students' => 0, 'visits' => 0, 'risk' => 0, 'problem' => 0];
    foreach (['students', 'visits', 'risk', 'problem'] as $f) {
        $byDept[$k][$f] += $t[$f];
    }
    $byDept[$k]['teachers']++;
}

if (get('export') === 'csv') {
    $rows = [];
    foreach ($sum['teachers'] as $t) {
        $rows[] = [$t['teacher_name'], $t['department_name'], implode(', ', $t['group_names']), $t['students'], $t['visits'], $t['visit_times'], $t['visit_pct'],
            $t['normal'], $t['risk'], $t['problem'], $t['approved'], $t['pending'], $t['revision']];
    }
    $rows[] = [];
    $rows[] = ['รวม', '', '', $tot['students'], $tot['visits'], $tot['visit_times'], $tot['visit_pct'], $tot['normal'], $tot['risk'], $tot['problem'],
        $tot['approved'], $tot['pending'], $tot['revision']];
    $rows[] = [$period['label'] . ' · ข้อมูล ณ ' . thai_date(date('Y-m-d'))];
    log_activity('report_export', 'ส่งออกสรุปการเยี่ยมบ้าน ' . $period['label']);
    csv_download('home_visit_summary_' . $year . '.csv',
        ['ครูที่ปรึกษา', 'แผนก', 'กลุ่มเรียน', 'นักเรียนในที่ปรึกษา', 'เยี่ยมบ้านแล้ว (คน)', 'จำนวนครั้งที่เยี่ยม', 'ร้อยละ', 'ปกติ', 'กลุ่มเสี่ยง', 'กลุ่มมีปัญหา', 'ผ่าน', 'รอตรวจ', 'ให้แก้ไข'],
        $rows);
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['year' => $year, 'sem' => $period['sem']['id'] ?? null, 'dept' => $scopeAll ? ($dept ?: null) : null], $over), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'สรุปการเยี่ยมบ้านนักเรียน';
$pageIcon = 'bi-house-heart';
$breadcrumbs = ['สรุปการเยี่ยมบ้าน' => null];
$activeMenu = 'reports/home_visit_summary.php';
$pageActions = '<a href="' . e($qs(['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i> ส่งออก Excel</a>'
    . ' <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> พิมพ์</button>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4 d-print-none">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1" for="year">ปีการศึกษา</label>
                <select name="year" id="year" class="form-select" onchange="this.form.sem.value='';this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= home_visit_period_controls($period) ?>
            <?php if ($scopeAll): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทั้งวิทยาลัย</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> แสดง</button></div>
        </form>
    </div>
</div>

<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
    <strong><?= e($period['label']) ?></strong><?= $scopeAll ? '' : ' · ' . e($user['department_name'] ?? '') ?> · นักเรียนในที่ปรึกษานับจากจำนวนนักเรียนของกลุ่มที่ปรึกษา
    (กลุ่มเดียวกันหลายภาคเรียนนับครั้งเดียว) · ไม่มีการกำหนดเป้าจำนวนการเยี่ยมบ้าน ร้อยละใช้เพื่อดูความครอบคลุม</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        [$tot['teachers'], 'ครูที่ปรึกษา', 'ic-navy', 'bi-person-badge', null],
        [$tot['visits'] . '/' . $tot['students'], 'นักเรียนที่เยี่ยมบ้านแล้ว · รวม ' . $tot['visit_times'] . ' ครั้ง', 'ic-blue', 'bi-house-check', $tot['visit_pct']],
        [$tot['normal'], 'ปกติ', 'ic-green', 'bi-emoji-smile', percent($tot['normal'], $tot['visits'])],
        [$tot['risk'], 'กลุ่มเสี่ยง', 'ic-yellow', 'bi-exclamation-triangle', percent($tot['risk'], $tot['visits'])],
        [$tot['problem'], 'กลุ่มมีปัญหา', 'ic-red', 'bi-exclamation-octagon', percent($tot['problem'], $tot['visits'])],
        [$tot['pending'], 'รอตรวจ', 'ic-orange', 'bi-hourglass-split', null],
    ] as [$val, $label, $ic, $icon, $pct]): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stat-card flex-column align-items-start gap-2">
                <div class="d-flex align-items-center gap-2 w-100">
                    <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                    <div class="stat-value" style="font-size:1.4rem"><?= e((string)$val) ?></div>
                </div>
                <div class="stat-label"><?= e($label) ?><?= $pct !== null ? ' <strong>' . $pct . '%</strong>' : '' ?></div>
                <?php if ($pct !== null): ?><div class="progress progress-thin w-100"><div class="progress-bar bg-accent" style="width: <?= $pct ?>%"></div></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$sum['teachers']): ?>
    <div class="alert alert-info">ยังไม่มีการกำหนดครูที่ปรึกษาในปีการศึกษานี้</div>
<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>จำนวนการเยี่ยมบ้านรายเดือน</div>
            <div class="card-body"><?php if ($sum['by_month']): ?><canvas id="monthChart" height="120"></canvas><?php else: ?><div class="text-muted small">ยังไม่มีรายงาน</div><?php endif; ?></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>ผลการคัดกรองนักเรียน</div>
            <div class="card-body"><?php if ($tot['visits']): ?><canvas id="riskChart" height="200"></canvas><?php else: ?><div class="text-muted small">ยังไม่มีรายงาน</div><?php endif; ?></div>
        </div>
    </div>
</div>

<?php if ($scopeAll && !$dept): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>สรุปรายแผนก</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>แผนก</th><th class="text-center">ครูที่ปรึกษา</th><th class="text-center">นักเรียน</th><th class="text-center">เยี่ยมแล้ว</th>
                        <th class="text-center">กลุ่มเสี่ยง</th><th class="text-center">มีปัญหา</th><th style="width:25%">ร้อยละที่เยี่ยม</th></tr></thead>
                    <tbody>
                    <?php foreach ($byDept as $name => $d): $pct = percent($d['visits'], $d['students']); ?>
                        <tr>
                            <td><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$name)) ?></td>
                            <td class="text-center"><?= $d['teachers'] ?></td><td class="text-center"><?= $d['students'] ?></td>
                            <td class="text-center"><?= $d['visits'] ?></td><td class="text-center"><?= $d['risk'] ?></td>
                            <td class="text-center <?= $d['problem'] ? 'text-danger' : '' ?>"><?= $d['problem'] ?></td>
                            <td><div class="d-flex align-items-center gap-2"><div class="progress progress-thin flex-fill"><div class="progress-bar bg-accent" style="width: <?= min(100, $pct) ?>%"></div></div><small><?= $pct ?>%</small></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-table me-2"></i>สรุปรายครูที่ปรึกษา</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order='[[4, "desc"]]'>
                <thead><tr><th>ครูที่ปรึกษา</th><th>แผนก</th><th>กลุ่มเรียน</th><th class="text-center">นักเรียน</th><th class="text-center">เยี่ยมแล้ว (คน)</th><th class="text-center">ครั้ง</th>
                    <th class="text-center">ร้อยละ</th><th class="text-center">ปกติ/เสี่ยง/ปัญหา</th><th class="text-center">ผ่าน/รอ/แก้</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($sum['teachers'] as $t): ?>
                    <tr>
                        <td><?= e($t['teacher_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$t['department_name'])) ?></td>
                        <td class="small"><?= e(implode(', ', $t['group_names'])) ?></td>
                        <td class="text-center"><?= $t['students'] ?></td>
                        <td class="text-center fw-semibold"><?= $t['visits'] ?></td>
                        <td class="text-center"><?= $t['visit_times'] ?></td>
                        <td class="text-center" data-order="<?= $t['visit_pct'] ?>"><?= $t['visit_pct'] ?>%</td>
                        <td class="text-center small"><?= $t['normal'] ?>/<span class="text-warning"><?= $t['risk'] ?></span>/<span class="text-danger"><?= $t['problem'] ?></span></td>
                        <td class="text-center small"><?= $t['approved'] ?>/<?= $t['pending'] ?>/<?= $t['revision'] ?></td>
                        <td class="text-end"><a href="<?= e(url('reports/home_visit_teacher.php?' . http_build_query(array_filter(['teacher' => $t['teacher_id'], 'year' => $year, 'sem' => $period['sem']['id'] ?? null])))) ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-eye"></i> รายละเอียด</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$chart = ['months' => array_map('home_visit_month_label', array_keys($sum['by_month'])), 'counts' => array_values($sum['by_month']),
    'risk' => [array_values($risk), [$tot['normal'], $tot['risk'], $tot['problem']]]];
$pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
    var d = ' . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    var m = document.getElementById("monthChart");
    if (m) new Chart(m, { type: "bar", data: { labels: d.months, datasets: [{ label: "จำนวนการเยี่ยมบ้าน", data: d.counts, backgroundColor: "#f47b20", borderRadius: 4 }] },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } } });
    var r = document.getElementById("riskChart");
    if (r) new Chart(r, { type: "doughnut", data: { labels: d.risk[0], datasets: [{ data: d.risk[1], backgroundColor: ["#198754", "#ffc107", "#dc3545"] }] },
        options: { plugins: { legend: { position: "bottom" } } } });
});';
endif;
?>
<div class="d-none d-print-block small mt-4">ผู้ออกรายงาน: <?= e(full_name($user)) ?> · ระบบ <?= e(system_name()) ?> · <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
