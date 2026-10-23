<?php
/**
 * ไฟล์: auth/profile.php
 * โปรไฟล์ของฉัน — แก้ไขข้อมูลส่วนตัวและรูปโปรไฟล์ (ทุก role)
 * ข้อมูลที่แก้เองไม่ได้: ชื่อผู้ใช้, แผนก, สิทธิ์ (ต้องให้ admin แก้)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$errors = [];
$avatarMaxBytes = 2 * 1024 * 1024; // รูปโปรไฟล์ไม่เกิน 2MB

if (is_post()) {
    csrf_verify();

    // ---------- ยกเลิกการเชื่อมบัญชี Google ----------
    if (post('action') === 'google_unlink') {
        if ((int)$user['password_login'] !== 1) {
            flash('error', 'ท่านสมัครด้วย Google และยังไม่ได้ตั้งรหัสผ่าน กรุณาตั้งรหัสผ่านก่อนยกเลิกการเชื่อม');
        } else {
            db_exec('UPDATE users SET google_sub = NULL WHERE id = ?', 'i', [$uid]);
            log_activity('google_unlink', 'ยกเลิกการเชื่อมบัญชี Google');
            flash('success', 'ยกเลิกการเชื่อมบัญชี Google แล้ว');
        }
        redirect('auth/profile.php');
    }

    $data = [
        'prefix'            => post('prefix'),
        'first_name'        => post('first_name'),
        'last_name'         => post('last_name'),
        'position'          => mb_substr(post('position'), 0, 100),
        'academic_standing' => post('academic_standing'),
        'email'             => mb_strtolower(post('email')),
        'phone'             => preg_replace('/[\s-]/', '', post('phone')),
    ];

    // ---------- ตรวจสอบข้อมูล ----------
    if (!in_array($data['prefix'], name_prefixes(), true)) {
        $errors['prefix'] = 'กรุณาเลือกคำนำหน้า';
    }
    if ($data['first_name'] === '' || mb_strlen($data['first_name']) > 100) {
        $errors['first_name'] = 'กรุณากรอกชื่อ';
    }
    if ($data['last_name'] === '' || mb_strlen($data['last_name']) > 100) {
        $errors['last_name'] = 'กรุณากรอกนามสกุล';
    }
    // ตำแหน่งผู้ตรวจงาน (เช่น หัวหน้างานบุคลากร) ให้สิทธิ์ตรวจงาน — กำหนด/เปลี่ยนได้เฉพาะผู้ดูแลระบบ
    if (normalize_position($data['position']) !== normalize_position((string)$user['position'])
        && (is_reviewer_position($data['position']) || is_reviewer_position((string)$user['position']))) {
        $errors['position'] = 'ตำแหน่งนี้เกี่ยวข้องกับสิทธิ์ตรวจงาน กำหนดหรือเปลี่ยนได้โดยผู้ดูแลระบบเท่านั้น';
    }
    if (!in_array($data['academic_standing'], academic_standing_options(), true)) {
        $errors['academic_standing'] = 'วิทยฐานะไม่ถูกต้อง';
    }
    if (!valid_email($data['email'])) {
        $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (db_value('SELECT id FROM users WHERE email = ? AND id <> ?', 'si', [$data['email'], $uid])) {
        $errors['email'] = 'อีเมลนี้มีผู้ใช้อื่นใช้แล้ว';
    }
    if ($data['phone'] !== '' && !valid_phone($data['phone'])) {
        $errors['phone'] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
    }

    // ---------- รูปโปรไฟล์ ----------
    $newAvatar = null;
    $removeAvatar = isset($_POST['remove_avatar']);
    if (!$errors && !empty($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = handle_upload($_FILES['avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'webp'], $avatarMaxBytes);
        if (!$up['ok']) {
            $errors['avatar'] = $up['error'];
        } else {
            $newAvatar = $up['stored_name'];
        }
    }

    // ---------- บันทึก ----------
    if (!$errors) {
        $avatar = $user['avatar'];
        if ($newAvatar !== null) {
            delete_upload('avatars', $user['avatar']);
            $avatar = $newAvatar;
        } elseif ($removeAvatar) {
            delete_upload('avatars', $user['avatar']);
            $avatar = null;
        }

        db_exec(
            'UPDATE users SET prefix = ?, first_name = ?, last_name = ?, position = ?, academic_standing = ?,
                    email = ?, phone = ?, avatar = ? WHERE id = ?',
            'ssssssssi',
            [$data['prefix'], $data['first_name'], $data['last_name'], $data['position'], $data['academic_standing'],
                $data['email'], $data['phone'], $avatar, $uid]
        );
        log_activity('update_profile', 'แก้ไขข้อมูลส่วนตัว');
        flash('success', 'บันทึกข้อมูลโปรไฟล์เรียบร้อยแล้ว');
        redirect('auth/profile.php');
    } elseif ($newAvatar !== null) {
        delete_upload('avatars', $newAvatar); // บันทึกไม่สำเร็จ ลบรูปที่เพิ่งอัปโหลด
    }
}

/** ค่าในฟอร์ม: ถ้าส่งฟอร์มแล้วใช้ค่าที่กรอก ไม่เช่นนั้นใช้ค่าจากฐานข้อมูล */
$val = fn(string $k) => is_post() ? post($k) : (string)($user[$k] ?? '');
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

