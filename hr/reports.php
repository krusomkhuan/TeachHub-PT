<?php
/**
 * ไฟล์: hr/reports.php
 * รายงานสรุปบุคลากร (ผู้ดูแลงานบุคลากร + ผู้บริหาร)
 *   ?tab=overview (ภาพรวม) | staff | leave | training | events   ?fy= ปีงบประมาณ   ?dept= แผนก
 *   ?export=csv  ส่งออก Excel (CSV)   ·   พิมพ์/บันทึกเป็น PDF ที่ hr/report_print.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_view_hr_overview($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับงานบุคลากรและผู้บริหาร');
}
$self = 'hr/reports.php';
$activeMenu = $self;
$tab = isset(hr_report_types()[get('tab')]) ? get('tab') : 'overview';
$fy = hr_fiscal_param();
$dept = get_int('dept');
$qs = fn(array $over = []) => '?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'fy' => $fy, 'dept' => $dept ?: null], $over), fn($v) => $v !== null && $v !== ''));

if ($tab !== 'overview') {
    $report = hr_report_data($tab, $fy, $dept);
    if (get('export') === 'csv') {
        log_activity('hr_export', 'ส่งออก CSV: ' . $report['title']);
        // ช่องว่าง "-" ส่งเป็นค่าว่าง (csv_download เติม ' หน้าค่าที่ขึ้นต้นด้วย - กันสูตร Excel)
        $csvRows = array_map(fn($r) => array_map(fn($c) => $c === '-' ? '' : $c, $r), $report['rows']);
        csv_download('hr_' . $tab . ($tab === 'staff' ? '' : '_' . $fy) . '.csv', $report['header'], $csvRows);
    }
}

$departments = db_all('SELECT id, name FROM departments ORDER BY name');
$pageTitle = 'รายงานสรุปบุคลากร';
$pageIcon = 'bi-bar-chart-line';
$breadcrumbs = ['รายงานสรุปบุคลากร' => null];
if ($tab !== 'overview') {
    $pageActions = '<a href="' . e($qs(['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i> ส่งออก Excel</a>'
        . ' <a href="' . e(url('hr/report_print.php' . $qs())) . '" target="_blank" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i> พิมพ์ / PDF</a>';
}
require APP_ROOT . '/includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
    <?php foreach (['overview' => 'ภาพรวม'] + hr_report_types() as $k => $label): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e('?' . http_build_query(['tab' => $k, 'fy' => $fy, 'dept' => $dept ?: null])) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <?php if ($tab !== 'staff'): ?>
                <div class="col-md-3"><label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                    <select name="fy" id="fy" class="form-select"><?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-md-5"><label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                <select name="dept" id="dept" class="form-select"><option value="0">ทุกแผนก/งาน</option>
                    <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> แสดง</button></div>
        </form>
    </div>
</div>

<?php if ($tab === 'overview'):
    $people = hr_people($dept);
    $edu = hr_highest_educations();
    $group = function (callable $key) use ($people): array {
        $out = [];
        foreach ($people as $p) {
            $k = $key($p) ?: 'ไม่ระบุ';
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    };
    $blocks = [
        ['ประเภทบุคลากร', 'bi-people', $group(fn($p) => $p['staff_type'])],
        ['ตำแหน่ง', 'bi-briefcase', $group(fn($p) => $p['position'])],
        ['วิทยฐานะ', 'bi-award', $group(fn($p) => $p['academic_standing'] ?: 'ไม่มีวิทยฐานะ')],
        ['วุฒิการศึกษาสูงสุด', 'bi-mortarboard', $group(fn($p) => $edu[(int)$p['id']] ?? '')],
        ['แผนกวิชา/งาน', 'bi-diagram-3', $group(fn($p) => preg_replace('/^แผนกวิชา/u', '', (string)$p['department_name']) ?: 'ไม่สังกัดแผนกวิชา')],
    ];
    $total = count($people);
    $withProfile = count(array_filter($people, fn($p) => (int)$p['has_profile'] === 1));
    $verified = count(array_filter($people, fn($p) => !empty($p['verified_at'])));
    $today = date('Y-m-d');
    $onLeave = db_all(
        "SELECT l.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name FROM hr_leaves l JOIN users u ON u.id = l.user_id
         WHERE l.status = 'approved' AND ? BETWEEN l.start_date AND l.end_date AND (? = 0 OR u.department_id = ?) ORDER BY u.first_name",
        'sii',
        [$today, $dept, $dept]
    );
    $pendingLeaves = (int)db_value("SELECT COUNT(*) FROM hr_leaves WHERE status LIKE 'pending%'");
    ?>
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-people"></i></div>
                <div><div class="stat-value"><?= $total ?></div><div class="stat-label">บุคลากรที่ใช้งานระบบ</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><a class="stat-card" href="<?= e(url('hr/staff.php')) ?>"><div class="stat-icon ic-green"><i class="bi bi-person-vcard"></i></div>
                <div><div class="stat-value"><?= $withProfile ?></div><div class="stat-label">กรอกทะเบียนประวัติแล้ว</div><div class="stat-sub">ตรวจสอบแล้ว <?= $verified ?> คน</div></div></a></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-orange"><i class="bi bi-calendar-x"></i></div>
                <div><div class="stat-value"><?= count($onLeave) ?></div><div class="stat-label">ลาวันนี้ (<?= e(thai_date($today)) ?>)</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><a class="stat-card" href="<?= e(url('hr/leave_review.php?status=pending')) ?>"><div class="stat-icon ic-yellow"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="stat-value"><?= $pendingLeaves ?></div><div class="stat-label">ใบลาระหว่างพิจารณา</div></div></a></div>
    </div>
    <?php if ($onLeave): ?>
        <div class="card mb-4"><div class="card-header"><i class="bi bi-calendar-x me-2"></i>บุคลากรที่ลาวันนี้</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($onLeave as $l): ?>
                    <li class="list-group-item d-flex"><span><?= e($l['user_name']) ?></span><span class="ms-auto small"><?= e(hr_leave_type_label($l['leave_type'])) ?> · <?= e(hr_leave_range_text($l)) ?></span></li>
                <?php endforeach; ?>
            </ul></div>
    <?php endif; ?>
    <div class="row g-4">
        <?php foreach ($blocks as [$label, $icon, $data]): ?>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?></div>
                    <div class="card-body">
                        <?php foreach ($data as $k => $n): $pct = $total ? round($n * 100 / $total) : 0; ?>
                            <div class="d-flex small mb-1"><span><?= e($k) ?></span><span class="ms-auto fw-semibold"><?= $n ?> คน</span></div>
                            <div class="progress mb-2" style="height:8px" role="progressbar" aria-label="<?= e($k) ?>" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" style="width:<?= $pct ?>%;background:var(--th-primary)"></div></div>
                        <?php endforeach; ?>
                        <?php if (!$data): ?><div class="text-muted">ไม่มีข้อมูล</div><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><i class="bi bi-table me-2"></i><?= e($report['title']) ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover datatable">
                    <thead><tr><?php foreach ($report['header'] as $i => $h): ?><th<?= in_array($i, $report['num'], true) ? ' class="text-end"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($report['rows'] as $r): ?>
                        <tr><?php foreach ($r as $i => $c): ?><td<?= in_array($i, $report['num'], true) ? ' class="text-end"' : '' ?>><?= e($c) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
