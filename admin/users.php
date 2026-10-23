<?php
/**
 * ไฟล์: admin/users.php
 * จัดการผู้ใช้: อนุมัติ, ระงับ/เปิดใช้งาน, รีเซ็ตรหัสผ่าน, ลบ (เฉพาะบัญชีที่ยังไม่อนุมัติ), จัดการคำขอรีเซ็ตรหัสผ่าน
 * แก้ไขข้อมูล/กำหนด role ที่ admin/user_edit.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$myId = (int)$user['id'];
// เจ้าหน้าที่ดูแลระบบ (สิทธิ์จัดการผู้ใช้) จัดการบัญชีผู้ดูแลระบบ/เจ้าหน้าที่ดูแลระบบด้วยกันไม่ได้
$limitedEditor = is_limited_admin($user);

// =====================================================================
// จัดการคำสั่ง (POST)
// =====================================================================
if (is_post()) {
    csrf_verify();

    $action = post('action');
    $targetId = (int)post('user_id');
    $return = in_array(post('return'), ['admin/index.php', 'admin/users.php'], true) ? post('return') : 'admin/users.php';

    // คำขอรีเซ็ตรหัสผ่าน: หา user_id จาก request
    $requestId = (int)post('request_id');
    if ($requestId > 0) {
        $req = db_one("SELECT * FROM password_reset_requests WHERE id = ? AND status = 'pending'", 'i', [$requestId]);
        if (!$req) {
            flash('error', 'ไม่พบคำขอ หรือคำขอนี้ถูกดำเนินการแล้ว');
            redirect($return);
        }
        $targetId = (int)$req['user_id'];
    }

    $target = db_one('SELECT * FROM users WHERE id = ?', 'i', [$targetId]);
    if (!$target) {
        flash('error', 'ไม่พบผู้ใช้');
        redirect($return);
    }
    $name = full_name($target) . ' (' . $target['username'] . ')';
    if ($limitedEditor && $target['role'] === 'admin') {
        log_activity('access_denied', 'เจ้าหน้าที่ดูแลระบบพยายามจัดการบัญชีผู้ดูแลระบบ ' . $name);
        flash('error', 'เจ้าหน้าที่ดูแลระบบจัดการบัญชีผู้ดูแลระบบไม่ได้');
        redirect($return);
    }

    switch ($action) {
        // ---------- อนุมัติสมาชิก ----------
        case 'approve':
            db_exec("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'", 'i', [$targetId]);
            // ผู้สมัครตำแหน่งรองผู้อำนวยการ → ให้สิทธิ์ผู้บริหารอัตโนมัติ (admin ปรับภายหลังได้)
            $extra = '';
            if ($target['role'] === 'teacher' && is_executive_position((string)$target['position'])) {
                db_exec("UPDATE users SET role = 'executive' WHERE id = ?", 'i', [$targetId]);
                $extra = ' (ตั้งสิทธิ์เป็นผู้บริหารตามตำแหน่ง)';
            }
            log_activity('user_approve', 'อนุมัติ ' . $name . $extra);
            flash('success', 'อนุมัติ ' . $name . ' เรียบร้อยแล้ว' . $extra);
            break;

        // ---------- ระงับการใช้งาน ----------
        case 'suspend':
            if ($targetId === $myId) {
                flash('error', 'ไม่สามารถระงับบัญชีของตนเองได้');
            } elseif (is_full_admin($target) && full_admin_count($targetId) < 1) {
                flash('error', 'ต้องมีผู้ดูแลระบบเต็มสิทธิ์ที่ใช้งานได้อย่างน้อย 1 คน');
            } else {
                db_exec("UPDATE users SET status = 'suspended' WHERE id = ?", 'i', [$targetId]);
                log_activity('user_suspend', 'ระงับ ' . $name);
                flash('success', 'ระงับการใช้งาน ' . $name . ' แล้ว');
            }
            break;

        // ---------- เปิดใช้งานอีกครั้ง ----------
        case 'activate':
            db_exec("UPDATE users SET status = 'active' WHERE id = ?", 'i', [$targetId]);
            log_activity('user_activate', 'เปิดใช้งาน ' . $name);
            flash('success', 'เปิดใช้งาน ' . $name . ' แล้ว');
            break;

        // ---------- รีเซ็ตรหัสผ่าน (จากปุ่มในตาราง หรือจากคำขอ) ----------
        case 'reset_password':
            $temp = random_password(10);
            db_exec(
                'UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?',
                'si',
                [password_hash($temp, PASSWORD_DEFAULT), $targetId]
            );
            // ปิดคำขอรีเซ็ตที่ค้างอยู่ทั้งหมดของผู้ใช้นี้
            db_exec(
                "UPDATE password_reset_requests SET status = 'done', handled_by = ?, handled_at = NOW()
                 WHERE user_id = ? AND status = 'pending'",
                'ii',
                [$myId, $targetId]
            );
            log_activity('user_reset_password', 'รีเซ็ตรหัสผ่าน ' . $name);
            flash('important', 'รีเซ็ตรหัสผ่านของ ' . $name . ' แล้ว' . "\n" . 'รหัสผ่านชั่วคราว: ' . $temp
                . "\n" . 'กรุณาแจ้งผู้ใช้ ระบบจะบังคับให้ตั้งรหัสผ่านใหม่เมื่อเข้าสู่ระบบ (รหัสนี้จะแสดงเพียงครั้งเดียว)');
            break;

        // ---------- ปฏิเสธคำขอรีเซ็ต ----------
        case 'reject_request':
            db_exec(
                "UPDATE password_reset_requests SET status = 'rejected', handled_by = ?, handled_at = NOW() WHERE id = ?",
                'ii',
                [$myId, $requestId]
            );
            log_activity('reset_request_reject', 'ปฏิเสธคำขอรีเซ็ตรหัสผ่านของ ' . $name);
            flash('success', 'ปฏิเสธคำขอแล้ว');
            break;

        // ---------- ลบ (เฉพาะบัญชีรออนุมัติ เพื่อไม่ให้งานที่ส่งแล้วหาย) ----------
        case 'delete':
            if ($target['status'] !== 'pending') {
                flash('error', 'ลบได้เฉพาะบัญชีที่ยังไม่อนุมัติ บัญชีที่ใช้งานแล้วให้ใช้ "ระงับ" แทน');
            } else {
                db_exec("DELETE FROM users WHERE id = ? AND status = 'pending'", 'i', [$targetId]);
                delete_upload('avatars', $target['avatar']);
                log_activity('user_delete', 'ลบผู้สมัคร ' . $name);
                flash('success', 'ลบผู้สมัคร ' . $name . ' แล้ว');
            }
            break;

        default:
            flash('error', 'คำสั่งไม่ถูกต้อง');
    }
    redirect($return);
}

// =====================================================================
// แสดงรายการ
// =====================================================================
$fStatus = get('status');
$fRole = get('role');
$fDept = get_int('dept');

$where = ['1 = 1'];
$types = '';
$params = [];
if (isset(user_status_labels()[$fStatus])) {
    $where[] = 'u.status = ?';
    $types .= 's';
    $params[] = $fStatus;
}
if (isset(role_labels()[$fRole])) {
    $where[] = 'u.role = ?';
    $types .= 's';
    $params[] = $fRole;
}
if ($fDept > 0) {
    $where[] = 'u.department_id = ?';
    $types .= 'i';
    $params[] = $fDept;
}

$users = db_all(
    'SELECT u.*, d.name AS department_name FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY FIELD(u.status, \'pending\', \'active\', \'suspended\'), u.first_name',
    $types,
    $params
);
$departments = db_all('SELECT id, name FROM departments ORDER BY name');
$requests = db_all(
    "SELECT r.*, u.username, u.prefix, u.first_name, u.last_name, u.email, u.phone, u.role
     FROM password_reset_requests r JOIN users u ON u.id = r.user_id
     WHERE r.status = 'pending' ORDER BY r.created_at"
);
$counts = [];
foreach (db_all('SELECT status, COUNT(*) AS c FROM users GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['c'];
}

$pageTitle = 'จัดการผู้ใช้';
$pageIcon = 'bi-people';
$breadcrumbs = ['จัดการผู้ใช้' => null];
$pageActions = '<a href="' . e(url('admin/user_edit.php')) . '" class="btn btn-accent"><i class="bi bi-person-plus me-1"></i>เพิ่มผู้ใช้</a>';
require APP_ROOT . '/includes/header.php';

/** ฟอร์มปุ่มคำสั่งแบบ POST (มี CSRF) */
function user_action_form(string $action, int $userId, string $label, string $btnClass, string $confirm, string $icon, int $requestId = 0): string
{
    return '<form method="post" class="d-inline" data-confirm="' . e($confirm) . '"'
        . (in_array($action, ['delete', 'suspend', 'reject_request'], true) ? ' data-confirm-icon="warning"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="user_id" value="' . $userId . '">'
        . ($requestId ? '<input type="hidden" name="request_id" value="' . $requestId . '">' : '')
        . '<button type="submit" class="' . e($btnClass) . '" title="' . e($label) . '" data-bs-toggle="tooltip">'
        . '<i class="bi ' . e($icon) . '"></i></button></form>';
}
?>

<?php if ($requests): ?>
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-header"><i class="bi bi-key me-2 text-warning"></i>คำขอรีเซ็ตรหัสผ่าน (<?= count($requests) ?>)</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>ผู้ใช้</th><th>ช่องทางติดต่อ/หมายเหตุ</th><th>ขอเมื่อ</th><th>IP</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($requests as $rq): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e(full_name($rq)) ?></div><small class="text-muted"><?= e($rq['username']) ?> · <?= e($rq['email']) ?> · <?= e($rq['phone']) ?></small></td>
                        <td class="small"><?= e($rq['request_note'] ?: '-') ?></td>
                        <td class="small"><?= e(thai_datetime($rq['created_at'])) ?></td>
                        <td class="small"><?= e($rq['requested_ip']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($limitedEditor && $rq['role'] === 'admin'): ?>
                                <span class="small text-muted"><i class="bi bi-lock"></i> บัญชีผู้ดูแลระบบ</span>
                            <?php else: ?>
                            <?= user_action_form('reset_password', (int)$rq['user_id'], 'รีเซ็ตรหัสผ่าน', 'btn btn-sm btn-warning',
                                'รีเซ็ตรหัสผ่านของ ' . full_name($rq) . ' และสร้างรหัสผ่านชั่วคราว?', 'bi-arrow-repeat', (int)$rq['id']) ?>
                            <?= user_action_form('reject_request', (int)$rq['user_id'], 'ปฏิเสธคำขอ', 'btn btn-sm btn-outline-secondary',
                                'ปฏิเสธคำขอรีเซ็ตรหัสผ่านนี้?', 'bi-x-lg', (int)$rq['id']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ตัวกรอง -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small mb-1" for="fStatus">สถานะ</label>
                <select name="status" id="fStatus" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach (user_status_labels() as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($v) ?> (<?= $counts[$k] ?? 0 ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small mb-1" for="fRole">สิทธิ์</label>
                <select name="role" id="fRole" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach (role_labels() as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $fRole === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label class="form-label small mb-1" for="fDept">แผนกวิชา</label>
                <select name="dept" id="fDept" class="form-select">
                    <option value="0">ทุกแผนก</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e(url('admin/users.php')) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- ตารางผู้ใช้ -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead>
                <tr>
                    <th>ชื่อ-สกุล</th><th>ชื่อผู้ใช้ / โทร</th><th>แผนก</th><th>สิทธิ์</th><th>สถานะ</th><th>เข้าระบบล่าสุด</th><th class="no-sort text-end">จัดการ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): $uidRow = (int)$u['id']; ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?= e(avatar_url($u)) ?>" class="avatar-sm" alt="" loading="lazy">
                                <div>
                                    <div class="fw-semibold"><?= e(full_name($u)) ?></div>
                                    <small class="text-muted"><?= e($u['email']) ?></small>
                                    <?php if (!empty($u['google_sub'])): ?><span class="badge bg-light text-dark border ms-1" title="เชื่อมบัญชี Google แล้ว"><i class="bi bi-google"></i></span><?php endif; ?>
                                    <?php if ($u['position'] !== null && $u['position'] !== ''): ?>
                                        <div class="small text-primary-th"><?= e($u['position']) ?>
                                            <?php foreach (designated_review_types($u) as $rt): ?><span class="badge bg-info text-dark ms-1">ผู้ตรวจ<?= e(['lesson_plan' => 'โครงการสอน/แผน', 'pa' => ' PA', 'research' => 'วิจัย'][$rt]) ?></span><?php endforeach; ?>
                                            <?php if (is_advisor_staff($u)): ?><span class="badge bg-info text-dark ms-1">ผู้ตรวจรายงานครูที่ปรึกษา</span><?php endif; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= e($u['username']) ?><br><small class="text-muted"><?= e($u['phone'] ?: '-') ?></small></td>
                        <td class="small"><?= e($u['department_name'] ?? '-') ?></td>
                        <td class="small"><?= e(user_role_label($u)) ?></td>
                        <td data-order="<?= e($u['status']) ?>"><?= user_status_badge($u['status']) ?>
                            <?php if ($u['must_change_password']): ?><br><small class="text-warning">รอเปลี่ยนรหัส</small><?php endif; ?></td>
                        <td class="small" data-order="<?= e((string)$u['last_login_at']) ?>"><?= e(thai_datetime($u['last_login_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($limitedEditor && $u['role'] === 'admin'): ?>
                                <span class="small text-muted" title="เจ้าหน้าที่ดูแลระบบจัดการบัญชีผู้ดูแลระบบไม่ได้" data-bs-toggle="tooltip"><i class="bi bi-lock"></i></span>
                            <?php else: ?>
                            <?php if ($u['status'] === 'pending'): ?>
                                <?= user_action_form('approve', $uidRow, 'อนุมัติ', 'btn btn-sm btn-success', 'อนุมัติ ' . full_name($u) . ' ให้เข้าใช้งาน?', 'bi-check-lg') ?>
                            <?php endif; ?>
                            <a href="<?= e(url('admin/user_edit.php?id=' . $uidRow)) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข/กำหนดสิทธิ์" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></a>
                            <?= user_action_form('reset_password', $uidRow, 'รีเซ็ตรหัสผ่าน', 'btn btn-sm btn-outline-warning', 'รีเซ็ตรหัสผ่านของ ' . full_name($u) . ' และสร้างรหัสผ่านชั่วคราว?', 'bi-key') ?>
                            <?php if ($uidRow !== $myId): ?>
                                <?php if ($u['status'] === 'active'): ?>
                                    <?= user_action_form('suspend', $uidRow, 'ระงับ', 'btn btn-sm btn-outline-danger', 'ระงับการใช้งานของ ' . full_name($u) . '?', 'bi-slash-circle') ?>
                                <?php elseif ($u['status'] === 'suspended'): ?>
                                    <?= user_action_form('activate', $uidRow, 'เปิดใช้งาน', 'btn btn-sm btn-outline-success', 'เปิดใช้งาน ' . full_name($u) . ' อีกครั้ง?', 'bi-arrow-counterclockwise') ?>
                                <?php else: ?>
                                    <?= user_action_form('delete', $uidRow, 'ลบผู้สมัคร', 'btn btn-sm btn-outline-danger', 'ลบผู้สมัคร ' . full_name($u) . ' ออกจากระบบ? (ไม่สามารถกู้คืนได้)', 'bi-trash') ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
