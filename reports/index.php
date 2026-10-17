<?php
/**
 * ไฟล์: reports/index.php
 * ระบบรายงานผลแยก 4 งาน: แผนการสอน / PA / วิจัยในชั้นเรียน / นิเทศการสอน
 *   ?type=lesson_plan|pa|research|supervision|student_eval
 *   ?ay= &round_id= &dept= &status=   ตัวกรอง
 *   ?export=csv                         ส่งออก Excel (CSV ภาษาไทย ใส่ BOM)
 * สิทธิ์: admin / ผู้บริหาร (ทุกแผนก), หัวหน้าแผนก (เฉพาะแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin', 'executive', 'supervisor');
$isSupervisor = $user['role'] === 'supervisor';

// ---------- ตัวกรอง ----------
$types = ['lesson_plan', 'pa', 'research', 'supervision', 'student_eval'];
$type = in_array(get('type'), $types, true) ? get('type') : 'lesson_plan';
// รายงานนิเทศ 2 รูปแบบใช้หน้าตาเดียวกัน ต่างกันที่ข้อความและแหล่งข้อมูล
$isEval = in_array($type, ['supervision', 'student_eval'], true);
$L = $type === 'student_eval'
    ? ['done' => 'นักเรียนประเมินแล้ว', 'notdone' => 'ยังไม่มีผลประเมิน', 'has' => 'มีแบบประเมิน', 'none' => 'ไม่มีแบบประเมิน',
       'col_sched' => 'แบบประเมิน', 'col_done' => 'มีผู้ตอบ', 'col3' => 'นักเรียนที่ตอบ', 'col3_key' => 'responses', 'last' => 'ปิดประเมินล่าสุด']
    : ['done' => 'นิเทศแล้ว', 'notdone' => 'ยังไม่ได้นิเทศ', 'has' => 'มีตาราง', 'none' => 'ไม่มีตาราง',
       'col_sched' => 'ตารางนิเทศ', 'col_done' => 'นิเทศแล้ว', 'col3' => 'รับทราบ', 'col3_key' => 'acked', 'last' => 'นิเทศล่าสุด'];
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$f = [
    'ay'       => get_int('ay', (int)(current_academic_year()['id'] ?? 0)),
    'round_id' => $isEval ? 0 : get_int('round_id'),
    'dept'     => $isSupervisor ? (int)$user['department_id'] : get_int('dept'),
    'status'   => array_key_exists(get('status'), report_status_options($type)) ? get('status') : '',
    // ระดับ ปวช./ปวส. (ใช้กับรายงานนิเทศโดยนักเรียน)
    'level'    => $type === 'student_eval' && isset(student_levels()[get('level')]) ? get('level') : '',
];
if ($f['round_id'] > 0) {
    // ระบุรอบแล้ว ไม่ต้องกรองภาคเรียนซ้ำ
    $f['ay'] = 0;
}
$rounds = $isEval ? [] : db_all('SELECT id, name FROM submission_rounds WHERE type = ? ORDER BY close_at DESC', 's', [$type]);
$departments = $isSupervisor ? [] : db_all('SELECT id, name FROM departments ORDER BY name');

// ---------- ข้อมูล ----------
if ($isEval) {
    $all = $type === 'student_eval' ? report_student_eval_rows($f) : report_supervision_rows($f);
    $categories = $type === 'student_eval' ? report_student_eval_categories($f) : report_supervision_categories($f);
    $byLevel = $type === 'student_eval' ? report_student_eval_by_level($f) : [];
    $deptSup = report_supervision_departments($all);
    $doneRows = array_filter($all, fn($r) => (int)$r['done'] > 0);
    $summary = [
        'total'     => count($all),
        'done'      => count($doneRows),
        'scheduled' => count(array_filter($all, fn($r) => $r['person_status'] === 'scheduled')),
        'none'      => count(array_filter($all, fn($r) => $r['person_status'] === 'none')),
        'avg'       => $doneRows ? round(array_sum(array_column($doneRows, 'avg_score')) / count($doneRows), 2) : null,
    ];
} else {
    $all = report_submission_rows($type, $f);
    $summary = report_submission_summary($all);
    $byDept = report_group_by_department($all);
}
$rows = report_filter($type, $all, $f['status']);

// ข้อความตัวกรองสำหรับหัวรายงาน/ไฟล์
$filterText = [];
foreach ($years as $y) {
    if ((int)$y['id'] === $f['ay']) {
        $filterText[] = 'ภาคเรียน ' . academic_label($y);
    }
}
foreach ($rounds as $r) {
    if ((int)$r['id'] === $f['round_id']) {
        $filterText[] = 'รอบ: ' . $r['name'];
    }
}
$deptName = $isSupervisor ? ($user['department_name'] ?? '') : '';
foreach ($departments as $d) {
    if ((int)$d['id'] === $f['dept']) {
        $deptName = $d['name'];
    }
}
$filterText[] = $deptName ?: 'ทุกแผนก';
if ($f['level'] !== '') {
    $filterText[] = 'ระดับ ' . $f['level'];
}
if ($f['status'] !== '') {
    $filterText[] = 'สถานะ: ' . report_status_options($type)[$f['status']];
}
$typeLabel = submission_type_label($type);

// =====================================================================
// ส่งออก CSV
// =====================================================================
if (get('export') === 'csv') {
    log_activity('report_export', 'ส่งออกรายงาน' . $typeLabel . ' (' . implode(', ', $filterText) . ')');
    $filename = 'รายงาน' . $typeLabel . '_' . date('Ymd_His') . '.csv';
    $i = 0;
    if ($isEval) {
        $header = ['ลำดับ', 'ชื่อ-สกุล', 'แผนกวิชา', 'จำนวน' . $L['col_sched'], $L['col_done'], $L['col3'], 'คะแนนเฉลี่ย', 'ระดับคุณภาพ', $L['last'], 'สถานะ'];
        $data = array_map(function ($r) use (&$i, $type, $L) {
            return [++$i, $r['teacher_name'], $r['department_name'], $r['scheduled'], $r['done'], $r[$L['col3_key']],
                $r['avg_score'] ?? '', $r['avg_score'] !== null ? quality_level((float)$r['avg_score']) : '',
                $r['last_date'] ? thai_date($r['last_date']) : '', report_status_options($type)[$r['person_status']]];
        }, $rows);
    } else {
        $header = ['ลำดับ', 'ชื่อ-สกุล', 'แผนกวิชา', 'จำนวนที่ส่ง', 'ผ่าน', 'รอตรวจ', 'ให้แก้ไข', 'ส่งช้า', 'ส่งล่าสุด', 'สถานะ'];
        $data = array_map(function ($r) use (&$i) {
            return [++$i, $r['teacher_name'], $r['department_name'], $r['sent'], $r['approved'], $r['pending'], $r['revision'],
                $r['late'], $r['last_at'] ? thai_datetime($r['last_at']) : '', status_labels()[$r['person_status']]
                . ((int)$r['late'] > 0 ? ' (ส่งช้า)' : '')];
        }, $rows);
    }
    $data[] = [];
    $data[] = ['ตัวกรอง', implode(' / ', $filterText)];
    $data[] = ['ออกรายงานเมื่อ', thai_datetime(date('Y-m-d H:i:s')), 'โดย', full_name($user)];
    csv_download($filename, $header, $data);
}

// =====================================================================
// แสดงผล
// =====================================================================
$qs = function (array $over) use ($type, $f, $isSupervisor): string {
    $base = ['type' => $type, 'ay' => $f['ay'], 'round_id' => $f['round_id'] ?: null, 'dept' => $isSupervisor ? null : ($f['dept'] ?: null), 'status' => $f['status'] ?: null, 'level' => $f['level'] ?: null];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
};

$pageTitle = 'รายงานผล' . $typeLabel;
$pageIcon = 'bi-bar-chart-line';
$breadcrumbs = ['รายงานผล' => null];
$activeMenu = 'reports/index.php';
$pageActions = '<a href="' . e($qs(['export' => 'csv'])) . '" class="btn btn-success"><i class="bi bi-file-earmark-excel me-1"></i>ส่งออก Excel</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์ / PDF</button>';
require APP_ROOT . '/includes/header.php';
?>
<!-- หัวรายงานสำหรับพิมพ์ -->
<div class="d-none d-print-block text-center mb-3">
    <h4 class="mb-0"><?= e(college_name()) ?></h4>
    <h5>รายงานผล<?= e($typeLabel) ?></h5>
    <div><?= e(implode(' · ', $filterText)) ?></div>
    <div class="small">ออกรายงานเมื่อ <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
</div>

<!-- แท็บประเภทงาน -->
<ul class="nav nav-pills mb-3 no-print gap-1">
    <?php foreach ($types as $t): ?>
        <li class="nav-item">
            <a class="nav-link <?= $type === $t ? 'active bg-primary-th' : 'bg-white border' ?>"
               href="?<?= e(http_build_query(['type' => $t, 'ay' => $f['ay']])) ?>">
                <i class="bi <?= e(submission_type_icon($t)) ?> me-1"></i><?= e(submission_type_label($t)) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<!-- ตัวกรอง -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <div class="col-md-<?= $isEval ? 3 : 2 ?>">
                <label class="form-label small mb-1" for="ay">ปีการศึกษา/ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <option value="0">ทั้งหมด</option>
                    <?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= $f['ay'] === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if (!$isEval): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="round_id">รอบการส่ง</label>
                    <select name="round_id" id="round_id" class="form-select">
                        <option value="0">ทุกรอบ</option>
                        <?php foreach ($rounds as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $f['round_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if (!$isSupervisor): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $f['dept'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($type === 'student_eval'): ?>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="level">ระดับ</label>
                    <select name="level" id="level" class="form-select">
                        <option value="">ทุกระดับ</option>
                        <?php foreach (array_keys(student_levels()) as $lv): ?><option value="<?= e($lv) ?>" <?= $f['level'] === $lv ? 'selected' : '' ?>><?= e($lv) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="status">สถานะ</label>
                <select name="status" id="status" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach (report_status_options($type) as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> แสดง</button>
                <a href="?type=<?= e($type) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
        <?php if ($isSupervisor): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> แสดงเฉพาะ<?= e($user['department_name'] ?? 'แผนกของท่าน') ?></div><?php endif; ?>
    </div>
</div>

<?php if ($isEval): ?>
    <!-- ===================== รายงานนิเทศ ===================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= $summary['total'] ?></div><div class="stat-label">ครูทั้งหมด</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= $summary['done'] ?></div><div class="stat-label"><?= e($L['done']) ?> (<?= percent($summary['done'], $summary['total']) ?>%)</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-yellow"><i class="bi bi-calendar-week"></i></div><div><div class="stat-value"><?= $summary['scheduled'] + $summary['none'] ?></div><div class="stat-label"><?= e($L['notdone']) ?></div><div class="stat-sub"><?= e($L['has']) ?> <?= $summary['scheduled'] ?> · <?= e($L['none']) ?> <?= $summary['none'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-star"></i></div><div><div class="stat-value"><?= $summary['avg'] !== null ? number_format($summary['avg'], 2) : '-' ?></div><div class="stat-label">คะแนนเฉลี่ยรวม</div><div class="stat-sub"><?= $summary['avg'] !== null ? e(quality_level($summary['avg'])) : '' ?></div></div></div></div>
    </div>
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><i class="bi bi-diagram-3 me-2"></i>คะแนนเฉลี่ยรายแผนก</div>
                <div class="card-body"><div class="chart-box"><canvas id="deptChart"></canvas></div></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><i class="bi bi-list-check me-2"></i>คะแนนเฉลี่ยรายด้าน</div>
                <div class="card-body">
                    <?php if ($categories): ?>
                        <div class="chart-box"><canvas id="catChart"></canvas></div>
                    <?php else: ?><div class="empty-state">ยังไม่มีผลการประเมิน</div><?php endif; ?>
                </div></div>
        </div>
    </div>
    <?php if ($type === 'student_eval'): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-mortarboard me-2"></i>สรุปตามระดับและชั้นปีของนักเรียนผู้ประเมิน</div>
            <div class="table-responsive">
                <table class="table mb-0 text-center">
                    <thead><tr><th class="text-start">ระดับ / ชั้นปี</th><th>แบบประเมิน</th><th>นักเรียนที่ตอบ (คน)</th><th>คะแนนเฉลี่ย</th><th>ระดับคุณภาพ</th></tr></thead>
                    <tbody>
                    <?php
                    $levelTotals = [];
                    foreach ($byLevel as $b) {
                        $levelTotals[$b['level']]['sessions'] = ($levelTotals[$b['level']]['sessions'] ?? 0) + (int)$b['sessions'];
                    }
                    $lastLv = null;
                    foreach ($byLevel as $b): ?>
                        <?php if ($b['level'] !== $lastLv): $lastLv = $b['level'];
                            // แถวรวมของระดับ (คำนวณจากคำตอบทั้งหมดของระดับนั้น)
                            $lvRows = array_filter($byLevel, fn($x) => $x['level'] === $b['level']);
                            $lvResp = array_sum(array_column($lvRows, 'responses'));
                            $lvAvg = $lvResp ? round(array_sum(array_map(fn($x) => (float)$x['avg_score'] * (int)$x['responses'], $lvRows)) / $lvResp, 2) : null; ?>
                            <tr class="table-light fw-semibold"><td class="text-start"><?= e($b['level']) ?> (รวม)</td><td><?= $levelTotals[$b['level']]['sessions'] ?></td><td><?= $lvResp ?></td>
                                <td><?= $lvAvg !== null ? number_format($lvAvg, 2) : '-' ?></td><td><?= $lvAvg !== null ? e(quality_level($lvAvg)) : '-' ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="text-start ps-4"><?= $b['grade_year'] ? 'ชั้นปีที่ ' . (int)$b['grade_year'] : 'ไม่ระบุชั้นปี' ?></td><td><?= (int)$b['sessions'] ?></td><td><?= (int)$b['responses'] ?></td>
                            <td><?= $b['avg_score'] !== null ? number_format((float)$b['avg_score'], 2) : '-' ?></td><td class="small"><?= $b['avg_score'] !== null ? e(quality_level((float)$b['avg_score'])) : '-' ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$byLevel): ?><tr><td colspan="5" class="empty-state">ยังไม่มีแบบประเมิน</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-table me-2"></i>สรุปรายแผนก</div>
        <div class="table-responsive">
            <table class="table mb-0 text-center">
                <thead><tr><th class="text-start">แผนก</th><th>ครู</th><th><?= e($L['col_done']) ?></th><th>ร้อยละ</th><th>คะแนนเฉลี่ย</th><th>ระดับคุณภาพ</th></tr></thead>
                <tbody>
                <?php foreach ($deptSup as $name => $d): ?>
                    <tr><td class="text-start"><?= e($name) ?></td><td><?= $d['teachers'] ?></td><td><?= $d['done'] ?></td><td><?= percent($d['done'], $d['teachers']) ?>%</td>
                        <td><?= $d['avg'] !== null ? number_format($d['avg'], 2) : '-' ?></td><td><?= $d['avg'] !== null ? e(quality_level($d['avg'])) : '-' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>คะแนนเฉลี่ยรายครู (<?= count($rows) ?> คน)</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable" data-order="[]" data-page-length="100">
                    <thead><tr><th>#</th><th>ชื่อ-สกุล</th><th>แผนก</th><th><?= e($L['col_sched']) ?></th><th><?= e($L['col_done']) ?></th><th><?= e($L['col3']) ?></th><th>คะแนนเฉลี่ย</th><th><?= e($L['last']) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($r['teacher_name']) ?></td>
                            <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', $r['department_name'])) ?></td>
                            <td><?= (int)$r['scheduled'] ?></td>
                            <td><?= (int)$r['done'] ?></td>
                            <td><?= (int)$r[$L['col3_key']] ?></td>
                            <td data-order="<?= e((string)$r['avg_score']) ?>"><?= $r['avg_score'] !== null ? quality_badge((float)$r['avg_score']) : '<span class="badge badge-none">' . e(report_status_options($type)[$r['person_status']]) . '</span>' ?></td>
                            <td class="small" data-order="<?= e((string)$r['last_date']) ?>"><?= e(thai_date($r['last_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    $chart = ['dept' => ['labels' => array_keys($deptSup), 'avg' => array_map(fn($d) => $d['avg'] ?? 0, array_values($deptSup))],
        'cat' => ['labels' => array_column($categories, 'category'), 'avg' => array_map('floatval', array_column($categories, 'avg_score'))]];
    $pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
        var d = ' . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', p = TeachHub.palette;
        new Chart(document.getElementById("deptChart"), { type: "bar",
            data: { labels: d.dept.labels, datasets: [{ label: "คะแนนเฉลี่ย", data: d.dept.avg, backgroundColor: p[0], borderRadius: 4 }] },
            options: { scales: { y: { beginAtZero: true, max: 5 } }, plugins: { legend: { display: false } } } });
        var c = document.getElementById("catChart");
        if (c) new Chart(c, { type: "bar",
            data: { labels: d.cat.labels, datasets: [{ label: "คะแนนเฉลี่ย", data: d.cat.avg, backgroundColor: p[1], borderRadius: 4 }] },
            options: { indexAxis: "y", scales: { x: { beginAtZero: true, max: 5 } }, plugins: { legend: { display: false } } } });
    });';
    ?>
<?php else: ?>
    <!-- ===================== รายงานงานที่ส่ง ===================== -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['submitted', 'ส่งแล้ว', 'ic-blue', 'bi-send-check'], ['not_submitted', 'ยังไม่ส่ง', 'ic-navy', 'bi-dash-circle'],
            ['late', 'ส่งช้า', 'ic-orange', 'bi-clock-history'], ['approved', 'ผ่าน', 'ic-green', 'bi-check2-circle'],
            ['revision', 'ให้แก้ไข', 'ic-red', 'bi-pencil-square'], ['pending', 'รอตรวจ', 'ic-yellow', 'bi-hourglass-split'],
        ];
        foreach ($cards as [$k, $label, $ic, $icon]): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= e($qs(['status' => $k])) ?>" class="stat-card flex-column align-items-start gap-2">
                    <div class="d-flex align-items-center gap-2 w-100">
                        <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                        <div class="stat-value"><?= $summary[$k] ?></div>
                    </div>
                    <div class="stat-label"><?= e($label) ?> <strong><?= $summary[$k . '_pct'] ?>%</strong></div>
                    <div class="progress progress-thin w-100"><div class="progress-bar bg-accent" style="width: <?= $summary[$k . '_pct'] ?>%"></div></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="small text-muted mb-3">ครูทั้งหมด <?= $summary['total'] ?> คน · จำนวนงานที่ส่ง <?= $summary['files'] ?> รายการ · ร้อยละคิดจากจำนวนครู · สถานะรายคน: มีงานให้แก้ไข → "ให้แก้ไข", มีงานรอตรวจ → "รอตรวจ", ผ่านทุกงาน → "ผ่าน"</div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-bar-chart me-2"></i>สถานะการส่งงานแยกตามแผนก</div>
        <div class="card-body"><div class="chart-box"><canvas id="deptChart"></canvas></div></div>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>รายชื่อครู (<?= count($rows) ?> คน)</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable" data-order="[]" data-page-length="100">
                    <thead><tr><th>#</th><th>ชื่อ-สกุล</th><th>แผนก</th><th>ส่ง</th><th>ผ่าน</th><th>รอตรวจ</th><th>แก้ไข</th><th>ส่งล่าสุด</th><th>สถานะ</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($r['teacher_name']) ?></td>
                            <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', $r['department_name'])) ?></td>
                            <td><?= (int)$r['sent'] ?></td>
                            <td><?= (int)$r['approved'] ?></td>
                            <td><?= (int)$r['pending'] ?></td>
                            <td><?= (int)$r['revision'] ?></td>
                            <td class="small" data-order="<?= e((string)$r['last_at']) ?>"><?= e(thai_datetime($r['last_at'])) ?></td>
                            <td data-order="<?= e($r['person_status']) ?>"><?= status_badge($r['person_status'], (int)$r['late'] > 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    $chart = ['labels' => array_keys($byDept)];
    foreach (['approved', 'pending', 'revision', 'not_submitted'] as $k) {
        $chart[$k] = array_map(fn($d) => $d[$k] ?? 0, array_values($byDept));
    }
    $pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
        var d = ' . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ', c = TeachHub.statusColors;
        new Chart(document.getElementById("deptChart"), { type: "bar",
            data: { labels: d.labels, datasets: [
                { label: "ผ่าน", data: d.approved, backgroundColor: c.approved },
                { label: "รอตรวจ", data: d.pending, backgroundColor: c.pending },
                { label: "ให้แก้ไข", data: d.revision, backgroundColor: c.revision },
                { label: "ยังไม่ส่ง", data: d.not_submitted, backgroundColor: c.none }
            ]},
            options: { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: "จำนวนครู (คน)" } } } } });
    });';
    ?>
<?php endif; ?>

<div class="d-none d-print-block small mt-4">ผู้ออกรายงาน: <?= e(full_name($user)) ?> · ระบบ <?= e(system_name()) ?></div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
