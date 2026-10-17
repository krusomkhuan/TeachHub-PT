<?php
/**
 * ไฟล์: config/db.php
 * ตั้งค่าการเชื่อมต่อฐานข้อมูล MySQL/MariaDB ด้วย MySQLi
 * ** แก้ค่า DB_* ให้ตรงกับเครื่อง/โฮสติ้งของท่าน **
 */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'teachhub_pt');

/**
 * คืนค่าการเชื่อมต่อ mysqli (สร้างครั้งเดียวต่อหนึ่ง request)
 */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    // ให้ MySQLi โยน Exception เมื่อเกิดข้อผิดพลาด จะได้จัดการได้ง่าย
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
        // ตั้ง timezone ของ session ฐานข้อมูลให้ตรงกับ Asia/Bangkok
        $conn->query("SET time_zone = '+07:00'");
    } catch (mysqli_sql_exception $ex) {
        error_log('DB connect error: ' . $ex->getMessage());
        http_response_code(500);
        $msg = (defined('APP_DEBUG') && APP_DEBUG) ? htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') : '';
        exit('<div style="font-family:sans-serif;padding:2rem">ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่าใน config/db.php<br><small>' . $msg . '</small></div>');
    }

    return $conn;
}
