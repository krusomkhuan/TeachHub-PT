<?php
/**
 * ไฟล์: includes/sidebar.php
 * เมนูด้านข้างแยกตาม role (ถูก include จาก header.php)
 * - หน้าไหนยังไม่ได้สร้างไฟล์ (โมดูลระยะถัดไป) จะแสดงป้าย "เร็ว ๆ นี้" อัตโนมัติ
 * - บนมือถือแสดงเป็น Offcanvas
 */

// ตัวเลขแจ้งเตือนบนเมนู
$badgeCounts = [];
if ($user['role'] === 'admin') {
    $badgeCounts['admin/users.php'] = (int)db_value("SELECT COUNT(*) FROM users WHERE status = 'pending'")
        + (int)db_value("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'");
}
if (in_array($user['role'], ['admin', 'executive'], true)) {
    // รายงานการนิเทศที่รอผู้ใช้คนนี้พิจารณา
    $badgeCounts['reports/supervision_reports.php'] = report_pending_count((int)$user['id']);
}
if ($user['role'] === 'supervisor') {
    // รายงานของแผนกที่ถูกส่งกลับให้แก้ไข
    $badgeCounts['supervisor/supervision_reports.php'] = (int)db_value(
        "SELECT COUNT(*) FROM supervision_reports WHERE department_id = ? AND status = 'returned'",
        'i',
        [(int)$user['department_id']]
    );
}

/**
 * โครงสร้างเมนู: [หัวข้อกลุ่ม => [[ชื่อเมนู, ไฟล์, ไอคอน], ...]]
 */
