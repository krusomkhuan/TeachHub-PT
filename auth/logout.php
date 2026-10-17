<?php
/**
 * ไฟล์: auth/logout.php
 * ออกจากระบบ — ต้องส่ง CSRF token มาด้วย (POST หรือ ?_csrf=) ป้องกันการถูกบังคับออกจากระบบ
 * ตัวอย่างลิงก์: <a href="<?= e(url('auth/logout.php?_csrf=' . csrf_token())) ?>">ออกจากระบบ</a>
 */
require_once __DIR__ . '/../config/config.php';

if (is_logged_in()) {
    if (!csrf_check()) {
        // token ไม่ถูกต้อง: ไม่ออกจากระบบ กลับไปหน้าแดชบอร์ด
        $u = current_user();
        redirect($u ? dashboard_url($u['role']) : 'auth/login.php');
    }
    log_activity('logout', 'ออกจากระบบ');
    logout_user();
    flash('success', 'ออกจากระบบเรียบร้อยแล้ว');
}

redirect('auth/login.php');
