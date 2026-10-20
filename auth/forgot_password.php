<?php
/**
 * ไฟล์: auth/forgot_password.php
 * ลืมรหัสผ่าน — ผู้ใช้ส่งคำขอ แล้ว admin เป็นผู้รีเซ็ตรหัสผ่านให้ในหน้าจัดการผู้ใช้
 * ตรวจยืนยันตัวตนด้วย ชื่อผู้ใช้/อีเมล + เบอร์โทรศัพท์ที่ลงทะเบียนไว้
 */
require_once __DIR__ . '/../config/config.php';

redirect_if_logged_in();

$done = false;
$error = '';

if (is_post()) {
    csrf_verify();

    $login = post('username');
    $phone = preg_replace('/[\s-]/', '', post('phone'));
    $note = mb_substr(post('note'), 0, 250);
    $ip = client_ip();

    // จำกัดการส่งคำขอ: ไม่เกิน 3 ครั้งต่อ IP ใน 1 ชั่วโมง
    $recent = (int)db_value(
        'SELECT COUNT(*) FROM password_reset_requests WHERE requested_ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
        's',
        [$ip]
    );

    if ($login === '' || $phone === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif ($recent >= 3) {
        $error = 'ส่งคำขอบ่อยเกินไป กรุณาลองใหม่ภายหลัง หรือติดต่องานพัฒนาหลักสูตรฯ โดยตรง';
    } else {
        $user = db_one(
            "SELECT id, username FROM users WHERE (username = ? OR email = ?) AND phone <> '' AND phone = ? LIMIT 1",
            'sss',
            [$login, mb_strtolower($login), $phone]
        );

        if ($user) {
            // ไม่สร้างคำขอซ้ำถ้ายังมีคำขอที่รอดำเนินการอยู่
            $pending = db_value(
                'SELECT id FROM password_reset_requests WHERE user_id = ? AND status = \'pending\' LIMIT 1',
                'i',
                [(int)$user['id']]
            );
            if (!$pending) {
                db_insert(
                    'INSERT INTO password_reset_requests (user_id, request_note, requested_ip) VALUES (?, ?, ?)',
                    'iss',
                    [(int)$user['id'], $note, $ip]
                );
                log_activity('forgot_password', 'ส่งคำขอรีเซ็ตรหัสผ่าน', (int)$user['id']);
            }
        } else {
            log_activity('forgot_password', 'คำขอรีเซ็ตไม่พบผู้ใช้: ' . mb_substr($login, 0, 50));
        }
        // แสดงข้อความเหมือนกันทุกกรณี ไม่เปิดเผยว่ามีบัญชีหรือไม่
        $done = true;
    }
}

$pageTitle = 'ลืมรหัสผ่าน';
require APP_ROOT . '/includes/auth_header.php';
?>
<h3 class="auth-title"><i class="bi bi-key me-1"></i> ลืมรหัสผ่าน</h3>

<?php if ($done): ?>
    <div class="text-center py-3">
        <i class="bi bi-envelope-check text-success" style="font-size:3.5rem"></i>
        <h5 class="mt-3">ส่งคำขอเรียบร้อยแล้ว</h5>
        <p class="text-muted">หากข้อมูลถูกต้อง ผู้ดูแลระบบจะรีเซ็ตรหัสผ่านและแจ้งรหัสผ่านชั่วคราวให้ท่านทราบ
            เมื่อเข้าสู่ระบบด้วยรหัสชั่วคราวแล้ว ระบบจะให้ตั้งรหัสผ่านใหม่ทันที</p>
        <p class="small text-muted mb-4">ติดต่อผู้ดูแลระบบ</p>
        <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-accent px-4">กลับหน้าเข้าสู่ระบบ</a>
    </div>
<?php else: ?>
    <p class="auth-subtitle">กรอกข้อมูลเพื่อส่งคำขอให้ผู้ดูแลระบบรีเซ็ตรหัสผ่าน</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('auth/forgot_password.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="username" class="form-label">ชื่อผู้ใช้ หรือ อีเมล</label>
            <input type="text" class="form-control" id="username" name="username" value="<?= old('username') ?>" required autofocus>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">เบอร์โทรศัพท์ที่ลงทะเบียนไว้</label>
            <input type="tel" class="form-control" id="phone" name="phone" value="<?= old('phone') ?>" maxlength="12" placeholder="08xxxxxxxx" required>
        </div>
        <div class="mb-3">
            <label for="note" class="form-label">ช่องทางติดต่อกลับ / หมายเหตุ <span class="text-muted small">(ไม่บังคับ)</span></label>
            <input type="text" class="form-control" id="note" name="note" value="<?= old('note') ?>" maxlength="250"
                   placeholder="เช่น โทร 08x-xxx-xxxx หรือ LINE ID">
        </div>
        <button type="submit" class="btn btn-accent w-100 py-2" data-loading-text="กำลังส่งคำขอ...">
            <i class="bi bi-send me-1"></i> ส่งคำขอรีเซ็ตรหัสผ่าน
        </button>
    </form>
    <p class="text-center mt-4 mb-0"><a href="<?= e(url('auth/login.php')) ?>"><i class="bi bi-arrow-left"></i> กลับหน้าเข้าสู่ระบบ</a></p>
<?php endif; ?>

<?php require APP_ROOT . '/includes/auth_footer.php'; ?>