$menus = [
    'admin' => [
        '' => [
            ['แดชบอร์ด', 'admin/index.php', 'bi-speedometer2'],
        ],
        'ตรวจงานที่ส่ง' => [
            ['โครงการสอน/แผนการสอน', 'admin/lesson_plans.php', 'bi-journal-text'],
            ['ข้อตกลง PA', 'admin/pa.php', 'bi-clipboard-check'],
            ['วิจัยในชั้นเรียน', 'admin/research.php', 'bi-search'],
        ],
        'นิเทศการสอน' => [
            ['1. โดยหัวหน้าแผนก', 'admin/supervision.php', 'bi-calendar-week'],
            ['2. โดยนักเรียน (QR Code)', 'admin/student_eval.php', 'bi-qr-code'],
            ['หัวข้อแบบประเมิน', 'admin/supervision_criteria.php', 'bi-list-check'],
            ['รายงานการนิเทศ (เสนอ/พิจารณา)', 'reports/supervision_reports.php', 'bi-file-earmark-check'],
        ],
        'กำหนดการ & รายงาน' => [
            ['รอบการส่งงาน', 'admin/rounds.php', 'bi-hourglass-split'],
            ['รายงานผล', 'reports/index.php', 'bi-bar-chart-line'],
            ['เกณฑ์การส่งรายภาคเรียน', 'reports/teaching_compliance.php', 'bi-clipboard2-check'],
        ],
        'ข้อมูลพื้นฐาน' => [
            ['จัดการผู้ใช้', 'admin/users.php', 'bi-people'],
            ['แผนกวิชา', 'admin/departments.php', 'bi-diagram-3'],
            ['ปีการศึกษา/ภาคเรียน', 'admin/academic_years.php', 'bi-calendar3'],
            ['รายวิชา', 'admin/subjects.php', 'bi-book'],
        ],
        'เนื้อหาเว็บไซต์' => [
            ['ประกาศข่าว', 'admin/announcements.php', 'bi-megaphone'],
            ['เอกสารดาวน์โหลด', 'admin/downloads.php', 'bi-cloud-download'],
            ['ข้อตกลง PDPA', 'admin/pdpa.php', 'bi-shield-check'],
        ],
        'ระบบ' => [
            ['ตั้งค่าระบบ', 'admin/settings.php', 'bi-gear'],
            ['สำรอง / กู้คืนข้อมูล', 'admin/backup.php', 'bi-database-gear'],
            ['บันทึกการใช้งาน', 'admin/logs.php', 'bi-clock-history'],
            ['ตรวจสอบระบบ', 'admin/system_check.php', 'bi-heart-pulse'],
        ],
    ],
    'supervisor' => [
        '' => [
            ['แดชบอร์ด', 'supervisor/index.php', 'bi-speedometer2'],
        ],
        'ตรวจงานในแผนก' => [
            ['โครงการสอน/แผนการสอน', 'supervisor/lesson_plans.php', 'bi-journal-text'],
            ['ข้อตกลง PA', 'supervisor/pa.php', 'bi-clipboard-check'],
            ['วิจัยในชั้นเรียน', 'supervisor/research.php', 'bi-search'],
        ],
        'นิเทศการสอน' => [
            ['1. นิเทศโดยหัวหน้าแผนก', 'supervisor/supervision.php', 'bi-person-video3'],
            ['2. ผลประเมินโดยนักเรียน', 'supervisor/student_eval.php', 'bi-people'],
            ['รายงานการนิเทศ (เสนอผู้บริหาร)', 'supervisor/supervision_reports.php', 'bi-file-earmark-text'],
        ],
        'งานของฉัน' => [
            ['รายวิชาที่สอน', 'teacher/teaching_subjects.php', 'bi-book'],
            ['ส่งโครงการสอน/แผนการสอน', 'teacher/lesson_plans.php', 'bi-upload'],
            ['ส่ง PA', 'teacher/pa.php', 'bi-upload'],
            ['ส่งวิจัย', 'teacher/research.php', 'bi-upload'],
            ['ผลการนิเทศของฉัน', 'teacher/supervision.php', 'bi-person-badge'],
        ],
        'รายงาน' => [
            ['รายงานแผนก', 'reports/index.php', 'bi-bar-chart-line'],
            ['เกณฑ์การส่งรายภาคเรียน', 'reports/teaching_compliance.php', 'bi-clipboard2-check'],
        ],
    ],
    'teacher' => [
        '' => [
            ['แดชบอร์ด', 'teacher/index.php', 'bi-speedometer2'],
        ],
        'ส่งงาน' => [
            ['รายวิชาที่สอน', 'teacher/teaching_subjects.php', 'bi-book'],
            ['โครงการสอน/แผนการจัดการเรียนรู้', 'teacher/lesson_plans.php', 'bi-journal-text'],
            ['ข้อตกลง PA', 'teacher/pa.php', 'bi-clipboard-check'],
            ['วิจัยในชั้นเรียน', 'teacher/research.php', 'bi-search'],
        ],
        'นิเทศการสอน' => [
            ['ผลการนิเทศของฉัน', 'teacher/supervision.php', 'bi-person-video3'],
        ],
        'ข้อมูลทั่วไป' => [
            ['ปฏิทินส่งงาน', 'public/calendar.php', 'bi-calendar-event'],
            ['ดาวน์โหลดแบบฟอร์ม', 'public/downloads.php', 'bi-cloud-download'],
        ],
    ],
    'executive' => [
        '' => [
            ['แดชบอร์ดผู้บริหาร', 'executive/index.php', 'bi-speedometer2'],
        ],
        'รายงาน' => [
            ['รายงานการนิเทศ (พิจารณา)', 'reports/supervision_reports.php', 'bi-file-earmark-check'],
            ['รายงานผลแยกงาน', 'reports/index.php', 'bi-bar-chart-line'],
            ['เกณฑ์การส่งรายภาคเรียน', 'reports/teaching_compliance.php', 'bi-clipboard2-check'],
        ],
        'ข้อมูลทั่วไป' => [
            ['ปฏิทินส่งงาน', 'public/calendar.php', 'bi-calendar-event'],
            ['ประกาศข่าวสาร', 'public/announcements.php', 'bi-megaphone'],
        ],
    ],
];

$roleMenu = $menus[$user['role']] ?? [];

