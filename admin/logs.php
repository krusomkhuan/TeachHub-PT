<?php
/**
 * ไฟล์: admin/logs.php
 * บันทึกการใช้งาน (Activity Log): ใคร ทำอะไร กับอะไร เมื่อไร จาก IP ใด
 *   - ค้นหา/กรองตามผู้ใช้ หมวดงาน การกระทำ ช่วงวันที่ คำค้น
 *   - แบ่งหน้าฝั่งเซิร์ฟเวอร์ (รองรับ Log จำนวนมาก) และส่งออก CSV
 *   - ดาวน์โหลดไฟล์ Log รายเดือน (uploads/logs — เข้าผ่านเว็บโดยตรงไม่ได้)
 *   - ตั้งระยะเวลาเก็บ / ล้าง Log เก่า: เฉพาะผู้ดูแลระบบเต็มสิทธิ์ (เจ้าหน้าที่ที่ได้สิทธิ์ "บันทึกการใช้งาน" ดูและส่งออกได้อย่างเดียว)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$isFull = is_full_admin($user);
$retentionOptions = [90 => '90 วัน', 180 => '180 วัน', 365 => '1 ปี', 730 => '2 ปี', 1095 => '3 ปี', 0 => 'เก็บถาวร (ไม่ลบอัตโนมัติ)'];

log_purge_maybe();

// ---------- ตั้งค่า / ล้าง Log เก่า (ผู้ดูแลระบบเต็มสิทธิ์) ----------
if (is_post()) {
    csrf_verify();
    if (!$isFull) {
        log_activity('access_denied', 'เจ้าหน้าที่ดูแลระบบพยายามตั้งค่า/ล้างบันทึกการใช้งาน');
        abort_page(403, 'ไม่มีสิทธิ์', 'ตั้งระยะเวลาเก็บและล้างบันทึกการใช้งานได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์');
    }
    $action = post('action');
    $days = (int)post('days');
    if ($action === 'settings') {
        if (!array_key_exists($days, $retentionOptions)) {
            flash('error', 'ระยะเวลาไม่ถูกต้อง');
        } else {
            $old = log_retention_days();
            setting_save('log_retention_days', (string)$days);
            setting_save('log_purge_last', '0'); // ให้ลบตามค่าใหม่ทันที
            log_activity('logs_settings', 'ระยะเวลาเก็บบันทึกการใช้งาน ' . $retentionOptions[$old] . ' → ' . $retentionOptions[$days]);
            log_purge_maybe();
            flash('success', 'บันทึกระยะเวลาเก็บ Log: ' . $retentionOptions[$days]);
        }
    }
    if ($action === 'purge') {
        if (!in_array($days, [90, 180, 365, 730], true)) {
            flash('error', 'ระยะเวลาไม่ถูกต้อง');
        } else {
            $n = log_purge_old($days);
            log_activity('logs_purge', 'ล้าง Log ที่เก่ากว่า ' . $days . ' วัน จำนวน ' . $n . ' รายการ (พร้อมไฟล์ Log ของเดือนที่เก่ากว่า)');
            flash('success', 'ลบบันทึกที่เก่ากว่า ' . $days . ' วันแล้ว ' . number_format($n) . ' รายการ');
        }
    }
    redirect('admin/logs.php');
}

// ---------- ดาวน์โหลดไฟล์ Log รายเดือน ----------
if (get('file') !== '') {
    $files = log_files();
    $name = get('file');
    if (!isset($files[$name])) {
        abort_page(404, 'ไม่พบไฟล์ Log');
    }
    log_activity('logs_file_download', 'ดาวน์โหลดไฟล์ ' . $name);
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($files[$name]));
    header('Cache-Control: private, max-age=0');
    readfile($files[$name]);
    exit;
}

// ---------- ตัวกรอง ----------
$groups = log_action_groups();
$fUser = get_int('user_id');
$fGroup = get('group');
$fGroup = isset($groups[$fGroup]) || $fGroup === 'other' ? $fGroup : '';
$fAction = get('action');
$fFrom = get('from');
$fTo = get('to');
$fQ = mb_substr(get('q'), 0, 100);
$perPage = in_array(get_int('per'), [50, 100, 200], true) ? get_int('per') : 50;
$validDate = fn($d) => $d !== '' && DateTime::createFromFormat('Y-m-d', $d) !== false;

$where = ['1 = 1'];
$types = '';
$params = [];
$addIn = function (array $actions, bool $not) use (&$where, &$types, &$params) {
    $where[] = 'l.action ' . ($not ? 'NOT ' : '') . 'IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
    $types .= str_repeat('s', count($actions));
    array_push($params, ...$actions);
};
if ($fUser > 0) {
    $where[] = 'l.user_id = ?';
    $types .= 'i';
    $params[] = $fUser;
}
if ($fGroup === 'other') {
    $addIn(array_keys(array_merge(...array_map(fn($g) => $g[1], array_values($groups)))), true);
} elseif ($fGroup !== '') {
    $addIn(array_keys($groups[$fGroup][1]), false);
}
if ($fAction !== '') {
    $where[] = 'l.action = ?';
    $types .= 's';
    $params[] = $fAction;
}
if ($validDate($fFrom)) {
    $where[] = 'l.created_at >= ?';
    $types .= 's';
    $params[] = $fFrom . ' 00:00:00';
}
if ($validDate($fTo)) {
    $where[] = 'l.created_at <= ?';
    $types .= 's';
    $params[] = $fTo . ' 23:59:59';
}
if ($fQ !== '') {
    $where[] = "(l.detail LIKE ? OR l.ip_address LIKE ? OR u.username LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $types .= 'ssss';
    array_push($params, ...array_fill(0, 4, '%' . $fQ . '%'));
}
$whereSql = implode(' AND ', $where);
$baseSql = "FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $whereSql";

// ---------- ส่งออก CSV (สูงสุด 50,000 รายการ) ----------
if (get('export') === 'csv') {
    $rows = db_all(
        "SELECT l.id, l.created_at, u.username, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS name, l.action, l.detail, l.ip_address, l.user_agent
         $baseSql ORDER BY l.id DESC LIMIT 50000",
        $types,
        $params
    );
    $groupName = fn($a) => ($g = log_action_group($a)) === 'other' ? 'อื่น ๆ' : $groups[$g][0];
    $data = array_map(fn($r) => [$r['id'], thai_datetime($r['created_at']), $r['username'] ?? '-', $r['name'] ?? '-',
        $groupName($r['action']), log_action_label($r['action']), $r['action'], $r['detail'], $r['ip_address'], $r['user_agent']], $rows);
    log_activity('report_export', 'ส่งออกบันทึกการใช้งาน ' . count($rows) . ' รายการ'
        . ($fGroup !== '' ? ' หมวด ' . ($groups[$fGroup][0] ?? 'อื่น ๆ') : '') . ($fAction !== '' ? ' การกระทำ ' . $fAction : '')
        . ($fUser ? ' ผู้ใช้ #' . $fUser : '') . ($fFrom !== '' || $fTo !== '' ? ' ช่วง ' . $fFrom . ' ถึง ' . $fTo : '') . ($fQ !== '' ? ' คำค้น "' . $fQ . '"' : ''));
    csv_download('activity_log_' . date('Ymd_His') . '.csv',
        ['ลำดับ', 'วันเวลา', 'ชื่อผู้ใช้', 'ชื่อ-สกุล', 'หมวด', 'การกระทำ', 'รหัสการกระทำ', 'รายละเอียด', 'IP', 'User Agent'], $data);
}

// ---------- แบ่งหน้า ----------
$total = (int)db_value("SELECT COUNT(*) $baseSql", $types, $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min(max(1, get_int('page', 1)), $pages);
$logs = db_all(
    "SELECT l.*, u.username, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS name, u.role $baseSql
     ORDER BY l.id DESC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);

$users = db_all('SELECT id, username, first_name, last_name FROM users ORDER BY first_name');
$actions = array_column(db_all('SELECT DISTINCT action FROM activity_logs ORDER BY action'), 'action');
$actionsByGroup = [];
foreach ($actions as $a) {
    $actionsByGroup[log_action_group($a)][] = $a;
}
$todayStats = db_one(
    "SELECT COUNT(*) AS total, COALESCE(SUM(action = 'login'), 0) AS logins, COALESCE(SUM(action = 'login_failed'), 0) AS failed,
            COUNT(DISTINCT user_id) AS users
     FROM activity_logs WHERE created_at >= CURDATE()"
);
$retention = log_retention_days();
$oldest = db_value('SELECT MIN(created_at) FROM activity_logs');
$allCount = (int)db_value('SELECT COUNT(*) FROM activity_logs');
$logFiles = log_files();
$q = fn(array $over) => '?' . http_build_query(array_filter(array_merge(
    ['user_id' => $fUser ?: null, 'group' => $fGroup, 'action' => $fAction, 'from' => $fFrom, 'to' => $fTo, 'q' => $fQ,
        'per' => $perPage !== 50 ? $perPage : null], $over
), fn($v) => $v !== null && $v !== ''));
$groupBadge = function (string $action) use ($groups): string {
    $g = log_action_group($action);
    return '<span class="badge bg-light text-secondary border fw-normal">' . e($g === 'other' ? 'อื่น ๆ' : $groups[$g][0]) . '</span>';
};

$pageTitle = 'บันทึกการใช้งาน (Log)';
$pageIcon = 'bi-clock-history';
$breadcrumbs = ['บันทึกการใช้งาน' => null];
$pageActions = '<a href="' . e($q(['export' => 'csv'])) . '" class="btn btn-success"><i class="bi bi-file-earmark-excel me-1"></i>ส่งออก CSV</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-activity"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['total']) ?></div><div class="stat-label">กิจกรรมวันนี้</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-box-arrow-in-right"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['logins']) ?></div><div class="stat-label">เข้าสู่ระบบวันนี้</div></div></div></div>
    <div class="col-6 col-xl-3"><a href="<?= e($q(['action' => 'login_failed', 'group' => null, 'from' => date('Y-m-d')])) ?>" class="stat-card"><div class="stat-icon ic-red"><i class="bi bi-shield-exclamation"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['failed']) ?></div><div class="stat-label">ล็อกอินผิดวันนี้</div></div></a></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['users']) ?></div><div class="stat-label">ผู้ใช้ที่ใช้งานวันนี้</div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6 col-xl-3">
                <label class="form-label small mb-1" for="user_id">ผู้ใช้</label>
                <select name="user_id" id="user_id" class="form-select">
                    <option value="0">ทุกคน</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['username'] . ')') ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-xl-2">
                <label class="form-label small mb-1" for="group">หมวดงาน</label>
                <select name="group" id="group" class="form-select">
                    <option value="">ทุกหมวด</option>
                    <?php foreach ($groups as $k => $g): ?><option value="<?= e($k) ?>" <?= $fGroup === $k ? 'selected' : '' ?>><?= e($g[0]) ?></option><?php endforeach; ?>
                    <option value="other" <?= $fGroup === 'other' ? 'selected' : '' ?>>อื่น ๆ</option>
                </select>
            </div>
            <div class="col-md-6 col-xl-2">
                <label class="form-label small mb-1" for="action">การกระทำ</label>
                <select name="action" id="action" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($groups + ['other' => ['อื่น ๆ', []]] as $k => $g): if (empty($actionsByGroup[$k])) continue; ?>
                        <optgroup label="<?= e($g[0]) ?>">
                            <?php foreach ($actionsByGroup[$k] as $a): ?><option value="<?= e($a) ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= e(log_action_label($a)) ?></option><?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="from">ตั้งแต่วันที่</label><input type="date" name="from" id="from" class="form-control" value="<?= e($fFrom) ?>"></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="to">ถึงวันที่</label><input type="date" name="to" id="to" class="form-control" value="<?= e($fTo) ?>"></div>
            <div class="col-md-6 col-xl-4"><label class="form-label small mb-1" for="q">คำค้น (รายละเอียด / ชื่อผู้ใช้ / IP)</label><input type="search" name="q" id="q" class="form-control" value="<?= e($fQ) ?>" placeholder="เช่น ชื่องาน, #12, 192.168"></div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-1" for="per">แสดงต่อหน้า</label>
                <select name="per" id="per" class="form-select">
                    <?php foreach ([50, 100, 200] as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> รายการ</option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2 d-flex gap-1">
                <button class="btn btn-primary flex-fill"><i class="bi bi-search me-1"></i>ค้นหา</button>
                <a href="<?= e(url('admin/logs.php')) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap gap-2"><span><i class="bi bi-list-ul me-2"></i>รายการ (<?= number_format($total) ?> รายการ · หน้า <?= $page ?>/<?= $pages ?>)</span>
        <?php if ($total > 50000): ?><span class="ms-auto small text-warning fw-normal">ส่งออก CSV ได้ครั้งละ 50,000 รายการล่าสุด — กรองช่วงวันที่เพื่อส่งออกส่วนที่เหลือ</span><?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 small">
            <thead><tr><th>วันเวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr class="<?= in_array($l['action'], ['login_failed', 'access_denied', 'csrf_failed'], true) ? 'table-danger' : '' ?>">
                    <td class="text-nowrap"><?= e(thai_datetime($l['created_at'])) ?></td>
                    <td><?= $l['username'] ? '<a href="' . e($q(['user_id' => (int)$l['user_id'], 'page' => null])) . '" class="text-reset">' . e($l['name']) . '</a><br><span class="text-muted">' . e($l['username']) . '</span>' : '<span class="text-muted">' . ($l['user_id'] ? 'ผู้ใช้ #' . (int)$l['user_id'] . ' (ถูกลบ)' : 'ผู้เยี่ยมชม') . '</span>' ?></td>
                    <td><a href="<?= e($q(['action' => $l['action'], 'page' => null])) ?>" class="text-reset text-nowrap"><?= e(log_action_label($l['action'])) ?></a><br><?= $groupBadge($l['action']) ?></td>
                    <td style="max-width:460px" class="text-break" title="<?= e($l['user_agent']) ?>"><?= e((string)$l['detail']) ?></td>
                    <td class="text-nowrap"><?= e($l['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="5" class="empty-state">ไม่พบรายการ</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <div class="card-footer bg-white">
            <nav aria-label="แบ่งหน้า">
                <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($q(['page' => 1])) ?>" aria-label="หน้าแรก">«</a></li>
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $page - 1])) ?>">ก่อนหน้า</a></li>
                    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
                        <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $i])) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $page + 1])) ?>">ถัดไป</a></li>
                    <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $pages])) ?>" aria-label="หน้าสุดท้าย">»</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-hourglass-split me-2"></i>ระยะเวลาเก็บบันทึก</div>
            <div class="card-body">
                <p class="small mb-2">
                    ปัจจุบัน: <strong><?= e($retentionOptions[$retention] ?? $retention . ' วัน') ?></strong>
                    <?= $retention > 0 ? '— ระบบลบ Log ที่เก่ากว่านี้ให้อัตโนมัติวันละครั้ง (ทั้งในฐานข้อมูลและไฟล์ Log)' : '— ไม่ลบอัตโนมัติ' ?><br>
                    มีบันทึกทั้งหมด <?= number_format($allCount) ?> รายการ<?= $oldest ? ' · เก่าสุด ' . e(thai_datetime($oldest)) : '' ?>
                </p>
                <?php if ($isFull): ?>
                    <form method="post" class="d-flex flex-wrap gap-2 mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="settings">
                        <select name="days" class="form-select form-select-sm w-auto" aria-label="ระยะเวลาเก็บ">
                            <?php foreach ($retentionOptions as $d => $label): ?><option value="<?= $d ?>" <?= $retention === $d ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-primary">บันทึก</button>
                    </form>
                    <form method="post" class="d-flex flex-wrap align-items-center gap-2 border-top pt-3" data-confirm="ลบบันทึกการใช้งานเก่าถาวร? (ไม่สามารถกู้คืนได้ แนะนำให้ส่งออก CSV เก็บไว้ก่อน)" data-confirm-icon="warning">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="purge">
                        <span class="small"><i class="bi bi-trash3 text-danger me-1"></i>ล้างทันที</span>
                        <select name="days" class="form-select form-select-sm w-auto" aria-label="ระยะเวลา">
                            <option value="730">เก่ากว่า 2 ปี</option>
                            <option value="365">เก่ากว่า 1 ปี</option>
                            <option value="180">เก่ากว่า 180 วัน</option>
                            <option value="90">เก่ากว่า 90 วัน</option>
                        </select>
                        <button class="btn btn-sm btn-outline-danger text-nowrap">ล้าง Log</button>
                    </form>
                <?php else: ?>
                    <p class="small text-muted mb-0"><i class="bi bi-lock me-1"></i>ตั้งระยะเวลาเก็บและล้าง Log ได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>ไฟล์ Log รายเดือน</div>
            <div class="card-body">
                <p class="small text-muted">ระบบเขียนบันทึกทุกรายการลงไฟล์ <code>uploads/logs/activity-ปี-เดือน.log</code> ด้วย (เปิดผ่านเว็บโดยตรงไม่ได้ และไม่รวมในไฟล์สำรองข้อมูล) ใช้เป็นหลักฐานสำรองกรณีฐานข้อมูลถูกแก้ไขหรือกู้คืน</p>
                <?php if ($logFiles): ?>
                    <ul class="list-group list-group-flush small">
                        <?php foreach (array_slice($logFiles, 0, 24, true) as $name => $path): ?>
                            <li class="list-group-item d-flex align-items-center px-0">
                                <i class="bi bi-file-earmark-text me-2 text-secondary"></i><?= e($name) ?>
                                <span class="text-muted ms-2"><?= e(format_bytes((int)filesize($path))) ?></span>
                                <a href="<?= e(url('admin/logs.php?file=' . rawurlencode($name))) ?>" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-download"></i></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small mb-0">ยังไม่มีไฟล์ Log</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
