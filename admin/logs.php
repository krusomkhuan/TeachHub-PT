<?php
/**
 * ไฟล์: admin/logs.php
 * บันทึกการใช้งาน (Activity Log): ใคร ทำอะไร เมื่อไร จาก IP ใด
 *   - ค้นหา/กรองตามผู้ใช้ การกระทำ ช่วงวันที่ IP
 *   - แบ่งหน้าฝั่งเซิร์ฟเวอร์ (รองรับ Log จำนวนมาก)
 *   - ส่งออก CSV และล้าง Log เก่า
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');

// ---------- ล้าง Log เก่า ----------
if (is_post()) {
    csrf_verify();
    $days = (int)post('days');
    if (!in_array($days, [90, 180, 365], true)) {
        flash('error', 'ระยะเวลาไม่ถูกต้อง');
    } else {
        $n = db_exec('DELETE FROM activity_logs WHERE created_at < (NOW() - INTERVAL ? DAY)', 'i', [$days]);
        log_activity('logs_purge', 'ล้าง Log ที่เก่ากว่า ' . $days . ' วัน จำนวน ' . $n . ' รายการ');
        flash('success', 'ลบบันทึกที่เก่ากว่า ' . $days . ' วันแล้ว ' . number_format($n) . ' รายการ');
    }
    redirect('admin/logs.php');
}

// ---------- ตัวกรอง ----------
$fUser = get_int('user_id');
$fAction = get('action');
$fFrom = get('from');
$fTo = get('to');
$fQ = mb_substr(get('q'), 0, 100);
$validDate = fn($d) => $d !== '' && DateTime::createFromFormat('Y-m-d', $d) !== false;

$where = ['1 = 1'];
$types = '';
$params = [];
if ($fUser > 0) {
    $where[] = 'l.user_id = ?';
    $types .= 'i';
    $params[] = $fUser;
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
    $where[] = '(l.detail LIKE ? OR l.ip_address LIKE ?)';
    $types .= 'ss';
    $params[] = '%' . $fQ . '%';
    $params[] = '%' . $fQ . '%';
}
$whereSql = implode(' AND ', $where);
$baseSql = "FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $whereSql";

// ---------- ส่งออก CSV (สูงสุด 50,000 รายการ) ----------
if (get('export') === 'csv') {
    $rows = db_all(
        "SELECT l.created_at, u.username, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS name, l.action, l.detail, l.ip_address, l.user_agent
         $baseSql ORDER BY l.id DESC LIMIT 50000",
        $types,
        $params
    );
    $data = array_map(fn($r) => [thai_datetime($r['created_at']), $r['username'] ?? '-', $r['name'] ?? '-',
        log_action_label($r['action']), $r['detail'], $r['ip_address'], $r['user_agent']], $rows);
    log_activity('report_export', 'ส่งออกบันทึกการใช้งาน ' . count($rows) . ' รายการ');
    csv_download('activity_log_' . date('Ymd_His') . '.csv', ['วันเวลา', 'ชื่อผู้ใช้', 'ชื่อ-สกุล', 'การกระทำ', 'รายละเอียด', 'IP', 'User Agent'], $data);
}

// ---------- แบ่งหน้า ----------
$perPage = 50;
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
$todayStats = db_one(
    "SELECT COUNT(*) AS total, COALESCE(SUM(action = 'login'), 0) AS logins, COALESCE(SUM(action = 'login_failed'), 0) AS failed,
            COUNT(DISTINCT user_id) AS users
     FROM activity_logs WHERE created_at >= CURDATE()"
);
$q = fn(array $over) => '?' . http_build_query(array_filter(array_merge(
    ['user_id' => $fUser ?: null, 'action' => $fAction, 'from' => $fFrom, 'to' => $fTo, 'q' => $fQ], $over
), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'บันทึกการใช้งาน (Log)';
$pageIcon = 'bi-clock-history';
$breadcrumbs = ['บันทึกการใช้งาน' => null];
$pageActions = '<a href="' . e($q(['export' => 'csv'])) . '" class="btn btn-success"><i class="bi bi-file-earmark-excel me-1"></i>ส่งออก CSV</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-activity"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['total']) ?></div><div class="stat-label">กิจกรรมวันนี้</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-box-arrow-in-right"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['logins']) ?></div><div class="stat-label">เข้าสู่ระบบวันนี้</div></div></div></div>
    <div class="col-6 col-xl-3"><a href="<?= e($q(['action' => 'login_failed', 'from' => date('Y-m-d')])) ?>" class="stat-card"><div class="stat-icon ic-red"><i class="bi bi-shield-exclamation"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['failed']) ?></div><div class="stat-label">ล็อกอินผิดวันนี้</div></div></a></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= number_format((int)$todayStats['users']) ?></div><div class="stat-label">ผู้ใช้ที่ใช้งานวันนี้</div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="user_id">ผู้ใช้</label>
                <select name="user_id" id="user_id" class="form-select">
                    <option value="0">ทุกคน</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['username'] . ')') ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="action">การกระทำ</label>
                <select name="action" id="action" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= e(log_action_label($a)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label small mb-1" for="from">ตั้งแต่วันที่</label><input type="date" name="from" id="from" class="form-control" value="<?= e($fFrom) ?>"></div>
            <div class="col-md-2"><label class="form-label small mb-1" for="to">ถึงวันที่</label><input type="date" name="to" id="to" class="form-control" value="<?= e($fTo) ?>"></div>
            <div class="col-md-2"><label class="form-label small mb-1" for="q">ค้นหา (รายละเอียด/IP)</label><input type="search" name="q" id="q" class="form-control" value="<?= e($fQ) ?>"></div>
            <div class="col-md-1 d-flex gap-1">
                <button class="btn btn-primary flex-fill" title="ค้นหา"><i class="bi bi-search"></i></button>
                <a href="<?= e(url('admin/logs.php')) ?>" class="btn btn-outline-secondary" title="ล้าง"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex"><i class="bi bi-list-ul me-2"></i>รายการ (<?= number_format($total) ?> รายการ · หน้า <?= $page ?>/<?= $pages ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 small">
            <thead><tr><th>วันเวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr class="<?= in_array($l['action'], ['login_failed', 'access_denied', 'csrf_failed'], true) ? 'table-danger' : '' ?>">
                    <td class="text-nowrap"><?= e(thai_datetime($l['created_at'])) ?></td>
                    <td><?= $l['username'] ? e($l['name']) . '<br><span class="text-muted">' . e($l['username']) . '</span>' : '<span class="text-muted">ผู้เยี่ยมชม</span>' ?></td>
                    <td class="text-nowrap"><?= e(log_action_label($l['action'])) ?></td>
                    <td style="max-width:420px" title="<?= e($l['user_agent']) ?>"><?= e((string)$l['detail']) ?></td>
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
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $page - 1])) ?>">ก่อนหน้า</a></li>
                    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
                        <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $i])) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $page + 1])) ?>">ถัดไป</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<div class="card border-danger">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-trash3 text-danger"></i> ล้างบันทึกการใช้งานเก่า (ลดขนาดฐานข้อมูล)
        <form method="post" class="ms-auto d-flex gap-2" data-confirm="ลบบันทึกการใช้งานเก่าถาวร? (ไม่สามารถกู้คืนได้ แนะนำให้ส่งออก CSV เก็บไว้ก่อน)" data-confirm-icon="warning">
            <?= csrf_field() ?>
            <select name="days" class="form-select form-select-sm" aria-label="ระยะเวลา">
                <option value="365">เก่ากว่า 1 ปี</option>
                <option value="180">เก่ากว่า 180 วัน</option>
                <option value="90">เก่ากว่า 90 วัน</option>
            </select>
            <button class="btn btn-sm btn-outline-danger text-nowrap">ล้าง Log</button>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