// ครูที่เป็นผู้ตรวจตามตำแหน่ง: เพิ่มเมนูตรวจงานทุกแผนก (+ จำนวนรอตรวจ)
if ($user['role'] === 'teacher' && ($reviewTypes = designated_review_types($user))) {
    $reviewMenu = [];
    $reviewLabels = ['lesson_plan' => 'โครงการสอน/แผนการสอน', 'pa' => 'ข้อตกลง PA', 'research' => 'วิจัยในชั้นเรียน'];
    foreach ($reviewTypes as $rt) {
        $path = review_page('teacher', $rt);
        $reviewMenu[] = [$reviewLabels[$rt], $path, submission_type_icon($rt)];
        $badgeCounts[$path] = (int)db_value(
            'SELECT COUNT(*) FROM `' . submission_table($rt) . "` WHERE status = 'pending' AND teacher_id <> ?",
            'i',
            [(int)$user['id']]
        );
    }
    $roleMenu = array_slice($roleMenu, 0, 1, true) + ['ตรวจงาน (ตามตำแหน่ง)' => $reviewMenu] + array_slice($roleMenu, 1, null, true);
}
// path ของหน้าปัจจุบันเทียบกับ root ของระบบ เช่น admin/users.php
$currentPath = ltrim(substr(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), strlen(BASE_URL)), '/');
$activeMenu = $activeMenu ?? $currentPath; // หน้าย่อยกำหนด $activeMenu เพื่อไฮไลต์เมนูแม่ได้
?>
<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-label="เมนูหลัก">
    <div class="sidebar-brand">
        <img src="<?= e($logo) ?>" alt="โลโก้" class="sidebar-logo">
        <div class="lh-sm">
            <div class="sidebar-title"><?= e(system_name()) ?></div>
            <small class="sidebar-sub"><?= e(role_label($user['role'])) ?></small>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto d-lg-none" data-bs-dismiss="offcanvas"
                data-bs-target="#appSidebar" aria-label="ปิดเมนู"></button>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($roleMenu as $group => $items): ?>
            <?php if ($group !== ''): ?>
                <div class="sidebar-heading"><?= e($group) ?></div>
            <?php endif; ?>
            <?php foreach ($items as [$label, $path, $icon]):
                $exists = is_file(APP_ROOT . '/' . $path);
                $active = ($activeMenu === $path);
                $count = $badgeCounts[$path] ?? 0;
                ?>
                <?php if ($exists): ?>
                    <a href="<?= e(url($path)) ?>" class="sidebar-link<?= $active ? ' active' : '' ?>">
                        <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                        <?php if ($count > 0): ?><span class="badge rounded-pill bg-danger ms-auto"><?= $count ?></span><?php endif; ?>
                    </a>
                <?php else: ?>
                    <span class="sidebar-link disabled" title="จะเปิดใช้งานในระยะถัดไป">
                        <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                        <span class="badge rounded-pill bg-secondary bg-opacity-50 ms-auto fw-normal">เร็ว ๆ นี้</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="sidebar-heading">ติดต่อประสานงาน</div>
        <?php $boardOpen = $user['role'] === 'admin' ? board_open_count() : 0; ?>
        <a href="<?= e(url('board/index.php')) ?>" class="sidebar-link<?= $activeMenu === 'board/index.php' ? ' active' : '' ?>">
            <i class="bi bi-chat-square-text"></i><span>กระดานข่าว</span>
            <?php if ($boardOpen > 0): ?><span class="badge rounded-pill bg-danger ms-auto" title="กระทู้รอตอบ"><?= $boardOpen ?></span><?php endif; ?>
        </a>

        <div class="sidebar-heading">บัญชีผู้ใช้</div>
        <a href="<?= e(url('auth/profile.php')) ?>" class="sidebar-link<?= $activeMenu === 'auth/profile.php' ? ' active' : '' ?>">
            <i class="bi bi-person-circle"></i><span>โปรไฟล์ของฉัน</span>
        </a>
        <a href="<?= e(url('public/pdpa.php')) ?>" class="sidebar-link">
            <i class="bi bi-shield-lock"></i><span>ข้อตกลง PDPA</span>
        </a>
        <a href="<?= e(url('auth/logout.php?_csrf=' . csrf_token())) ?>" class="sidebar-link"
           data-confirm-link="ต้องการออกจากระบบใช่หรือไม่?" data-confirm-title="ออกจากระบบ">
            <i class="bi bi-box-arrow-right"></i><span>ออกจากระบบ</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <?= e(college_name()) ?><br>
        <span class="opacity-75">v<?= e(APP_VERSION) ?> · <?= e(APP_DEVELOPER) ?></span>
    </div>
</aside>
<?php
// ล้างตัวแปรภายในของเมนู ไม่ให้ไปทับตัวแปรชื่อเดียวกันของหน้าที่เรียกใช้
unset($boardOpen, $reviewTypes, $reviewMenu, $reviewLabels, $rt, $badgeCounts, $menus, $roleMenu, $currentPath, $group, $items, $label, $path, $icon, $exists, $active, $count);