$pageTitle = 'โปรไฟล์ของฉัน';
$pageIcon = 'bi-person-circle';
$breadcrumbs = ['โปรไฟล์ของฉัน' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-center">
            <div class="card-body py-4">
                <img src="<?= e(avatar_url($user)) ?>" alt="รูปโปรไฟล์" class="avatar-lg mb-3" id="avatarPreview">
                <h5 class="fw-bold mb-0"><?= e(full_name($user)) ?></h5>
                <div class="text-muted"><?= e($user['position']) ?></div>
                <span class="badge bg-primary-th mt-2"><?= e(user_role_label($user)) ?></span>
            </div>
            <ul class="list-group list-group-flush text-start small">
                <li class="list-group-item d-flex"><span class="text-muted">ชื่อผู้ใช้</span><span class="ms-auto fw-semibold"><?= e($user['username']) ?></span></li>
                <li class="list-group-item d-flex"><span class="text-muted">แผนกวิชา</span><span class="ms-auto text-end"><?= e($user['department_name'] ?? '-') ?></span></li>
                <li class="list-group-item d-flex"><span class="text-muted">เข้าระบบล่าสุด</span><span class="ms-auto"><?= e(thai_datetime($user['last_login_at'])) ?></span></li>
                <li class="list-group-item d-flex"><span class="text-muted">สมัครเมื่อ</span><span class="ms-auto"><?= e(thai_date($user['created_at'])) ?></span></li>
                <li class="list-group-item d-flex"><a href="<?= e(url('public/pdpa.php')) ?>" class="text-muted">ยอมรับข้อตกลง PDPA</a><span class="ms-auto"><?= e(thai_datetime($user['pdpa_accepted_at'])) ?></span></li>
            </ul>
            <div class="card-body">
                <a href="<?= e(url('auth/change_password.php')) ?>" class="btn btn-outline-primary w-100"><i class="bi bi-key me-1"></i>
                    <?= (int)$user['password_login'] === 1 ? 'เปลี่ยนรหัสผ่าน' : 'ตั้งรหัสผ่าน (ใช้แทน Google ได้)' ?></a>
                <?php if (!empty($user['google_sub'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-3 small">
                        <i class="bi bi-google text-success"></i><span class="flex-fill text-start">เชื่อมบัญชี Google แล้ว</span>
                        <?php if ((int)$user['password_login'] === 1): ?>
                            <form method="post" class="d-inline" data-confirm="ยกเลิกการเชื่อมบัญชี Google? หลังจากนี้ต้องเข้าสู่ระบบด้วยรหัสผ่าน" data-confirm-icon="warning">
                                <?= csrf_field() ?><input type="hidden" name="action" value="google_unlink">
                                <button class="btn btn-sm btn-link text-danger p-0">ยกเลิกการเชื่อม</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php elseif (google_enabled()): ?>
                    <a href="<?= e(url('auth/google_login.php?mode=link')) ?>" class="btn btn-outline-secondary w-100 mt-2"><i class="bi bi-google me-1"></i> เชื่อมต่อบัญชี Google</a>
                <?php endif; ?>
                <div class="small text-muted mt-2">ต้องการแก้ชื่อผู้ใช้หรือแผนก กรุณาติดต่อผู้ดูแลระบบ</div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil-square me-2"></i>แก้ไขข้อมูลส่วนตัว</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" novalidate data-confirm="ต้องการบันทึกการแก้ไขข้อมูลใช่หรือไม่?">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="prefix">คำนำหน้า</label>
                            <select class="form-select<?= $invalid('prefix') ?>" name="prefix" id="prefix">
                                <?php foreach (name_prefixes() as $p): ?>
                                    <option value="<?= e($p) ?>" <?= $val('prefix') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $err('prefix') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="first_name">ชื่อ</label>
                            <input class="form-control<?= $invalid('first_name') ?>" name="first_name" id="first_name" value="<?= e($val('first_name')) ?>" maxlength="100">
                            <?= $err('first_name') ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="last_name">นามสกุล</label>
                            <input class="form-control<?= $invalid('last_name') ?>" name="last_name" id="last_name" value="<?= e($val('last_name')) ?>" maxlength="100">
                            <?= $err('last_name') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="position">ตำแหน่ง</label>
                            <?php $lockPos = is_reviewer_position((string)$user['position']); ?>
                            <input class="form-control<?= $invalid('position') ?>" name="position" id="position" value="<?= e($val('position')) ?>" maxlength="100" list="positionList"
                                <?= $lockPos ? 'readonly' : '' ?>>
                            <datalist id="positionList">
                                <?php foreach (position_options() as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?>
                            </datalist>
                            <?php if ($lockPos): ?><div class="form-text">ตำแหน่งผู้ตรวจงาน — เปลี่ยนได้โดยผู้ดูแลระบบ</div><?php endif; ?>
                            <?= $err('position') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="academic_standing">วิทยฐานะ</label>
                            <select class="form-select<?= $invalid('academic_standing') ?>" name="academic_standing" id="academic_standing">
                                <?php foreach (academic_standing_options() as $s): ?>
                                    <option value="<?= e($s) ?>" <?= $val('academic_standing') === $s ? 'selected' : '' ?>><?= $s === '' ? '- ไม่มี -' : e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $err('academic_standing') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">อีเมล</label>
                            <input type="email" class="form-control<?= $invalid('email') ?>" name="email" id="email" value="<?= e($val('email')) ?>" maxlength="150">
                            <?= $err('email') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">เบอร์โทรศัพท์</label>
                            <input type="tel" class="form-control<?= $invalid('phone') ?>" name="phone" id="phone" value="<?= e($val('phone')) ?>" maxlength="12">
                            <?= $err('phone') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="avatar">รูปโปรไฟล์ <span class="text-muted small">(JPG, PNG, WEBP ไม่เกิน 2MB)</span></label>
                            <input type="file" class="form-control<?= $invalid('avatar') ?>" name="avatar" id="avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <?= $err('avatar') ?>
                            <?php if ($user['avatar']): ?>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_avatar" id="remove_avatar" value="1">
                                    <label class="form-check-label" for="remove_avatar">ลบรูปโปรไฟล์ปัจจุบัน</label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
// แสดงตัวอย่างรูปก่อนอัปโหลด
$pageScripts = 'document.getElementById("avatar").addEventListener("change", function (e) {
    var f = e.target.files[0];
    if (f && f.type.indexOf("image/") === 0) { document.getElementById("avatarPreview").src = URL.createObjectURL(f); }
});';
require APP_ROOT . '/includes/footer.php';
