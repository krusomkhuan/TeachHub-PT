<?php
/**
 * ไฟล์: auth/change_password.php
 * เปลี่ยนรหัสผ่าน — ใช้ได้ทุก role
 * กรณีบังคับเปลี่ยน (admin เริ่มต้น / ถูกรีเซ็ตรหัส) ระบบจะพามาหน้านี้ก่อนเข้าใช้งานส่วนอื่น
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$forced = (int)$user['must_change_password'] === 1;
// สมัครด้วย Google และยังไม่เคยตั้งรหัสผ่าน → ตั้งรหัสผ่านใหม่ได้โดยไม่ต้องกรอกรหัสเดิม
$firstPassword = (int)$user['password_login'] === 0 && !$forced;
$errors = [];

if (is_post()) {
    csrf_verify();

    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $hash = (string)db_value('SELECT password_hash FROM users WHERE id = ?', 'i', [(int)$user['id']]);

    if (!$firstPassword && !password_verify($current, $hash)) {
        $errors['current_password'] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
    }
    if ($msg = password_policy_error($new)) {
        $errors['new_password'] = $msg;
    } elseif (password_verify($new, $hash)) {
        $errors['new_password'] = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
    } elseif ($new !== $confirm) {
        $errors['confirm_password'] = 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน';
    }

    if (!$errors) {
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0, password_login = 1 WHERE id = ?',
            'si',
            [password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]
        );
        // สร้าง session id ใหม่หลังเปลี่ยนรหัสผ่าน
        session_regenerate_id(true);
        log_activity('change_password', 'เปลี่ยนรหัสผ่านสำเร็จ');
        flash('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
        redirect(dashboard_url($user['role']));
    }
}

$pageTitle = 'เปลี่ยนรหัสผ่าน';
require APP_ROOT . '/includes/auth_header.php';
?>
<h3 class="auth-title"><i class="bi bi-shield-lock me-1"></i> เปลี่ยนรหัสผ่าน</h3>
<p class="auth-subtitle"><?= e(full_name($user)) ?> (<?= e($user['username']) ?>)</p>

<?php if ($forced): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-circle me-1"></i>
        ท่านกำลังใช้รหัสผ่านเริ่มต้นหรือรหัสผ่านชั่วคราว กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งานระบบ
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/change_password.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($firstPassword): ?>
        <div class="alert alert-info small"><i class="bi bi-google me-1"></i>ท่านสมัครด้วยบัญชี Google — ตั้งรหัสผ่านเพื่อใช้เข้าสู่ระบบด้วยชื่อผู้ใช้ <strong><?= e($user['username']) ?></strong> ได้อีกทาง</div>
    <?php else: ?>
    <div class="mb-3">
        <label class="form-label" for="current_password">รหัสผ่านปัจจุบัน</label>
        <div class="input-group has-validation">
            <input type="password" class="form-control<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                   id="current_password" name="current_password" required autocomplete="current-password" autofocus>
            <span class="input-group-text toggle-password" data-target="#current_password"><i class="bi bi-eye"></i></span>
            <?php if (isset($errors['current_password'])): ?><div class="invalid-feedback"><?= e($errors['current_password']) ?></div><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="mb-3">
        <label class="form-label" for="new_password">รหัสผ่านใหม่</label>
        <div class="input-group has-validation">
            <input type="password" class="form-control<?= isset($errors['new_password']) ? ' is-invalid' : '' ?>"
                   id="new_password" name="new_password" required autocomplete="new-password" data-strength="#pwMeter">
            <span class="input-group-text toggle-password" data-target="#new_password"><i class="bi bi-eye"></i></span>
            <?php if (isset($errors['new_password'])): ?><div class="invalid-feedback"><?= e($errors['new_password']) ?></div><?php endif; ?>
        </div>
        <div class="pw-strength mt-1" id="pwMeter"><div></div></div>
        <div class="form-text">อย่างน้อย 8 ตัว มีทั้งตัวอักษรภาษาอังกฤษและตัวเลข</div>
    </div>
    <div class="mb-4">
        <label class="form-label" for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
        <input type="password" class="form-control<?= isset($errors['confirm_password']) ? ' is-invalid' : '' ?>"
               id="confirm_password" name="confirm_password" required autocomplete="new-password">
        <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?= e($errors['confirm_password']) ?></div><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-accent w-100 py-2" data-loading-text="กำลังบันทึก...">
        <i class="bi bi-check2-circle me-1"></i> บันทึกรหัสผ่านใหม่
    </button>
</form>

<div class="d-flex justify-content-between mt-4">
    <?php if (!$forced): ?>
        <a href="<?= e(url(dashboard_url($user['role']))) ?>"><i class="bi bi-arrow-left"></i> กลับแดชบอร์ด</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <a href="<?= e(url('auth/logout.php?_csrf=' . csrf_token())) ?>" class="text-danger"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
</div>

<?php require APP_ROOT . '/includes/auth_footer.php'; ?>
