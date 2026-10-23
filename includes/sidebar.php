<?php
/**
 * ไฟล์: includes/sidebar.php
 * เมนูด้านข้างแยกตาม role (ถูก include จาก header.php)
 * - หน้าไหนยังไม่ได้สร้างไฟล์ (โมดูลระยะถัดไป) จะแสดงป้าย "เร็ว ๆ นี้" อัตโนมัติ
 * - บนมือถือแสดงเป็น Offcanvas
 */

// ตัวเลขแจ้งเตือนบนเมนู
$badgeCounts = [];
if (admin_can($user, 'users')) {
    $badgeCounts['admin/users.php'] = (int)db_value("SELECT COUNT(*) FROM users WHERE status = 'pending'")
        + (int)db_value("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'");
}
if (admin_can($user, 'supervision_reports') || $user['role'] === 'executive') {
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
            ['ประเมินแผนการจัดการเรียนรู้', 'review/plan_evaluations.php', 'bi-clipboard-data'],
            ['รายการแบบประเมินแผนฯ', 'admin/plan_eval_criteria.php', 'bi-list-check'],
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
            ['รายงานผลประเมินแผนฯ', 'reports/plan_eval_summary.php', 'bi-graph-up'],
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
            ['ประเมินแผนการจัดการเรียนรู้', 'review/plan_evaluations.php', 'bi-clipboard-data'],
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
            ['ผลประเมินแผนของฉัน', 'teacher/plan_evaluations.php', 'bi-clipboard-data'],
        ],
        'รายงาน' => [
            ['รายงานแผนก', 'reports/index.php', 'bi-bar-chart-line'],
            ['เกณฑ์การส่งรายภาคเรียน', 'reports/teaching_compliance.php', 'bi-clipboard2-check'],
            ['รายงานผลประเมินแผนฯ (แผนก)', 'reports/plan_eval_summary.php', 'bi-graph-up'],
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
            ['ผลประเมินแผนของฉัน', 'teacher/plan_evaluations.php', 'bi-clipboard-data'],
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
            ['รายงานผลประเมินแผนฯ', 'reports/plan_eval_summary.php', 'bi-graph-up'],
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
    // หัวหน้างานพัฒนาหลักสูตรฯ ประเมินแผนได้ทุกแผนก / ผู้ตรวจแผนการสอนตามตำแหน่งดูรายงานผลประเมินแผนได้
    if (is_curriculum_head($user)) {
        $reviewMenu[] = ['ประเมินแผนการจัดการเรียนรู้', 'review/plan_evaluations.php', 'bi-clipboard-data'];
    }
    if (in_array('lesson_plan', $reviewTypes, true)) {
        $reviewMenu[] = ['รายงานผลประเมินแผนฯ', 'reports/plan_eval_summary.php', 'bi-graph-up'];
    }
    $roleMenu = array_slice($roleMenu, 0, 1, true) + ['ตรวจงาน (ตามตำแหน่ง)' => $reviewMenu] + array_slice($roleMenu, 1, null, true);
}
// ประเมินแผนการจัดการเรียนรู้: จำนวนแผนที่รอประเมิน (ภาคเรียนปัจจุบัน) / ผลประเมินที่ครูยังไม่รับทราบ
if (is_plan_evaluator($user)) {
    $badgeCounts['review/plan_evaluations.php'] = plan_eval_pending_count($user);
}
if (in_array($user['role'], teacher_roles(), true)) {
    $badgeCounts['teacher/plan_evaluations.php'] = plan_eval_unack_count((int)$user['id']);
}
// รายงานครูที่ปรึกษา: ครูทุกคนเห็นเมนูรายงาน (ครูที่ยังไม่มีกลุ่มที่ปรึกษา หน้ารายงานจะแจ้งให้ติดต่องานครูที่ปรึกษาฯ)
// / ผู้ตรวจ (admin + งานครูที่ปรึกษาฯ ตามตำแหน่ง) / ผู้ดูสรุป
if (in_array($user['role'], teacher_roles(), true)) {
    $advisorMenu = [['รายงานกิจกรรมโฮมรูม', 'teacher/advisor_reports.php', 'bi-people'], ['รายงานการเยี่ยมบ้าน', 'teacher/home_visits.php', 'bi-house-heart']];
    $badgeCounts['teacher/home_visits.php'] = (int)db_value(
        "SELECT COUNT(*) FROM home_visits WHERE teacher_id = ? AND status = 'revision'",
        'i',
        [(int)$user['id']]
    );
    $badgeCounts['teacher/advisor_reports.php'] = (int)db_value(
        "SELECT COUNT(*) FROM advisor_reports WHERE teacher_id = ? AND status = 'revision'",
        'i',
        [(int)$user['id']]
    );
    $roleMenu = array_slice($roleMenu, 0, 2, true) + ['ครูที่ปรึกษา' => $advisorMenu] + array_slice($roleMenu, 2, null, true);
}
if (can_manage_advisor($user)) {
    $badgeCounts['review/advisor_reports.php'] = advisor_pending_count($user['role'] === 'admin' ? 0 : (int)$user['id']);
    $badgeCounts['review/home_visits.php'] = home_visit_pending_count($user['role'] === 'admin' ? 0 : (int)$user['id']);
    $advisorMenu = [$user['role'] === 'admin' ? 'งานครูที่ปรึกษา' : 'งานครูที่ปรึกษา (ตามตำแหน่ง)' => [
        ['รายงานกิจกรรมโฮมรูม', 'review/advisor_reports.php', 'bi-clipboard-check'],
        ['กลุ่มที่ปรึกษา / วันงดพบ', 'review/advisor_groups.php', 'bi-diagram-3'],
        ['สรุปรายงานกิจกรรมโฮมรูม', 'reports/advisor_summary.php', 'bi-bar-chart-line'],
        ['รายงานการเยี่ยมบ้าน', 'review/home_visits.php', 'bi-house-check'],
        ['สรุปรายงานการเยี่ยมบ้าน', 'reports/home_visit_summary.php', 'bi-house-heart'],
    ]];
    // หัวหน้างาน/เจ้าหน้าที่ที่เป็นครูที่ปรึกษาด้วย: วางเมนูส่งรายงานของตนเองต่อจากเมนูงานครูที่ปรึกษา
    // และตั้งชื่อกลุ่มให้ต่างจากเมนูตรวจรายงาน (ชื่อเมนูซ้ำกัน)
    if (isset($roleMenu['ครูที่ปรึกษา'])) {
        $advisorMenu['ครูที่ปรึกษา (รายงานของฉัน)'] = $roleMenu['ครูที่ปรึกษา'];
        unset($roleMenu['ครูที่ปรึกษา']);
    }
    // วางต่อจากกลุ่ม "นิเทศการสอน" (admin) หรือต่อจากแดชบอร์ด
    $advisorPos = array_search('นิเทศการสอน', array_keys($roleMenu), true);
    $advisorPos = ($user['role'] === 'admin' && $advisorPos !== false) ? $advisorPos + 1 : 1;
    $roleMenu = array_slice($roleMenu, 0, $advisorPos, true) + $advisorMenu + array_slice($roleMenu, $advisorPos, null, true);
} elseif (in_array($user['role'], ['executive', 'supervisor'], true)) {
    $roleMenu['รายงาน'][] = ['สรุปรายงานกิจกรรมโฮมรูม', 'reports/advisor_summary.php', 'bi-people'];
    $roleMenu['รายงาน'][] = ['สรุปรายงานการเยี่ยมบ้าน', 'reports/home_visit_summary.php', 'bi-house-heart'];
}
// เจ้าหน้าที่ดูแลระบบ: เห็นเฉพาะเมนูที่ผู้ดูแลระบบกำหนดสิทธิ์ให้ (หน้าเองก็ตรวจสิทธิ์ซ้ำที่ require_login)
if (is_limited_admin($user)) {
    foreach ($roleMenu as $group => $items) {
        $roleMenu[$group] = array_values(array_filter($items, fn($it) => admin_can_path($user, $it[1])));
        if (!$roleMenu[$group]) {
            unset($roleMenu[$group]);
        }
    }
}
// เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว: เห็นเฉพาะเมนูงานครูที่ปรึกษา (+ เมนูส่งรายงานของตนเองถ้าเป็นครูที่ปรึกษาด้วย)
if (is_advisor_staff_only($user)) {
    $staffMenu = ['งานครูที่ปรึกษา' => $roleMenu['งานครูที่ปรึกษา (ตามตำแหน่ง)'] ?? []];
    if (is_advisor_teacher((int)$user['id']) && isset($roleMenu['ครูที่ปรึกษา (รายงานของฉัน)'])) {
        $staffMenu['ครูที่ปรึกษา (รายงานของฉัน)'] = $roleMenu['ครูที่ปรึกษา (รายงานของฉัน)'];
    }
    $roleMenu = $staffMenu;
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
            <small class="sidebar-sub"><?= e(user_role_label($user)) ?></small>
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
        <?php $boardOpen = board_can_moderate($user) ? board_open_count() : 0; ?>
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
unset($boardOpen, $advisorMenu, $advisorPos, $reviewTypes, $reviewMenu, $reviewLabels, $rt, $badgeCounts, $menus, $roleMenu, $currentPath, $group, $items, $label, $path, $icon, $exists, $active, $count);
