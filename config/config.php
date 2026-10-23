<?php
/**
 * ไฟล์: config/config.php
 * ไฟล์ตั้งค่าหลักของระบบ (bootstrap) — ทุกหน้าต้อง require ไฟล์นี้เป็นอันดับแรก
 *   require_once __DIR__ . '/../config/config.php';
 */

// ---------------------------------------------------------------
// โหมดพัฒนา: true = แสดง error (ใช้ตอนพัฒนา), false = ซ่อน (ใช้งานจริง)
// ---------------------------------------------------------------
define('APP_DEBUG', true);

define('APP_NAME', 'TeachHub PT');
define('APP_VERSION', '1.17.0');
define('APP_DEVELOPER', 'ครูสมควร สากำ');

// ---------------------------------------------------------------
// โทนสีหลักของระบบ (ค่าเริ่มต้น — admin ปรับได้ในหน้าตั้งค่าระบบ)
// ---------------------------------------------------------------
define('COLOR_PRIMARY_DEFAULT', '#1b2a4e'); // น้ำเงินกรมท่า
define('COLOR_ACCENT_DEFAULT', '#f47b20');  // ส้ม

// ---------------------------------------------------------------
// ตั้งค่าพื้นฐาน PHP
// ---------------------------------------------------------------
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------
// พาธของระบบ
// ---------------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_PATH', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');

/**
 * BASE_URL = URL ของโฟลเดอร์ระบบ เช่น /teachhub_pt
 * ระบบตรวจหาให้อัตโนมัติ หากติดตั้งแล้ว URL ไม่ถูกต้อง ให้กำหนดเองแทน เช่น
 *   define('BASE_URL', '/teachhub_pt');   หรือ   define('BASE_URL', '');  (ติดตั้งที่ root ของโดเมน)
 */
if (!defined('BASE_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(APP_ROOT);
    $base = '';
    if ($docRoot && $appRoot && stripos($appRoot, $docRoot) === 0) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
    }
    define('BASE_URL', rtrim($base, '/'));
}

// ---------------------------------------------------------------
// ตั้งค่า Session ให้ปลอดภัย
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('TEACHHUBSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (BASE_URL === '' ? '/' : BASE_URL . '/'),
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------
// โหลดไฟล์ที่จำเป็น
// ---------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/admin_perm.php';
require_once APP_ROOT . '/includes/submissions.php';
require_once APP_ROOT . '/includes/supervision.php';
require_once APP_ROOT . '/includes/student_eval.php';
require_once APP_ROOT . '/includes/supervision_report.php';
require_once APP_ROOT . '/includes/reports.php';
require_once APP_ROOT . '/includes/pdpa.php';
require_once APP_ROOT . '/includes/teaching.php';
require_once APP_ROOT . '/includes/google_auth.php';
require_once APP_ROOT . '/includes/backup.php';
require_once APP_ROOT . '/includes/board.php';
require_once APP_ROOT . '/includes/advisor.php';
require_once APP_ROOT . '/includes/home_visit.php';
require_once APP_ROOT . '/includes/plan_eval.php';

// ส่ง header ความปลอดภัยพื้นฐาน
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
