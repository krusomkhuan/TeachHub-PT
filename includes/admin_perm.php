<?php
/**
 * ไฟล์: includes/admin_perm.php
 * สิทธิ์เจ้าหน้าที่ดูแลระบบ (v1.17)
 *
 * - บัญชี role 'admin' ที่ users.admin_permissions = NULL  → ผู้ดูแลระบบเต็มสิทธิ์ (เหมือนเดิม)
 * - บัญชี role 'admin' ที่ users.admin_permissions = JSON  → เจ้าหน้าที่ดูแลระบบ ใช้ได้เฉพาะเมนูที่ได้รับสิทธิ์
 * - กำหนดสิทธิ์ได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์ (admin/user_edit.php) และต้องมีผู้ดูแลระบบเต็มสิทธิ์อย่างน้อย 1 คนเสมอ
 *
 * การตรวจสิทธิ์ฝั่งเซิร์ฟเวอร์อยู่ที่ require_login() → admin_perm_enforce():
 * ทุกหน้าที่อยู่ในรายการสิทธิ์ด้านล่าง ต้องมีสิทธิ์นั้น และหน้าใน admin/ ที่ไม่มีในรายการ (นอกจากแดชบอร์ด) ถูกปฏิเสธเสมอ
 */

/**
 * รายการสิทธิ์: [กลุ่ม => [รหัสสิทธิ์ => [ชื่อสิทธิ์, [หน้าที่ใช้สิทธิ์นี้], หน้าเสี่ยง]]]
 * หน้าเสี่ยง = เข้าถึงข้อมูลผู้ใช้/ตั้งค่า/ฐานข้อมูลทั้งระบบ — แม่แบบสิทธิ์ไม่ติ๊กให้อัตโนมัติ
 */
function admin_permission_groups(): array
{
    return [
        'ตรวจงานที่ส่ง' => [
            'lesson_plans'       => ['โครงการสอน/แผนการสอน', ['admin/lesson_plans.php'], false],
            'pa'                 => ['ข้อตกลง PA', ['admin/pa.php'], false],
            'research'           => ['วิจัยในชั้นเรียน', ['admin/research.php'], false],
            'plan_eval'          => ['ประเมินแผนการจัดการเรียนรู้ + รายงานผลประเมินแผนฯ',
                ['review/plan_evaluations.php', 'reports/plan_eval_summary.php', 'reports/plan_eval_print.php'], false],
            'plan_eval_criteria' => ['รายการแบบประเมินแผนฯ', ['admin/plan_eval_criteria.php'], false],
        ],
        'นิเทศการสอน' => [
            'supervision'          => ['นิเทศโดยหัวหน้าแผนก', ['admin/supervision.php', 'reports/supervision_print.php'], false],
            'student_eval'         => ['นิเทศโดยนักเรียน (QR Code)', ['admin/student_eval.php', 'reports/student_eval_qr.php'], false],
            'supervision_criteria' => ['หัวข้อแบบประเมินนิเทศ', ['admin/supervision_criteria.php'], false],
            'supervision_reports'  => ['รายงานการนิเทศ (เสนอ/พิจารณา)',
                ['reports/supervision_reports.php', 'reports/supervision_report.php', 'reports/supervision_report_print.php'], false],
        ],
        'งานครูที่ปรึกษาและแนะแนว' => [
            'advisor' => ['รายงานกิจกรรมโฮมรูม / เยี่ยมบ้าน / กลุ่มที่ปรึกษา และสรุปรายงาน',
                ['review/advisor_reports.php', 'review/advisor_groups.php', 'review/home_visits.php', 'reports/advisor_summary.php',
                    'reports/advisor_teacher.php', 'reports/home_visit_summary.php', 'reports/home_visit_teacher.php'], false],
        ],
        'งานบุคลากร' => [
            'hr' => ['งานบุคลากร (ทะเบียนประวัติทุกคน ตรวจสอบใบลา/การอบรม คำสั่ง กิจกรรมวันสำคัญ รายงานสรุป)',
                ['hr/staff.php', 'hr/leave_review.php', 'hr/leave_quotas.php', 'hr/trainings_review.php',
                    'hr/orders_manage.php', 'hr/events_manage.php', 'hr/reports.php', 'hr/report_print.php'], true],
        ],
        'กำหนดการ & รายงาน' => [
            'rounds'  => ['รอบการส่งงาน', ['admin/rounds.php'], false],
            'reports' => ['รายงานผล + เกณฑ์การส่งรายภาคเรียน', ['reports/index.php', 'reports/teaching_compliance.php'], false],
        ],
        'ข้อมูลพื้นฐาน' => [
            'users'          => ['จัดการผู้ใช้ (อนุมัติ/แก้ไข/รีเซ็ตรหัสผ่าน — ยกเว้นบัญชีผู้ดูแลระบบ)', ['admin/users.php', 'admin/user_edit.php'], true],
            'departments'    => ['แผนกวิชา', ['admin/departments.php'], false],
            'academic_years' => ['ปีการศึกษา/ภาคเรียน', ['admin/academic_years.php'], false],
            'subjects'       => ['รายวิชา', ['admin/subjects.php'], false],
        ],
        'เนื้อหาเว็บไซต์' => [
            'announcements' => ['ประกาศข่าว', ['admin/announcements.php'], false],
            'downloads'     => ['เอกสารดาวน์โหลด + หมวดหมู่', ['admin/downloads.php', 'admin/download_categories.php'], false],
            'pdpa'          => ['ข้อตกลง PDPA', ['admin/pdpa.php'], false],
            'board'         => ['ดูแลกระดานข่าว (แก้ไข/ปิด/ลบกระทู้)', [], false],
        ],
        'ระบบ' => [
            'settings'     => ['ตั้งค่าระบบ', ['admin/settings.php'], true],
            'backup'       => ['สำรอง / กู้คืนข้อมูล', ['admin/backup.php'], true],
            'drive'        => ['ที่เก็บไฟล์ Google Drive', ['admin/drive.php'], true],
            'logs'         => ['บันทึกการใช้งาน', ['admin/logs.php'], true],
            'system_check' => ['ตรวจสอบระบบ', ['admin/system_check.php'], false],
        ],
    ];
}

