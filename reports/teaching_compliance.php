<?php
/**
 * ไฟล์: reports/teaching_compliance.php
 * ตรวจเกณฑ์การส่งรายภาคเรียน: โครงการสอนครบทุกรายวิชา + แผนการจัดการเรียนรู้อย่างน้อย 1 วิชา
 *   ?ay= ภาคเรียน, ?dept= แผนก (admin/ผู้บริหาร), ?status=ok|notok
 * สิทธิ์: admin / ผู้บริหาร (ทุกแผนก), หัวหน้าแผนก (เฉพาะแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin', 'executive', 'supervisor');
$isSupervisor = $user['role'] === 'supervisor';
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ay = get_int('ay', (int)(current_academic_year()['id'] ?? 0));
$dept = $isSupervisor ? (int)$user['department_id'] : get_int('dept');
$fStatus = in_array(get('status'), ['ok', 'notok'], true) ? get('status') : '';
$departments = $isSupervisor ? [] : db_all('SELECT id, name FROM departments ORDER BY name');

$teachers = db_all(
    "SELECT u.id, u.prefix, u.first_name, u.last_name, COALESCE(d.name, '-') AS department_name
     FROM users u LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.status = 'active' AND u.role IN ('teacher','supervisor') AND (? = 0 OR u.department_id = ?)
     ORDER BY d.name, u.first_name",
    'ii',
    [$dept, $dept]
);
$rows = [];
$sum = ['total' => 0, 'ok' => 0, 'no_subject' => 0];
foreach ($teachers as $t) {
    $c = teaching_compliance((int)$t['id'], $ay);
    $sum['total']++;
    $sum['ok'] += $c['ok'] ? 1 : 0;
    $sum['no_subject'] += $c['subjects'] === 0 ? 1 : 0;
    if (($fStatus === 'ok' && !$c['ok']) || ($fStatus === 'notok' && $c['ok'])) {
        continue;
    }
    $rows[] = $t + $c;
}
$ayRow = null;
foreach ($years as $y) {
    if ((int)$y['id'] === $ay) {
        $ayRow = $y;
    }
}

// ส่งออก CSV
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teaching_compliance.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ครู', 'แผนก', 'รายวิชาที่สอน', 'ส่งโครงการสอน', 'ส่งแผนการจัดการเรียนรู้', 'แผนที่ผ่าน', 'ผลตามเกณฑ์', 'วิชาที่ยังไม่ส่งโครงการสอน']);
    foreach ($rows as $r) {
        fputcsv($out, [full_name($r), $r['department_name'], $r['subjects'], $r['outlines'], $r['plans'], $r['plans_approved'],
            $r['ok'] ? 'ครบตามเกณฑ์' : 'ยังไม่ครบ', implode(', ', $r['missing'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['ภาคเรียน ' . academic_label($ayRow)]);
    fclose($out);
    exit;
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['ay' => $ay, 'dept' => $isSupervisor ? null : ($dept ?: null), 'status' => $fStatus], $over), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'เกณฑ์การส่งโครงการสอน/แผนการสอน';
$pageIcon = 'bi-clipboard2-check';
$breadcrumbs = ['เกณฑ์การส่งรายภาคเรียน' => null];
$activeMenu = 'reports/teaching_compliance.php';
require APP_ROOT . '/includes/header.php';
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
    เกณฑ์แต่ละภาคเรียน: ครูส่ง <strong>โครงการสอนครบทุกรายวิชาที่สอน</strong> และ <strong>แผนการจัดการเรียนรู้อย่างน้อย 1 วิชา</strong>
    (รายวิชาที่สอนครูบันทึกเองที่เมนู “รายวิชาที่สอน”)</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $ay === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$isSupervisor): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="status">ผลตามเกณฑ์</label>
                <select name="status" id="status" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <option value="ok" <?= $fStatus === 'ok' ? 'selected' : '' ?>>ครบตามเกณฑ์</option>
                    <option value="notok" <?= $fStatus === 'notok' ? 'selected' : '' ?>>ยังไม่ครบ</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e($qs(['export' => 'csv'])) ?>" class="btn btn-outline-success" title="ส่งออก Excel"><i class="bi bi-file-earmark-excel"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-4"><div class="card text-center"><div class="card-body">
        <div class="display-6 fw-bold text-primary-th"><?= $sum['total'] ?></div><div class="text-muted small">ครูทั้งหมด</div></div></div></div>
    <div class="col-sm-4"><div class="card text-center"><div class="card-body">
        <div class="display-6 fw-bold text-success"><?= $sum['ok'] ?></div>
        <div class="text-muted small">ครบตามเกณฑ์ (<?= $sum['total'] ? round($sum['ok'] * 100 / $sum['total'], 1) : 0 ?>%)</div></div></div></div>
    <div class="col-sm-4"><div class="card text-center"><div class="card-body">
        <div class="display-6 fw-bold text-danger"><?= $sum['no_subject'] ?></div><div class="text-muted small">ยังไม่บันทึกรายวิชาที่สอน</div></div></div></div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-table me-2"></i>ภาคเรียน <?= e(academic_label($ayRow)) ?></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>ครู</th><th>แผนก</th><th class="text-center">วิชาที่สอน</th><th class="text-center">โครงการสอน</th>
                    <th class="text-center">แผนฯ (ผ่าน)</th><th>ผลตามเกณฑ์</th><th>วิชาที่ยังไม่ส่งโครงการสอน</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(full_name($r)) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td>
                        <td class="text-center"><?= $r['subjects'] ?></td>
                        <td class="text-center <?= $r['outline_ok'] ? 'text-success' : 'text-danger' ?>"><?= $r['outlines'] ?>/<?= $r['subjects'] ?></td>
                        <td class="text-center <?= $r['plan_ok'] ? 'text-success' : 'text-danger' ?>"><?= $r['plans'] ?> (<?= $r['plans_approved'] ?>)</td>
                        <td data-order="<?= $r['ok'] ? 1 : 0 ?>"><?= $r['subjects'] === 0 ? '<span class="badge bg-secondary">ยังไม่บันทึกวิชา</span>' : compliance_badge($r['ok']) ?></td>
                        <td class="small"><?= e(implode(', ', $r['missing']) ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
