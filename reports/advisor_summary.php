<?php
/**
 * ไฟล์: reports/advisor_summary.php
 * สรุปรายงานการพบนักเรียนของครูที่ปรึกษา — ภาพรวมทั้งวิทยาลัย / รายแผนก / รายครู
 *   ?ay= ภาคเรียน, ?dept= แผนก, ?period=term|month|week (+ ?month=Y-m / ?week=วันพุธ), ?export=csv
 * สิทธิ์: admin, ผู้บริหาร, งานครูที่ปรึกษาและแนะแนว (ทุกแผนก) / หัวหน้าแผนก (เฉพาะแผนกตนเอง)
 * นับเฉพาะสัปดาห์ที่ถึงแล้ว (อ้างอิงวันพุธของสัปดาห์) และไม่ใช่สัปดาห์งดพบ · รายงานลงวันที่ใดก็ได้ในสัปดาห์
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_view_advisor_overview($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
}
$scopeAll = can_manage_advisor($user) || $user['role'] === 'executive';
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ay = advisor_academic_year(get_int('ay'));
$ayId = (int)($ay['id'] ?? 0);
$dept = $scopeAll ? get_int('dept') : (int)$user['department_id'];
$departments = $scopeAll ? db_all('SELECT id, name FROM departments ORDER BY name') : [];
$period = advisor_period($ay);
$sum = advisor_summary($ay, $dept, 0, $period['range']);
$tot = $sum['totals'];
$weeks = $sum['weeks'];

// สรุปรายแผนก
$byDept = [];
foreach ($sum['teachers'] as $t) {
    $k = $t['department_name'] ?: '-';
    $byDept[$k] = $byDept[$k] ?? ['teachers' => 0, 'groups' => 0, 'expected' => 0, 'submitted' => 0, 'late' => 0, 'missing' => 0];
    foreach (['groups', 'expected', 'submitted', 'late', 'missing'] as $f) {
        $byDept[$k][$f] += $t[$f];
    }
    $byDept[$k]['teachers']++;
}

// ส่งออก CSV (รายครู)
if (get('export') === 'csv') {
    $rows = [];
    foreach ($sum['teachers'] as $t) {
        $rows[] = [$t['teacher_name'], $t['department_name'], implode(', ', $t['group_names']), $t['expected'], $t['submitted'], $t['on_time'],
            $t['late'], $t['missing'], $t['approved'], $t['pending'], $t['revision'], $t['submit_pct'], $t['attend_pct']];
    }
    $rows[] = [];
    $rows[] = ['รวม', '', $tot['groups'] . ' กลุ่ม', $tot['expected'], $tot['submitted'], $tot['on_time'], $tot['late'], $tot['missing'],
        $tot['approved'], $tot['pending'], $tot['revision'], $tot['submit_pct'], $tot['attend_pct']];
    $rows[] = [$period['label'] . ' (ภาคเรียน ' . academic_label($ay) . ') · นับสัปดาห์ที่ต้องส่ง ' . count($weeks) . ' สัปดาห์ ถึงวันที่ ' . thai_date(date('Y-m-d'))];
    log_activity('report_export', 'ส่งออกสรุปรายงานกิจกรรมโฮมรูม ' . $period['label']);
    csv_download('advisor_summary_' . str_replace('/', '-', academic_label($ay)) . ($period['range'] ? '_' . $period['range'][0] : '') . '.csv',
        ['ครูที่ปรึกษา', 'แผนก', 'กลุ่มเรียน', 'ต้องส่ง', 'ส่งแล้ว', 'ตรงเวลา', 'ส่งช้า', 'ขาดส่ง', 'ผ่าน', 'รอตรวจ', 'ให้แก้ไข', 'ร้อยละการส่ง', 'ร้อยละการเข้าพบของนักเรียน'],
        $rows);
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['ay' => $ayId, 'dept' => $scopeAll ? ($dept ?: null) : null] + advisor_period_query($period), $over), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'สรุปรายงานครูที่ปรึกษา';
$pageIcon = 'bi-bar-chart-line';
$breadcrumbs = ['สรุปรายงานครูที่ปรึกษา' => null];
$activeMenu = 'reports/advisor_summary.php';
$pageActions = '<a href="' . e($qs(['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i> ส่งออก Excel</a>'
    . ' <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> พิมพ์</button>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4 d-print-none">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $ayId === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= advisor_period_controls($period) ?>
            <?php if ($scopeAll): ?>
                <div class="col-md-3">
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
    <strong><?= e($period['label']) ?></strong><?= $period['range'] ? ' (ภาคเรียน ' . e(academic_label($ay)) . ')' : '' ?><?= $scopeAll ? '' : ' · ' . e($user['department_name'] ?? '') ?> ·
    นับสัปดาห์ที่ต้องส่งแล้ว <strong><?= count($weeks) ?></strong> สัปดาห์ (ถึง <?= e(thai_date(date('Y-m-d'))) ?>, ไม่รวมวันงดพบ)
    · ส่งตรงเวลา = ภายใน <?= advisor_late_days() ?> วันหลังวันที่พบนักเรียน</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        [$tot['teachers'], 'ครูที่ปรึกษา (' . $tot['groups'] . ' กลุ่ม)', 'ic-navy', 'bi-person-badge', null],
        [$tot['submitted'] . '/' . $tot['expected'], 'รายงานที่ส่งแล้ว', 'ic-blue', 'bi-send-check', $tot['submit_pct']],
        [$tot['on_time'], 'ส่งตรงเวลา', 'ic-green', 'bi-check2-circle', $tot['on_time_pct']],
        [$tot['late'], 'ส่งช้า', 'ic-orange', 'bi-clock-history', percent($tot['late'], $tot['expected'])],
        [$tot['missing'], 'ขาดส่ง', 'ic-red', 'bi-dash-circle', percent($tot['missing'], $tot['expected'])],
        [$tot['attend_pct'] . '%', 'นักเรียนเข้าพบครูที่ปรึกษา', 'ic-purple', 'bi-people', null],
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
    <div class="alert alert-info">ยังไม่มีการกำหนดครูที่ปรึกษาในภาคเรียนนี้<?= can_manage_advisor($user) ? ' — กำหนดได้ที่ <a href="' . e(url('review/advisor_groups.php?ay=' . $ayId)) . '">กลุ่มที่ปรึกษา</a>' : '' ?></div>
<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>ร้อยละการส่งรายงานรายสัปดาห์</div>
            <div class="card-body"><canvas id="weekChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>ผลการตรวจ</div>
            <div class="card-body">
                <?php foreach ([['approved', 'ผ่าน'], ['pending', 'รอตรวจ'], ['revision', 'ให้แก้ไข']] as [$k, $label]): ?>
                    <div class="d-flex justify-content-between small mb-1"><span><?= status_badge($k) ?></span><strong><?= (int)$tot[$k] ?> รายงาน</strong></div>
                    <div class="progress progress-thin mb-3"><div class="progress-bar bg-accent" style="width: <?= percent($tot[$k], $tot['submitted']) ?>%"></div></div>
                <?php endforeach; ?>
                <hr>
                <div class="small text-muted">การเข้าพบของนักเรียน (รวมทุกรายงาน)</div>
                <div class="small">มา <?= $tot['present'] ?> · มาสาย <?= $tot['late_students'] ?> · ลา <?= $tot['leave'] ?> · ขาด <?= $tot['absent'] ?> คน-ครั้ง</div>
            </div>
        </div>
    </div>
</div>

<?php if ($scopeAll && !$dept): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>สรุปรายแผนก</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>แผนก</th><th class="text-center">ครูที่ปรึกษา</th><th class="text-center">กลุ่ม</th><th class="text-center">ต้องส่ง</th>
                        <th class="text-center">ส่งแล้ว</th><th class="text-center">ส่งช้า</th><th class="text-center">ขาดส่ง</th><th style="width:25%">ร้อยละการส่ง</th></tr></thead>
                    <tbody>
                    <?php foreach ($byDept as $name => $d): $pct = percent($d['submitted'], $d['expected']); ?>
                        <tr>
                            <td><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$name)) ?></td>
                            <td class="text-center"><?= $d['teachers'] ?></td><td class="text-center"><?= $d['groups'] ?></td>
                            <td class="text-center"><?= $d['expected'] ?></td><td class="text-center"><?= $d['submitted'] ?></td>
                            <td class="text-center"><?= $d['late'] ?></td><td class="text-center <?= $d['missing'] ? 'text-danger' : '' ?>"><?= $d['missing'] ?></td>
                            <td><div class="d-flex align-items-center gap-2"><div class="progress progress-thin flex-fill"><div class="progress-bar bg-accent" style="width: <?= $pct ?>%"></div></div><small><?= $pct ?>%</small></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($period['mode'] === 'week' && $weeks):
    [$wMon, $wSun] = advisor_week_range($period['week']);
    $weekRows = db_all(
        "SELECT g.id AS group_id, g.group_name, g.teacher_id, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, d.name AS department_name,
                r.id, r.meet_date, r.status, r.is_late, r.total_students, r.present_count, r.late_count
         FROM advisor_groups g JOIN users u ON u.id = g.teacher_id LEFT JOIN departments d ON d.id = g.department_id
         LEFT JOIN advisor_reports r ON r.group_id = g.id AND r.meet_date BETWEEN ? AND ?
         WHERE g.academic_year_id = ? AND (? = 0 OR g.department_id = ?)
         ORDER BY (r.id IS NULL) DESC, d.name, u.first_name",
        'ssiii',
        [$wMon, $wSun, $ayId, $dept, $dept]
    ); ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-calendar-week me-2"></i>รายงานรายกลุ่ม <?= e($period['label']) ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover datatable" data-order="[]">
                    <thead><tr><th>ครูที่ปรึกษา</th><th>กลุ่มเรียน</th><th>แผนก</th><th>วันที่พบ</th><th class="text-center">เข้าพบ</th><th>สถานะ</th><th class="no-sort"></th></tr></thead>
                    <tbody>
                    <?php foreach ($weekRows as $w): ?>
                        <tr>
                            <td><?= e($w['teacher_name']) ?></td>
                            <td class="small"><?= e($w['group_name']) ?></td>
                            <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$w['department_name'])) ?></td>
                            <td class="small"><?= $w['id'] ? e(advisor_date_label($w['meet_date'])) : '-' ?></td>
                            <td class="text-center small"><?= $w['id'] ? ((int)$w['present_count'] + (int)$w['late_count']) . '/' . (int)$w['total_students'] : '-' ?></td>
                            <td><?= $w['id'] ? status_badge($w['status'], (bool)$w['is_late']) : '<span class="badge bg-danger bg-opacity-75">ขาดส่ง</span>' ?></td>
                            <td class="text-end"><?php if ($w['id'] && can_view_advisor_teacher($user, (int)$w['teacher_id'])): ?>
                                <a href="<?= e(url((int)$w['teacher_id'] === (int)$user['id'] ? 'teacher/advisor_reports.php?id=' . (int)$w['id'] : (can_manage_advisor($user) ? 'review/advisor_reports.php?id=' . (int)$w['id'] : 'reports/advisor_teacher.php?report=' . (int)$w['id']))) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-table me-2"></i>สรุปรายครูที่ปรึกษา · <?= e($period['label']) ?></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order='[[7, "desc"]]'>
                <thead><tr><th>ครูที่ปรึกษา</th><th>แผนก</th><th>กลุ่มเรียน</th><th class="text-center">ต้องส่ง</th><th class="text-center">ส่งแล้ว</th>
                    <th class="text-center">ตรงเวลา</th><th class="text-center">ส่งช้า</th><th class="text-center">ขาดส่ง</th>
                    <th class="text-center">ผ่าน/รอ/แก้</th><th class="text-center">ร้อยละ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($sum['teachers'] as $t): ?>
                    <tr>
                        <td><?= e($t['teacher_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$t['department_name'])) ?></td>
                        <td class="small"><?= e(implode(', ', $t['group_names'])) ?></td>
                        <td class="text-center"><?= $t['expected'] ?></td>
                        <td class="text-center"><?= $t['submitted'] ?></td>
                        <td class="text-center text-success"><?= $t['on_time'] ?></td>
                        <td class="text-center"><?= $t['late'] ? '<span class="badge badge-late">' . $t['late'] . '</span>' : '0' ?></td>
                        <td class="text-center <?= $t['missing'] ? 'text-danger fw-semibold' : '' ?>"><?= $t['missing'] ?></td>
                        <td class="text-center small"><?= $t['approved'] ?>/<?= $t['pending'] ?>/<?= $t['revision'] ?></td>
                        <td class="text-center" data-order="<?= $t['submit_pct'] ?>"><?= $t['submit_pct'] ?>%</td>
                        <td class="text-end"><a href="<?= e(url('reports/advisor_teacher.php?' . http_build_query(array_filter(['teacher' => $t['teacher_id'], 'ay' => $ayId] + advisor_period_query($period))))) ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-eye"></i> รายละเอียด</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$chart = ['labels' => array_map(fn($w) => thai_date($w), array_keys($sum['by_week'])),
    'pct' => array_map(fn($w) => percent($w['submitted'], $w['expected']), array_values($sum['by_week']))];
$pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
    var d = ' . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    new Chart(document.getElementById("weekChart"), { type: "bar",
        data: { labels: d.labels, datasets: [{ label: "ร้อยละการส่ง", data: d.pct, backgroundColor: "#f47b20", borderRadius: 4 }] },
        options: { scales: { y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + "%"; } } } }, plugins: { legend: { display: false } } } });
});';
endif;
?>
<div class="d-none d-print-block small mt-4">ผู้ออกรายงาน: <?= e(full_name($user)) ?> · ระบบ <?= e(system_name()) ?> · <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
