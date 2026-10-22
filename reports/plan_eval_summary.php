<?php
/**
 * ไฟล์: reports/plan_eval_summary.php
 * รายงานผลการประเมินแผนการจัดการเรียนรู้ 2 ระดับ
 *   - ภาพรวมทั้งวิทยาลัย (?dept=0) : สรุปรายแผนกวิชา + รายด้าน/รายข้อของทั้งวิทยาลัย
 *   - ระดับแผนกวิชา (?dept=id)    : สรุปของแผนก + รายการแผนของครูในแผนก
 *   ?ay= ภาคเรียน (all = ทุกภาคเรียน), ?export=csv
 * สิทธิ์: admin, ผู้บริหาร, หัวหน้างาน/เจ้าหน้าที่งานพัฒนาหลักสูตรฯ (ทุกแผนก) / หัวหน้าแผนก (เฉพาะแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$scope = plan_eval_report_scope($user);
if ($scope === null) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
}
$ayId = plan_eval_selected_ay();
$dept = $scope === 0 ? get_int('dept') : $scope;
$departments = $scope === 0 ? db_all('SELECT id, name FROM departments ORDER BY name') : [];
$deptName = $dept ? (string)db_value('SELECT name FROM departments WHERE id = ?', 'i', [$dept]) : '';
$ays = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ayLabel = 'ทุกภาคเรียน';
foreach ($ays as $a) {
    if ((int)$a['id'] === $ayId) {
        $ayLabel = 'ภาคเรียน ' . academic_label($a);
    }
}
$rep = plan_eval_report($ayId, $dept);
$tot = $rep['totals'];
$levelLabel = $dept ? 'ระดับแผนกวิชา: ' . $deptName : 'ภาพรวมทั้งวิทยาลัย';
$decisions = plan_eval_decisions();

if (get('export') === 'csv') {
    $rows = [];
    foreach ($rep['plans'] as $p) {
        if ($p['eval_id'] && $p['eval_status'] !== 'final') {
            $rows[] = [$p['teacher_name'], $p['department_name'], $p['subject_code'], $p['subject_name'], $p['level'], '', '', 'ร่าง (ยังไม่ส่งผล)', '', '', $p['evaluator_name'] ?? '', ''];
            continue;
        }
        $rows[] = [$p['teacher_name'], $p['department_name'], $p['subject_code'], $p['subject_name'], $p['level'],
            $p['eval_id'] ? $p['total_score'] . '/' . $p['max_score'] : '', $p['eval_id'] ? $p['avg_score'] : '',
            $p['eval_id'] ? plan_eval_level((float)$p['avg_score']) : 'ยังไม่ประเมิน',
            $p['eval_id'] ? ((int)$p['is_passed'] ? 'ผ่าน' : 'ไม่ผ่าน') : '', $p['eval_id'] ? ($decisions[$p['decision']] ?? '') : '',
            $p['evaluator_name'] ?? '', $p['acknowledged_at'] ? thai_date($p['acknowledged_at']) : ''];
    }
    $rows[] = [];
    $rows[] = ['สรุป', $levelLabel, $ayLabel, 'แผนที่ส่ง ' . $tot['plans'], 'ประเมินแล้ว ' . $tot['evaluated'], 'ผ่านเกณฑ์ ' . $tot['passed'],
        'ค่าเฉลี่ยรวม ' . number_format($tot['avg'], 2)];
    foreach ($rep['by_category'] as $cat => $c) {
        $rows[] = ['รายด้าน', $cat, '', '', '', '', $c['avg'], plan_eval_level($c['avg'])];
    }
    log_activity('report_export', 'ส่งออกรายงานผลประเมินแผนฯ ' . $levelLabel . ' ' . $ayLabel);
    csv_download('plan_evaluation_' . ($dept ?: 'all') . '_' . ($ayId ?: 'all') . '.csv',
        ['ครูผู้สอน', 'แผนก', 'รหัสวิชา', 'ชื่อวิชา', 'ระดับ', 'คะแนนรวม', 'ค่าเฉลี่ย', 'ระดับคุณภาพ', 'เกณฑ์', 'ผลการพิจารณา', 'ผู้ประเมิน', 'ครูรับทราบ'],
        $rows);
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['ay' => $ayId ?: 'all', 'dept' => $scope === 0 ? ($dept ?: null) : null], $over), fn($v) => $v !== null && $v !== ''));
$canEvaluate = is_plan_evaluator($user);
$weakItems = $rep['by_item'];
uasort($weakItems, fn($a, $b) => $a['avg'] <=> $b['avg']);
$weakKeys = array_slice(array_keys($weakItems), 0, min(5, max(0, count($weakItems) - 1)));

$pageTitle = 'รายงานผลการประเมินแผนการจัดการเรียนรู้';
$pageIcon = 'bi-bar-chart-line';
$breadcrumbs = ['รายงานผลการประเมินแผนฯ' => null];
$activeMenu = 'reports/plan_eval_summary.php';
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
                    <option value="all" <?= $ayId === 0 ? 'selected' : '' ?>>ทุกภาคเรียน</option>
                    <?php foreach ($ays as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $ayId === (int)$a['id'] ? 'selected' : '' ?>><?= e(academic_label($a)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($scope === 0): ?>
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="dept">ระดับรายงาน</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ภาพรวมทั้งวิทยาลัย</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>>ระดับแผนกวิชา: <?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> แสดง</button></div>
        </form>
    </div>
</div>

<h5 class="mb-3"><i class="bi bi-diagram-3 me-1 text-accent"></i><?= e($levelLabel) ?> <span class="text-muted fs-6">· <?= e($ayLabel) ?></span></h5>

<div class="row g-3 mb-4">
    <?php foreach ([
        [$tot['plans'], 'แผนที่ครูส่ง', 'ic-navy', 'bi-journal-text', null, $tot['teachers'] . ' คน'],
        [$tot['evaluated'], 'ประเมินแล้ว (ส่งผลแล้ว)', 'ic-blue', 'bi-clipboard-check', percent($tot['evaluated'], $tot['plans']), $tot['drafts'] ? 'ร่าง ' . $tot['drafts'] : null],
        [$tot['passed'], 'ผ่านเกณฑ์', 'ic-green', 'bi-patch-check', percent($tot['passed'], $tot['evaluated']), null],
        [$tot['evaluated'] - $tot['passed'], 'ไม่ผ่านเกณฑ์', 'ic-red', 'bi-x-octagon', percent($tot['evaluated'] - $tot['passed'], $tot['evaluated']), null],
        [$tot['evaluated'] ? number_format($tot['avg'], 2) : '-', 'ค่าเฉลี่ยรวม', 'ic-purple', 'bi-star', null, $tot['evaluated'] ? plan_eval_level($tot['avg']) : ''],
        [$tot['acknowledged'] . '/' . $tot['evaluated'], 'ครูรับทราบผล', 'ic-yellow', 'bi-bell', null, null],
    ] as [$val, $label, $ic, $icon, $pct, $sub]): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stat-card flex-column align-items-start gap-2">
                <div class="d-flex align-items-center gap-2 w-100">
                    <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                    <div class="stat-value" style="font-size:1.4rem"><?= e((string)$val) ?></div>
                </div>
                <div class="stat-label"><?= e($label) ?><?= $pct !== null ? ' <strong>' . $pct . '%</strong>' : '' ?><?= $sub ? ' · ' . e($sub) : '' ?></div>
                <?php if ($pct !== null): ?><div class="progress progress-thin w-100"><div class="progress-bar bg-accent" style="width: <?= $pct ?>%"></div></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$tot['plans']): ?>
    <div class="alert alert-info">ยังไม่มีแผนการจัดการเรียนรู้ที่ส่งในระบบ<?= $dept ? 'ของแผนกนี้' : '' ?> ใน<?= e($ayLabel) ?></div>
<?php else: ?>

<?php if (!$dept && $scope === 0): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>สรุปผลรายแผนกวิชา</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>แผนกวิชา</th><th class="text-center">แผนที่ส่ง</th><th class="text-center">ประเมินแล้ว</th><th style="width:20%">ร้อยละที่ประเมิน</th>
                        <th class="text-center">ผ่านเกณฑ์</th><th class="text-center">ร้อยละผ่าน</th><th class="text-center">ค่าเฉลี่ย</th><th class="d-print-none"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rep['by_dept'] as $did => $d): $pct = percent($d['evaluated'], $d['plans']); ?>
                        <tr>
                            <td><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$d['name'])) ?></td>
                            <td class="text-center"><?= $d['plans'] ?></td>
                            <td class="text-center"><?= $d['evaluated'] ?></td>
                            <td><div class="d-flex align-items-center gap-2"><div class="progress progress-thin flex-fill"><div class="progress-bar bg-accent" style="width: <?= $pct ?>%"></div></div><small><?= $pct ?>%</small></div></td>
                            <td class="text-center"><?= $d['passed'] ?></td>
                            <td class="text-center"><?= $d['evaluated'] ? percent($d['passed'], $d['evaluated']) . '%' : '-' ?></td>
                            <td class="text-center"><?= $d['evaluated'] ? plan_eval_level_badge($d['avg']) : '-' ?></td>
                            <td class="text-end d-print-none"><?php if ($did > 0): ?><a href="<?= e($qs(['dept' => $did])) ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-zoom-in"></i> ระดับแผนก</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tot['evaluated']): ?>
    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-bullseye me-2"></i>ค่าเฉลี่ยรายด้าน (ตอนที่ 2)</div>
                <div class="card-body">
                    <div class="chart-box-sm d-print-none"><canvas id="catChart"></canvas></div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>ด้านที่ประเมิน</th><th class="text-center">จำนวนคะแนน (ข้อ×แผน)</th><th class="text-center">ค่าเฉลี่ย</th></tr></thead>
                            <tbody>
                            <?php foreach ($rep['by_category'] as $cat => $c): ?>
                                <tr><td class="small"><?= e($cat) ?></td><td class="text-center"><?= $c['n'] ?></td><td class="text-center"><?= plan_eval_level_badge($c['avg']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-pie-chart me-2"></i>จำนวนแผนตามระดับคุณภาพ</div>
                <div class="card-body">
                    <div class="chart-box-sm d-print-none"><canvas id="levelChart"></canvas></div>
                    <?php foreach ($rep['levels'] as $lv => $n): ?>
                        <div class="d-flex justify-content-between small border-bottom py-1"><span><?= e($lv) ?></span><span><?= $n ?> แผน (<?= percent($n, $tot['evaluated']) ?>%)</span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-list-ol me-2"></i>ค่าเฉลี่ยรายข้อ (ตอนที่ 2)
                    <?php if ($weakKeys): ?><span class="small text-muted fw-normal ms-2"><span class="badge bg-danger-subtle text-danger border">ต่ำสุด</span> = ข้อที่ควรพัฒนาเป็นลำดับแรก</span><?php endif; ?></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th style="width:40px">ข้อ</th><th>รายการประเมิน</th><th class="text-center" style="width:120px">ค่าเฉลี่ย</th></tr></thead>
                        <tbody>
                        <?php $no = 0; $lastCat = null; foreach ($rep['by_item'] as $key => $it): $no++; ?>
                            <?php if ($it['category'] !== $lastCat): $lastCat = $it['category']; ?>
                                <tr class="table-light"><td colspan="3" class="fw-semibold small text-primary-th"><?= e($it['category']) ?></td></tr>
                            <?php endif; ?>
                            <tr class="<?= in_array($key, $weakKeys, true) ? 'table-danger' : '' ?>">
                                <td class="text-center small"><?= $no ?></td><td class="small"><?= e($it['title']) ?></td>
                                <td class="text-center"><?= plan_eval_level_badge($it['avg']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-list-check me-2"></i>ตอนที่ 1 ร้อยละของแผนที่ "มี" องค์ประกอบ</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>รายการ</th><th class="text-center">มี/ไม่มี/ไม่เกี่ยวข้อง</th><th style="width:30%">ร้อยละที่มี</th></tr></thead>
                        <tbody>
                        <?php foreach ($rep['check_items'] as $it): $base = $it['yes'] + $it['no']; $pct = percent($it['yes'], $base); ?>
                            <tr>
                                <td class="small"><?= e($it['title']) ?><?= $it['is_required'] ? ' <span class="badge bg-light text-dark border fw-normal">ต้องมี</span>' : '' ?></td>
                                <td class="text-center small"><?= $it['yes'] ?>/<span class="<?= $it['no'] ? 'text-danger fw-semibold' : '' ?>"><?= $it['no'] ?></span>/<?= $it['na'] ?></td>
                                <td><div class="d-flex align-items-center gap-2"><div class="progress progress-thin flex-fill"><div class="progress-bar <?= $pct < 100 && $it['is_required'] ? 'bg-danger' : 'bg-success' ?>" style="width: <?= $pct ?>%"></div></div><small><?= $base ? $pct . '%' : '-' ?></small></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-table me-2"></i>รายการแผนการจัดการเรียนรู้และผลการประเมิน</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>ครูผู้สอน</th><th>แผนก</th><th>รายวิชา</th><th class="text-center">คะแนน</th><th>ระดับคุณภาพ</th><th>เกณฑ์</th><th>ผลการพิจารณา</th><th>ผู้ประเมิน</th><th class="no-sort d-print-none"></th></tr></thead>
                <tbody>
                <?php foreach ($rep['plans'] as $p): $isFinal = $p['eval_id'] && $p['eval_status'] === 'final'; ?>
                    <tr>
                        <td><?= e($p['teacher_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$p['department_name'])) ?></td>
                        <td class="small"><span class="fw-semibold"><?= e($p['subject_code']) ?></span> <?= e($p['subject_name']) ?></td>
                        <td class="text-center" data-order="<?= $isFinal ? (int)$p['total_score'] : -1 ?>"><?= $isFinal ? (int)$p['total_score'] . '/' . (int)$p['max_score'] : '-' ?></td>
                        <td data-order="<?= $isFinal ? e($p['avg_score']) : -1 ?>"><?= $isFinal ? plan_eval_level_badge((float)$p['avg_score']) : ($p['eval_id'] ? plan_eval_status_badge('draft') : '<span class="badge bg-secondary">ยังไม่ประเมิน</span>') ?></td>
                        <td><?= $isFinal ? plan_eval_pass_badge((bool)$p['is_passed']) : '-' ?></td>
                        <td class="small"><?= $isFinal ? e($decisions[$p['decision']] ?? '') : '-' ?></td>
                        <td class="small"><?= e($p['evaluator_name'] ?? '-') ?></td>
                        <td class="text-end text-nowrap d-print-none">
                            <?php if ($isFinal): ?>
                                <a href="<?= e(url('reports/plan_eval_print.php?plan=' . (int)$p['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="พิมพ์แบบประเมิน"><i class="bi bi-printer"></i></a>
                            <?php endif; ?>
                            <?php if ($canEvaluate): ?>
                                <a href="<?= e(url('review/plan_evaluations.php?view=' . (int)$p['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> ดู</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
if ($tot['evaluated']) {
    $chart = ['cats' => array_map(fn($c) => preg_replace('/^ด้านที่ (\d+) .*/u', 'ด้านที่ $1', $c), array_keys($rep['by_category'])),
        'catFull' => array_keys($rep['by_category']), 'avgs' => array_column($rep['by_category'], 'avg'),
        'levels' => [array_keys($rep['levels']), array_values($rep['levels'])]];
    $pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
        var d = ' . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
        var c = document.getElementById("catChart");
        if (c) new Chart(c, { type: "bar", data: { labels: d.cats, datasets: [{ label: "ค่าเฉลี่ย", data: d.avgs, backgroundColor: "#f47b20", borderRadius: 4 }] },
            options: { maintainAspectRatio: false, scales: { y: { min: 0, max: 5 } }, plugins: { legend: { display: false },
                tooltip: { callbacks: { title: function (i) { return d.catFull[i[0].dataIndex]; } } } } } });
        var l = document.getElementById("levelChart");
        if (l) new Chart(l, { type: "doughnut", data: { labels: d.levels[0], datasets: [{ data: d.levels[1], backgroundColor: ["#198754", "#0d6efd", "#ffc107", "#fd7e14", "#dc3545"] }] },
            options: { maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } } });
    });';
}
endif;
?>
<div class="d-none d-print-block small mt-4">ผู้ออกรายงาน: <?= e(full_name($user)) ?> · ระบบ <?= e(system_name()) ?> · <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
