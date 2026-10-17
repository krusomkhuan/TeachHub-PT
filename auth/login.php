<?php
/**
 * ไฟล์: auth/login.php
 * หน้าเข้าสู่ระบบ — ล็อกอินด้วยชื่อผู้ใช้หรืออีเมล
 */
require_once __DIR__ . '/../config/config.php';

redirect_if_logged_in();

$error = '';
$next = $_GET['next'] ?? $_POST['next'] ?? '';
$next = is_string($next) ? $next : '';

if (is_post()) {
    csrf_verify();

    $login = post('username');
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();

    if ($login === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif (login_locked($ip)) {
        $error = 'เข้าสู่ระบบผิดพลาดหลายครั้งเกินไป กรุณารอ 15 นาทีแล้วลองใหม่';
    } else {
        $user = db_one(
            'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
            'ss',
            [$login, $login]
        );

        // ตรวจรหัสผ่าน (ถ้าไม่พบผู้ใช้ ยังเรียก password_verify เพื่อให้เวลาตอบสนองใกล้เคียงกัน)
        $dummyHash = '$2y$10$w1Um.4L0jJWMyJwBo6yBH.BrI1yUhE6eei5wrWd5dA0WjeWmlPsw6';
        $ok = password_verify($password, $user['password_hash'] ?? $dummyHash) && $user !== null;

        if (!$ok) {
            log_activity('login_failed', 'ชื่อผู้ใช้: ' . mb_substr($login, 0, 50), $user ? (int)$user['id'] : null);
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        } elseif ($user['status'] === 'pending') {
            $error = 'บัญชีของท่านอยู่ระหว่างรออนุมัติจากผู้ดูแลระบบ';
        } elseif ($user['status'] === 'suspended') {
            $error = 'บัญชีของท่านถูกระงับการใช้งาน กรุณาติดต่องานพัฒนาหลักสูตรฯ';
        } else {
            // อัปเกรด hash อัตโนมัติถ้าอัลกอริทึมเปลี่ยน
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', 'si',
                    [password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
            }

            login_user($user);

            // ต้องเปลี่ยนรหัสผ่านก่อน (หน้าเปลี่ยนรหัสผ่านจะแสดงคำอธิบายเอง)
            if ((int)$user['must_change_password'] === 1) {
                redirect('auth/change_password.php');
            }

            flash('success', 'ยินดีต้อนรับ ' . full_name($user));
            header('Location: ' . safe_next($next, dashboard_url($user['role'])));
            exit;
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
require APP_ROOT . '/includes/auth_header.php';
?>
<h3 class="auth-title"><i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ</h3>
<p class="auth-subtitle">สำหรับครูและบุคลากร <?= e(college_name()) ?></p>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/login.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">

    <div class="mb-3">
        <label for="username" class="form-label">ชื่อผู้ใช้ หรือ อีเมล</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control" id="username" name="username"
                   value="<?= old('username') ?>" required autofocus autocomplete="username">
        </div>
    </div>

    <div class="mb-2">
        <label for="password" class="form-label">รหัสผ่าน</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
            <span class="input-group-text toggle-password" data-target="#password" title="แสดง/ซ่อนรหัสผ่าน"><i class="bi bi-eye"></i></span>
        </div>
    </div>

    <div class="text-end mb-3">
        <a href="<?= e(url('auth/forgot_password.php')) ?>" class="small">ลืมรหัสผ่าน?</a>
    </div>

    <button type="submit" class="btn btn-accent w-100 py-2" data-loading-text="กำลังตรวจสอบ...">
        <i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ
    </button>
</form>

<?php if (google_enabled()): ?>
    <div class="text-center text-muted small my-3">หรือ</div>
    <?= google_button_html('เข้าสู่ระบบด้วย Google', url('auth/google_login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''))) ?>
    <?php if (google_allowed_domain() !== ''): ?>
        <div class="form-text text-center">ใช้ได้เฉพาะบัญชี @<?= e(google_allowed_domain()) ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (setting('allow_register', '1') === '1'): ?>
    <hr class="my-4">
    <p class="text-center mb-0">ยังไม่มีบัญชี?
        <a href="<?= e(url('auth/register.php')) ?>" class="fw-semibold">สมัครสมาชิก</a>
    </p>
<?php endif; ?>
<p class="text-center mt-2 mb-0"><a href="<?= e(url('public/index.php')) ?>" class="small text-muted"><i class="bi bi-house"></i> กลับหน้าแรก</a></p>

<?php require APP_ROOT . '/includes/auth_footer.php'; ?>