/** รหัสสิทธิ์ทั้งหมด => [ชื่อ, หน้า, หน้าเสี่ยง] */
function admin_permission_list(): array
{
    return array_merge(...array_values(admin_permission_groups()));
}

/** แม่แบบสิทธิ์ (ใช้ติ๊กให้อัตโนมัติในหน้าแก้ไขผู้ใช้ — ไม่เก็บในฐานข้อมูล) */
function admin_permission_presets(): array
{
    $safe = array_keys(array_filter(admin_permission_list(), fn($p) => !$p[2]));
    return [
        'curriculum' => ['งานพัฒนาหลักสูตรฯ', ['lesson_plans', 'pa', 'research', 'plan_eval', 'plan_eval_criteria', 'supervision',
            'student_eval', 'supervision_criteria', 'supervision_reports', 'rounds', 'reports', 'subjects']],
        'content'    => ['งานเนื้อหาเว็บไซต์', ['announcements', 'downloads', 'pdpa', 'board']],
        'safe'       => ['ทั้งหมด ยกเว้นหน้าเสี่ยง', $safe],
    ];
}

/** หน้า (path จาก root ของระบบ เช่น admin/users.php) => รหัสสิทธิ์ */
function admin_permission_pages(): array
{
    $map = [];
    foreach (admin_permission_list() as $key => [, $pages]) {
        foreach ($pages as $p) {
            $map[$p] = $key;
        }
    }
    return $map;
}

/** ฐานข้อมูลมีคอลัมน์ users.admin_permissions แล้วหรือยัง (นำเข้า update_v1.17.sql) */
function admin_perm_column_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool)db_value("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_permissions' LIMIT 1");
    }
    return $ready;
}

/** รหัสสิทธิ์ของผู้ใช้ (null = ผู้ดูแลระบบเต็มสิทธิ์ / ไม่ใช่ admin = []) */
function admin_permissions_of(array $user): ?array
{
    if (($user['role'] ?? '') !== 'admin') {
        return [];
    }
    $raw = $user['admin_permissions'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    $list = json_decode((string)$raw, true);
    return is_array($list) ? array_values(array_intersect(array_keys(admin_permission_list()), $list)) : [];
}

/** ผู้ดูแลระบบเต็มสิทธิ์ */
function is_full_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin' && admin_permissions_of($user) === null;
}

/** เจ้าหน้าที่ดูแลระบบ (role admin ที่ถูกจำกัดสิทธิ์) */
function is_limited_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin' && admin_permissions_of($user) !== null;
}

/** ผู้ใช้ (role admin) ใช้สิทธิ์นี้ได้หรือไม่ — role อื่นได้ false เสมอ */
function admin_can(array $user, string $perm): bool
{
    $perms = admin_permissions_of($user);
    return ($user['role'] ?? '') === 'admin' && ($perms === null || in_array($perm, $perms, true));
}

/** สิทธิ์ที่ใช้เปิดหน้า (path) นี้ได้ — หน้าที่ไม่มีในรายการ: แดชบอร์ด/หน้านอก admin/ = ได้, หน้าอื่นใน admin/ = ไม่ได้ */
function admin_can_path(array $user, string $path): bool
{
    if (is_full_admin($user)) {
        return true;
    }
    $map = admin_permission_pages();
    if (isset($map[$path])) {
        return admin_can($user, $map[$path]);
    }
    return $path === 'admin/index.php' || strpos($path, 'admin/') !== 0;
}

/** path ของหน้าปัจจุบันเทียบกับ root ของระบบ เช่น admin/users.php */
function current_script_path(): string
{
    return ltrim(substr(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), strlen(BASE_URL)), '/');
}

/** ตรวจสิทธิ์เจ้าหน้าที่ดูแลระบบกับหน้าปัจจุบัน (เรียกจาก require_login ทุกหน้าหลังบ้าน) */
function admin_perm_enforce(array $user): void
{
    if (!is_limited_admin($user)) {
        return;
    }
    $path = current_script_path();
    if (!admin_can_path($user, $path)) {
        log_activity('access_denied', 'เจ้าหน้าที่ดูแลระบบไม่มีสิทธิ์เปิดหน้า ' . ($_SERVER['REQUEST_URI'] ?? $path));
        $perm = admin_permission_pages()[$path] ?? null;
        abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้',
            $perm ? 'ต้องได้รับสิทธิ์ “' . admin_permission_list()[$perm][0] . '” จากผู้ดูแลระบบ' : 'หน้านี้สำหรับผู้ดูแลระบบเต็มสิทธิ์');
    }
}

/** ชื่อสิทธิ์สำหรับแสดงผล (แยกผู้ดูแลระบบเต็มสิทธิ์ / เจ้าหน้าที่ดูแลระบบ) */
function user_role_label(array $user): string
{
    return is_limited_admin($user) ? 'เจ้าหน้าที่ดูแลระบบ (กำหนดสิทธิ์)' : role_label((string)$user['role']);
}

/** จำนวนผู้ดูแลระบบเต็มสิทธิ์ที่ใช้งานได้ (ไม่นับ $excludeId) */
function full_admin_count(int $excludeId = 0): int
{
    $limited = admin_perm_column_ready() ? ' AND admin_permissions IS NULL' : '';
    return (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'$limited AND id <> ?", 'i', [$excludeId]);
}
