<?php
/**
 * ไฟล์: hr/leave_quotas.php
 * สิทธิ์วันลาพักผ่อนรายปีงบประมาณ (ผู้ดูแลงานบุคลากร) — สิทธิ์ปีนี้ + วันลาพักผ่อนสะสม   ?fy=
 * ค่าเริ่มต้นต่อปี (setting hr_vacation_days) ใช้กับบุคลากรที่ยังไม่กำหนดรายบุคคล
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_hr($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลงานบุคลากร');
}
$self = 'hr/leave_quotas.php';
$activeMenu = $self;
$fy = is_post() ? (int)post('fy') : hr_fiscal_param();
if ($fy < 2550 || $fy > 2700) {
    $fy = current_fiscal_year();
}
$default = (float)setting('hr_vacation_days', '10');

if (is_post()) {
    csrf_verify();
    if (post('action') === 'default') {
        $v = round((float)post('default_days') * 2) / 2;
        if ($v < 0 || $v > 30) {
            flash('error', 'สิทธิ์ลาพักผ่อนต่อปี 0 – 30 วัน');
        } else {
            setting_save('hr_vacation_days', hr_days($v));
            log_activity('hr_quota_save', 'ตั้งค่าสิทธิ์ลาพักผ่อนเริ่มต้น ' . hr_days($v) . ' วัน');
            flash('success', 'บันทึกค่าเริ่มต้นแล้ว');
        }
        redirect($self . '?fy=' . $fy);
    }
    $valid = array_flip(array_map('intval', array_column(hr_people(), 'id')));
    $n = 0;
    foreach ((array)($_POST['quota'] ?? []) as $pid => $v) {
        $pid = (int)$pid;
        $v = trim((string)$v);
        if (!isset($valid[$pid])) {
            continue;
        }
        if ($v === '') {
            $n += db_exec('DELETE FROM hr_leave_quotas WHERE user_id = ? AND fiscal_year = ?', 'ii', [$pid, $fy]);
            continue;
        }
        $d = round((float)$v * 2) / 2;
        if ($d < 0 || $d > 60) {
            continue;
        }
        db_exec('INSERT INTO hr_leave_quotas (user_id, fiscal_year, vacation_days) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vacation_days = VALUES(vacation_days)',
            'iid', [$pid, $fy, $d]);
        $n++;
    }
    log_activity('hr_quota_save', 'กำหนดสิทธิ์ลาพักผ่อนปีงบประมาณ ' . $fy);
    flash('success', 'บันทึกสิทธิ์ลาพักผ่อนปีงบประมาณ ' . $fy . ' แล้ว');
    redirect($self . '?fy=' . $fy);
}

$people = hr_people();
$quotas = [];
foreach (db_all('SELECT user_id, vacation_days FROM hr_leave_quotas WHERE fiscal_year = ?', 'i', [$fy]) as $r) {
    $quotas[(int)$r['user_id']] = (float)$r['vacation_days'];
}
$used = [];
foreach (db_all("SELECT user_id, SUM(days) AS d FROM hr_leaves WHERE fiscal_year = ? AND leave_type = 'vacation' AND status = 'approved' GROUP BY user_id", 'i', [$fy]) as $r) {
    $used[(int)$r['user_id']] = (float)$r['d'];
}

$pageTitle = 'สิทธิ์วันลาพักผ่อน';
$pageIcon = 'bi-sliders';
$breadcrumbs = ['สิทธิ์วันลาพักผ่อน' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-8"><label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                        <select name="fy" id="fy" class="form-select" onchange="this.form.submit()"><?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
                </form>
                <div class="small text-muted mt-2">ระบุ “สิทธิ์ปีนี้รวมวันสะสม” เช่น มีสิทธิ์ 10 วัน + สะสม 5 วัน = 15 · เว้นว่าง = ใช้ค่าเริ่มต้น <?= hr_days($default) ?> วัน</div>
            </div></div>
    </div>
    <div class="col-md-6">
        <form method="post" class="card h-100"><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="fy" value="<?= $fy ?>">
                <label class="form-label" for="default_days">สิทธิ์ลาพักผ่อนเริ่มต้นต่อปี (วันทำการ)</label>
                <div class="input-group"><input type="number" class="form-control" name="default_days" id="default_days" step="0.5" min="0" max="30" value="<?= hr_days($default) ?>">
                    <button class="btn btn-primary">บันทึก</button></div>
            </div></form>
    </div>
</div>
<form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="fy" value="<?= $fy ?>">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th>ชื่อ-สกุล</th><th>แผนก/งาน</th><th class="no-sort" style="width:170px">สิทธิ์ปีนี้รวมวันสะสม</th><th class="text-end">ใช้ไปแล้ว</th><th class="text-end">คงเหลือ</th></tr></thead>
                <tbody>
                <?php foreach ($people as $p): $pid = (int)$p['id']; $q = $quotas[$pid] ?? null; $u = $used[$pid] ?? 0; ?>
                    <tr>
                        <td><?= e($p['full_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$p['department_name']) ?: '-') ?></td>
                        <td><input type="number" class="form-control form-control-sm" name="quota[<?= $pid ?>]" step="0.5" min="0" max="60"
                                   value="<?= $q !== null ? hr_days($q) : '' ?>" placeholder="<?= hr_days($default) ?>" aria-label="สิทธิ์ลาพักผ่อนของ <?= e($p['full_name']) ?>"></td>
                        <td class="text-end"><?= hr_days($u) ?></td>
                        <td class="text-end"><?= hr_days(max(0, ($q ?? $default) - $u)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-accent" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกสิทธิ์ลาพักผ่อน</button></div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
