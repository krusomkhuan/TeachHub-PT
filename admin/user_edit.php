<?php
/**
 * ไฟล์: admin/user_edit.php
 * เพิ่ม/แก้ไขผู้ใช้ และกำหนดสิทธิ์ (role) / สถานะ — เฉพาะ admin
 * กำหนดสิทธิ์เจ้าหน้าที่ดูแลระบบ (users.admin_permissions) ได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์
 *   ?id=xx  = แก้ไข,  ไม่มี id = เพิ่มผู้ใช้ใหม่
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$myId = (int)$user['id'];

$id = get_int('id');
$editing = null;
if ($id > 0) {
    $editing = db_one('SELECT * FROM users WHERE id = ?', 'i', [$id]);
    if (!$editing) {
        abort_page(404, 'ไม่พบผู้ใช้');
    }
}
$isNew = $editing === null;
$isSelf = !$isNew && (int)$editing['id'] === $myId;
// เจ้าหน้าที่ดูแลระบบ (สิทธิ์จัดการผู้ใช้): แก้ไขบัญชีผู้ดูแลระบบ/เจ้าหน้าที่ดูแลระบบไม่ได้ รวมถึงบัญชีของตนเอง (แก้ข้อมูลตนเองที่หน้าโปรไฟล์)
$isFullEditor = is_full_admin($user);
if (!$isFullEditor && !$isNew && $editing['role'] === 'admin') {
    log_activity('access_denied', 'เจ้าหน้าที่ดูแลระบบพยายามแก้ไขบัญชีผู้ดูแลระบบ ' . $editing['username']);
    abort_page(403, 'ไม่มีสิทธิ์แก้ไขบัญชีนี้', 'เจ้าหน้าที่ดูแลระบบแก้ไขบัญชีผู้ดูแลระบบไม่ได้ — แก้ข้อมูลของตนเองได้ที่หน้าโปรไฟล์');
}
$permReady = admin_perm_column_ready();
$permGroups = admin_permission_groups();
$permKeys = array_keys(admin_permission_list());
$departments = db_all('SELECT id, name FROM departments WHERE is_active = 1 OR id = ? ORDER BY name', 'i', [(int)($editing['department_id'] ?? 0)]);
$errors = [];

if (is_post()) {
    csrf_verify();

    $data = [
        'username'          => post('username'),
        'prefix'            => post('prefix'),
        'first_name'        => post('first_name'),
        'last_name'         => post('last_name'),
        'department_id'     => (int)post('department_id'),
        'position'          => mb_substr(post('position'), 0, 100),
        'academic_standing' => post('academic_standing'),
        'email'             => mb_strtolower(post('email')),
        'phone'             => preg_replace('/[\s-]/', '', post('phone')),
        'role'              => post('role'),
        'status'            => post('status'),
    ];
    // สิทธิ์เจ้าหน้าที่งานตามตำแหน่ง (ค่า "pos:ลำดับ" จากช่องสิทธิ์) = role ครูผู้สอน + ตำแหน่งนั้น
    $staffRoles = advisor_staff_positions();
    if (preg_match('/^pos:(\d+)$/', $data['role'], $m) && isset($staffRoles[(int)$m[1]])) {
        $data['role'] = 'teacher';
        $data['position'] = $staffRoles[(int)$m[1]];
    } elseif (in_array(normalize_position($data['position']), $staffRoles, true)) {
        // เลือกสิทธิ์หลักแทนสิทธิ์เจ้าหน้าที่งาน = ยกเลิกตำแหน่งงานครูที่ปรึกษาฯ (สิทธิ์ตรวจรายงาน)
        $data['position'] = $data['role'] === 'teacher' ? 'ครู' : '';
    }
    // ป้องกัน admin เปลี่ยนสิทธิ์/สถานะของตนเองจนเข้าระบบไม่ได้
    if ($isSelf) {
        $data['role'] = $editing['role'];
        $data['status'] = $editing['status'];
    }
    $password = (string)($_POST['password'] ?? '');
    $mustChange = isset($_POST['must_change_password']) ? 1 : 0;

    // สิทธิ์ผู้ดูแลระบบ: null = เต็มสิทธิ์ / JSON = เจ้าหน้าที่ดูแลระบบ (กำหนดได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์ และไม่ใช่บัญชีตนเอง)
    $oldPerms = $isNew ? null : ($editing['admin_permissions'] ?? null);
    $adminPerms = $oldPerms;
    if ($data['role'] !== 'admin') {
        $adminPerms = null;
    } elseif ($isFullEditor && !$isSelf && $permReady) {
        $adminPerms = null;
        if (post('admin_type') === 'limited') {
            $picked = array_filter((array)($_POST['perms'] ?? []), 'is_string');
            $adminPerms = json_encode(array_values(array_intersect($permKeys, $picked)));
        }
    }

    // ---------- ตรวจสอบ ----------
    if (!valid_username($data['username'])) {
        $errors['username'] = 'ชื่อผู้ใช้ต้องเป็น a-z, 0-9, _ . - ยาว 4-30 ตัว';
    } elseif (db_value('SELECT id FROM users WHERE username = ? AND id <> ?', 'si', [$data['username'], $id])) {
        $errors['username'] = 'ชื่อผู้ใช้นี้ถูกใช้แล้ว';
    }
    if (!in_array($data['prefix'], name_prefixes(), true)) {
        $errors['prefix'] = 'กรุณาเลือกคำนำหน้า';
    }
    if ($data['first_name'] === '') {
        $errors['first_name'] = 'กรุณากรอกชื่อ';
    }
    if ($data['last_name'] === '') {
        $errors['last_name'] = 'กรุณากรอกนามสกุล';
    }
    if ($data['department_id'] && !in_array($data['department_id'], array_map('intval', array_column($departments, 'id')), true)) {
        $errors['department_id'] = 'แผนกไม่ถูกต้อง';
    }
    if (!isset(role_labels()[$data['role']])) {
        $errors['role'] = 'สิทธิ์ไม่ถูกต้อง';
    } elseif (!$isFullEditor && $data['role'] === 'admin') {
        $errors['role'] = 'เจ้าหน้าที่ดูแลระบบกำหนดสิทธิ์ผู้ดูแลระบบให้ผู้อื่นไม่ได้';
    } elseif (in_array($data['role'], ['teacher', 'supervisor'], true) && !$data['department_id']
        && !($data['role'] === 'teacher' && is_unit_staff_position($data['position']))) {
        // ตำแหน่งเจ้าหน้าที่งาน (เช่น เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว) ไม่สังกัดแผนกวิชาได้
        $errors['department_id'] = 'ครูและหัวหน้าแผนกต้องระบุแผนกวิชา (ยกเว้นตำแหน่งเจ้าหน้าที่งาน)';
    }
    if (!isset(user_status_labels()[$data['status']])) {
        $errors['status'] = 'สถานะไม่ถูกต้อง';
    }
    if (!in_array($data['academic_standing'], academic_standing_options(), true)) {
        $errors['academic_standing'] = 'วิทยฐานะไม่ถูกต้อง';
    }
    if (!valid_email($data['email'])) {
        $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (db_value('SELECT id FROM users WHERE email = ? AND id <> ?', 'si', [$data['email'], $id])) {
        $errors['email'] = 'อีเมลนี้มีผู้ใช้อื่นใช้แล้ว';
    }
    if ($data['phone'] !== '' && !valid_phone($data['phone'])) {
        $errors['phone'] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
    }
    if ($isNew || $password !== '') {
        if ($msg = password_policy_error($password)) {
            $errors['password'] = $msg;
        }
    }
    // ห้ามลดสิทธิ์/ระงับ/จำกัดสิทธิ์ ผู้ดูแลระบบเต็มสิทธิ์คนสุดท้าย
    if (!$isNew && is_full_admin($editing)
        && ($data['role'] !== 'admin' || $data['status'] !== 'active' || $adminPerms !== null)
        && full_admin_count($id) < 1) {
        $errors['role'] = 'ต้องมีผู้ดูแลระบบเต็มสิทธิ์ที่ใช้งานได้อย่างน้อย 1 คน';
    }

    // ---------- บันทึก ----------
    if (!$errors) {
        $deptId = $data['department_id'] ?: null;
        if ($isNew) {
            $newId = db_insert(
                'INSERT INTO users (username, password_hash, prefix, first_name, last_name, department_id,
                                    position, academic_standing, email, phone, role, status, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sssssissssssi',
                [$data['username'], password_hash($password, PASSWORD_DEFAULT), $data['prefix'], $data['first_name'],
                    $data['last_name'], $deptId, $data['position'], $data['academic_standing'],
                    $data['email'], $data['phone'], $data['role'], $data['status'], $mustChange]
            );
            if ($permReady && $adminPerms !== null) {
                db_exec('UPDATE users SET admin_permissions = ? WHERE id = ?', 'si', [$adminPerms, $newId]);
            }
            log_activity('user_create', 'เพิ่มผู้ใช้ ' . $data['username'] . ' สิทธิ์ ' . $data['role']
                . ($adminPerms !== null ? ' (เจ้าหน้าที่ดูแลระบบ: ' . implode(', ', json_decode($adminPerms, true)) . ')' : ''));
            flash('success', 'เพิ่มผู้ใช้ ' . $data['username'] . ' เรียบร้อยแล้ว');
            redirect('admin/user_edit.php?id=' . $newId);
        }

        db_exec(
            'UPDATE users SET username = ?, prefix = ?, first_name = ?, last_name = ?, department_id = ?,
                    position = ?, academic_standing = ?, email = ?, phone = ?, role = ?, status = ?
             WHERE id = ?',
            'ssssissssssi',
            [$data['username'], $data['prefix'], $data['first_name'], $data['last_name'], $deptId,
                $data['position'], $data['academic_standing'], $data['email'], $data['phone'], $data['role'],
                $data['status'], $id]
        );
        if ($permReady && $adminPerms !== $oldPerms) {
            db_exec('UPDATE users SET admin_permissions = ? WHERE id = ?', 'si', [$adminPerms, $id]);
        }
        if ($password !== '') {
            db_exec('UPDATE users SET password_hash = ?, must_change_password = ? WHERE id = ?', 'sii',
                [password_hash($password, PASSWORD_DEFAULT), $mustChange, $id]);
        }
        $changes = [];
        if ($editing['role'] !== $data['role']) {
            $changes[] = 'สิทธิ์ ' . $editing['role'] . ' → ' . $data['role'];
        }
        if ($editing['status'] !== $data['status']) {
            $changes[] = 'สถานะ ' . $editing['status'] . ' → ' . $data['status'];
        }
        if ($adminPerms !== $oldPerms && $data['role'] === 'admin') {
            $changes[] = $adminPerms === null ? 'ตั้งเป็นผู้ดูแลระบบเต็มสิทธิ์'
                : 'สิทธิ์เจ้าหน้าที่ดูแลระบบ: ' . (implode(', ', json_decode($adminPerms, true)) ?: 'ไม่มี');
        }
        if ($password !== '') {
            $changes[] = 'ตั้งรหัสผ่านใหม่';
        }
        log_activity('user_update', 'แก้ไขผู้ใช้ ' . $data['username'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
        flash('success', 'บันทึกข้อมูลผู้ใช้เรียบร้อยแล้ว');
        redirect('admin/user_edit.php?id=' . $id);
    }
}

$val = function (string $k, string $default = '') use ($editing) {
    if (is_post()) {
        return post($k);
    }
    return $editing !== null ? (string)($editing[$k] ?? '') : $default;
};
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

$pageTitle = $isNew ? 'เพิ่มผู้ใช้' : 'แก้ไขผู้ใช้: ' . full_name($editing);
$pageIcon = $isNew ? 'bi-person-plus' : 'bi-person-gear';
$breadcrumbs = ['จัดการผู้ใช้' => 'admin/users.php', ($isNew ? 'เพิ่มผู้ใช้' : 'แก้ไข') => null];
$activeMenu = 'admin/users.php';
require APP_ROOT . '/includes/header.php';
?>
<form method="post" novalidate data-confirm="ต้องการบันทึกข้อมูลผู้ใช้ใช่หรือไม่?">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><i class="bi bi-person-vcard me-2"></i>ข้อมูลส่วนตัว</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="prefix">คำนำหน้า *</label>
                            <select class="form-select<?= $invalid('prefix') ?>" name="prefix" id="prefix">
                                <option value="">-- เลือก --</option>
                                <?php foreach (name_prefixes() as $p): ?>
                                    <option value="<?= e($p) ?>" <?= $val('prefix') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                                <?php endforeach; ?>
                            </select><?= $err('prefix') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="first_name">ชื่อ *</label>
                            <input class="form-control<?= $invalid('first_name') ?>" name="first_name" id="first_name" value="<?= e($val('first_name')) ?>" maxlength="100"><?= $err('first_name') ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="last_name">นามสกุล *</label>
                            <input class="form-control<?= $invalid('last_name') ?>" name="last_name" id="last_name" value="<?= e($val('last_name')) ?>" maxlength="100"><?= $err('last_name') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="department_id">แผนกวิชา</label>
                            <select class="form-select<?= $invalid('department_id') ?>" name="department_id" id="department_id">
                                <option value="0">- ไม่สังกัดแผนก -</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" <?= (int)$val('department_id') === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select><?= $err('department_id') ?>
                            <div class="form-text">ตำแหน่งเจ้าหน้าที่งาน และผู้บริหาร เลือก “ไม่สังกัดแผนก” ได้</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="position">ตำแหน่ง</label>
                            <input class="form-control" name="position" id="position" value="<?= e($val('position')) ?>" maxlength="100" list="positionList">
                            <datalist id="positionList"><?php foreach (array_unique(array_merge(position_options(), reviewer_position_list(), hr_staff_positions())) as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
                            <div class="form-text">ตำแหน่งผู้ตรวจงาน: <?= e(implode(' · ', reviewer_position_list())) ?> (ได้สิทธิ์ตรวจงานตามประเภทอัตโนมัติ)<br>
                                งานบุคลากร: <?= e(implode(' · ', hr_staff_positions())) ?> (ได้สิทธิ์จัดการงานบุคลากรอัตโนมัติ)<br>หัวหน้างาน/เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว: เลือกที่ช่อง “สิทธิ์การใช้งาน”</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="academic_standing">วิทยฐานะ</label>
                            <select class="form-select<?= $invalid('academic_standing') ?>" name="academic_standing" id="academic_standing">
                                <?php foreach (academic_standing_options() as $s): ?>
                                    <option value="<?= e($s) ?>" <?= $val('academic_standing') === $s ? 'selected' : '' ?>><?= $s === '' ? '- ไม่มี -' : e($s) ?></option>
                                <?php endforeach; ?>
                            </select><?= $err('academic_standing') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">อีเมล *</label>
                            <input type="email" class="form-control<?= $invalid('email') ?>" name="email" id="email" value="<?= e($val('email')) ?>" maxlength="150"><?= $err('email') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">เบอร์โทรศัพท์</label>
                            <input class="form-control<?= $invalid('phone') ?>" name="phone" id="phone" value="<?= e($val('phone')) ?>" maxlength="12"><?= $err('phone') ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($isFullEditor):
                $permType = is_post() ? (post('admin_type') === 'limited' ? 'limited' : 'full') : (($editing && is_limited_admin($editing)) ? 'limited' : 'full');
                $permSel = is_post() ? array_filter((array)($_POST['perms'] ?? []), 'is_string') : (admin_permissions_of($editing ?? []) ?? []);
                $permLocked = $isSelf || !$permReady;
                ?>
                <div class="card mt-4" id="adminPermCard">
                    <div class="card-header"><i class="bi bi-person-lock me-2"></i>สิทธิ์ผู้ดูแลระบบ / เจ้าหน้าที่ดูแลระบบ</div>
                    <div class="card-body">
                        <?php if (!$permReady): ?>
                            <div class="alert alert-warning mb-3">ยังไม่ได้นำเข้า <code>update_v1.17.sql</code> — ยังกำหนดสิทธิ์เจ้าหน้าที่ดูแลระบบไม่ได้ (บัญชีผู้ดูแลระบบทุกบัญชีเป็นเต็มสิทธิ์)</div>
                        <?php elseif ($isSelf): ?>
                            <div class="alert alert-info mb-3">ไม่สามารถเปลี่ยนสิทธิ์ผู้ดูแลระบบของตนเองได้ (ป้องกันการล็อกตนเองออกจากระบบ)</div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="admin_type" id="adminTypeFull" value="full" <?= $permType === 'full' ? 'checked' : '' ?> <?= $permLocked ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="adminTypeFull"><strong>ผู้ดูแลระบบเต็มสิทธิ์</strong><br><small class="text-muted">ใช้ได้ทุกเมนู และกำหนดสิทธิ์ให้ผู้อื่นได้</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="admin_type" id="adminTypeLimited" value="limited" <?= $permType === 'limited' ? 'checked' : '' ?> <?= $permLocked ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="adminTypeLimited"><strong>เจ้าหน้าที่ดูแลระบบ (กำหนดสิทธิ์)</strong><br><small class="text-muted">ใช้ได้เฉพาะเมนูที่ติ๊กด้านล่าง · แก้ไขบัญชีผู้ดูแลระบบไม่ได้</small></label>
                            </div>
                        </div>
                        <div id="adminPermList">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                <span class="small text-muted">แม่แบบสิทธิ์:</span>
                                <?php foreach (admin_permission_presets() as $pk => [$plabel, $pperms]): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-perm-preset="<?= e(implode(',', $pperms)) ?>" <?= $permLocked ? 'disabled' : '' ?>><?= e($plabel) ?></button>
                                <?php endforeach; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-perm-preset="" <?= $permLocked ? 'disabled' : '' ?>>ล้างทั้งหมด</button>
                            </div>
                            <div class="row g-3">
                                <?php foreach ($permGroups as $gname => $gperms): ?>
                                    <div class="col-md-6">
                                        <div class="fw-semibold small text-primary-th mb-1"><?= e($gname) ?></div>
                                        <?php foreach ($gperms as $pkey => [$plabel, , $risky]): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="perms[]" value="<?= e($pkey) ?>" id="perm_<?= e($pkey) ?>"
                                                    <?= in_array($pkey, $permSel, true) ? 'checked' : '' ?> <?= $permLocked ? 'disabled' : '' ?>>
                                                <label class="form-check-label small" for="perm_<?= e($pkey) ?>"><?= e($plabel) ?>
                                                    <?php if ($risky): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1">หน้าเสี่ยง</span><?php endif; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text mt-2">ทุกบัญชีเปิดแดชบอร์ดและกระดานข่าวได้ · หน้าที่ไม่ได้รับสิทธิ์จะถูกซ่อนจากเมนู และเปิดด้วย URL ตรงจะถูกปฏิเสธ
                                · “หน้าเสี่ยง” เข้าถึงข้อมูลผู้ใช้ การตั้งค่า หรือฐานข้อมูลทั้งระบบ ควรให้เฉพาะผู้ที่จำเป็น</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-shield-lock me-2"></i>บัญชีและสิทธิ์</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="username">ชื่อผู้ใช้ *</label>
                        <input class="form-control<?= $invalid('username') ?>" name="username" id="username" value="<?= e($val('username')) ?>" maxlength="30" autocomplete="off"><?= $err('username') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="role">สิทธิ์การใช้งาน (Role) *</label>
                        <?php
                        // ค่าที่เลือกอยู่: ครูผู้สอนที่มีตำแหน่งเจ้าหน้าที่งาน แสดงเป็นตัวเลือกสิทธิ์ตามตำแหน่ง
                        $roleSel = $val('role', 'teacher');
                        $staffIdx = array_search(normalize_position($val('position')), advisor_staff_positions(), true);
                        if (!is_post() && $roleSel === 'teacher' && $staffIdx !== false) {
                            $roleSel = 'pos:' . $staffIdx;
                        }
                        ?>
                        <select class="form-select<?= $invalid('role') ?>" name="role" id="role" <?= $isSelf ? 'disabled' : '' ?>>
                            <optgroup label="สิทธิ์หลัก">
                                <?php foreach (role_labels() as $k => $v): if ($k === 'admin' && !$isFullEditor) continue; ?>
                                    <option value="<?= e($k) ?>" <?= $roleSel === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="งานครูที่ปรึกษาและแนะแนว (สิทธิ์ครูผู้สอน + ตำแหน่ง)">
                                <?php foreach (advisor_staff_positions() as $i => $p): ?>
                                    <option value="pos:<?= $i ?>" data-position="<?= e($p) ?>" <?= $roleSel === 'pos:' . $i ? 'selected' : '' ?>><?= e($p) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select><?= $err('role') ?>
                        <div class="form-text">เลือกหัวหน้างาน/เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว ระบบจะตั้งตำแหน่งให้อัตโนมัติ และได้สิทธิ์ตรวจรายงานกิจกรรมโฮมรูม/การเยี่ยมบ้าน
                            (เจ้าหน้าที่งานไม่ต้องสังกัดแผนกวิชา · หัวหน้างานต้องเลือกแผนก เพราะเป็นครูผู้สอนด้วย)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="status">สถานะ *</label>
                        <select class="form-select<?= $invalid('status') ?>" name="status" id="status" <?= $isSelf ? 'disabled' : '' ?>>
                            <?php foreach (user_status_labels() as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= $val('status', 'active') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('status') ?>
                        <?php if ($isSelf): ?><div class="form-text">ไม่สามารถเปลี่ยนสิทธิ์/สถานะของตนเองได้</div><?php endif; ?>
                    </div>
                    <hr>
                    <div class="mb-2">
                        <label class="form-label" for="password"><?= $isNew ? 'รหัสผ่าน *' : 'ตั้งรหัสผ่านใหม่' ?></label>
                        <div class="input-group has-validation">
                            <input type="password" class="form-control<?= $invalid('password') ?>" name="password" id="password" autocomplete="new-password"
                                   placeholder="<?= $isNew ? '' : 'เว้นว่างถ้าไม่ต้องการเปลี่ยน' ?>">
                            <span class="input-group-text toggle-password" data-target="#password"><i class="bi bi-eye"></i></span>
                            <?= $err('password') ?>
                        </div>
                        <div class="form-text">อย่างน้อย 8 ตัว มีทั้งตัวอักษรและตัวเลข</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="must_change_password" id="must_change_password" value="1"
                            <?= (!is_post() || isset($_POST['must_change_password'])) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="must_change_password">บังคับเปลี่ยนรหัสผ่านเมื่อเข้าระบบครั้งถัดไป</label>
                    </div>
                </div>
            </div>

            <?php if (!$isNew): ?>
                <div class="card mb-4">
                    <div class="card-body small">
                        <div class="d-flex"><span class="text-muted">สมัครเมื่อ</span><span class="ms-auto"><?= e(thai_datetime($editing['created_at'])) ?></span></div>
                        <div class="d-flex"><span class="text-muted">เข้าระบบล่าสุด</span><span class="ms-auto"><?= e(thai_datetime($editing['last_login_at'])) ?></span></div>
                        <div class="d-flex"><span class="text-muted">สถานะปัจจุบัน</span><span class="ms-auto"><?= user_status_badge($editing['status']) ?></span></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-accent" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึก</button>
                <a href="<?= e(url('admin/users.php')) ?>" class="btn btn-outline-secondary">กลับรายการผู้ใช้</a>
            </div>
        </div>
    </div>
</form>
<?php
// เลือกสิทธิ์ตามตำแหน่ง → เติมช่องตำแหน่งให้ (เจ้าหน้าที่งาน: ตั้งเป็นไม่สังกัดแผนก)
$pageScripts = '(function () {
    var role = document.getElementById("role"), pos = document.getElementById("position"), dept = document.getElementById("department_id");
    if (!role) return;
    var STAFF_POS = ' . json_encode(advisor_staff_positions(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    role.addEventListener("change", function () {
        var p = role.options[role.selectedIndex].getAttribute("data-position");
        if (!p) {
            // เปลี่ยนกลับเป็นสิทธิ์หลัก: ล้างตำแหน่งงานครูที่ปรึกษาฯ
            if (STAFF_POS.indexOf(pos.value.trim()) !== -1) pos.value = role.value === "teacher" ? "ครู" : "";
            return;
        }
        pos.value = p;
        if (p.indexOf("เจ้าหน้าที่งาน") === 0) dept.value = "0";
    });
})();
(function () {
    var card = document.getElementById("adminPermCard"), role = document.getElementById("role");
    if (!card || !role) return;
    var list = document.getElementById("adminPermList"), limited = document.getElementById("adminTypeLimited");
    function sync() {
        card.style.display = role.value === "admin" ? "" : "none";
        list.style.display = limited.checked ? "" : "none";
    }
    role.addEventListener("change", sync);
    card.querySelectorAll("input[name=admin_type]").forEach(function (r) { r.addEventListener("change", sync); });
    card.querySelectorAll("[data-perm-preset]").forEach(function (b) {
        b.addEventListener("click", function () {
            var keys = b.getAttribute("data-perm-preset").split(",");
            card.querySelectorAll("input[name=\\"perms[]\\"]").forEach(function (c) { c.checked = keys.indexOf(c.value) !== -1; });
        });
    });
    sync();
})();';
require APP_ROOT . '/includes/footer.php';

