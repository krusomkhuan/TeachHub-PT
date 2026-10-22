<?php
/**
 * ไฟล์: teacher/index.php
 * แดชบอร์ดครูผู้สอน: สรุปงานของฉัน, งานที่เปิดรับ (ใกล้ปิด = สีแดง), งานที่ต้องแก้ไข, ตารางนิเทศ, ประกาศ
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher');
// เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว: หน้าแรกคือหน้าตรวจรายงานกิจกรรมโฮมรูม
if (is_advisor_staff_only($user)) {
    redirect('review/advisor_reports.php');
}
$uid = (int)$user['id'];

// ---------- สรุปงานของฉัน (ทุกภาคเรียน) ----------
$summary = [];
foreach (submission_types() as $t) {
    $table = submission_table($t);
    $row = db_one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'pending'), 0)  AS pending,
                COALESCE(SUM(status = 'approved'), 0) AS approved,
                COALESCE(SUM(status = 'revision'), 0) AS revision
         FROM `$table` WHERE teacher_id = ?",
        'i',
        [$uid]
    );
    $summary[$t] = array_map('intval', $row);
}
$supCount = (int)db_value('SELECT COUNT(*) FROM supervision_schedules WHERE teacher_id = ? AND status <> \'cancelled\'', 'i', [$uid]);
$supAvg = db_value(
    'SELECT ROUND(AVG(res.avg_score), 2) FROM supervision_results res
     JOIN supervision_schedules sc ON sc.id = res.schedule_id WHERE sc.teacher_id = ?',
    'i',
    [$uid]
);

$stuAvg = db_value(
    'SELECT ROUND(AVG(r.avg_score), 2) FROM student_eval_responses r
     JOIN student_eval_sessions s ON s.id = r.session_id WHERE s.teacher_id = ?',
    'i',
    [$uid]
);

// ---------- งานที่ต้องแก้ไข ----------
$revisions = db_all(
    "SELECT 'lesson_plan' AS type, id, CONCAT(subject_code, ' ', subject_name) AS title, updated_at, submitted_at
       FROM lesson_plans WHERE teacher_id = ? AND status = 'revision'
     UNION ALL
     SELECT 'pa', id, CONCAT('PA ปีงบประมาณ ', fiscal_year, IF(phase = 'agreement', ' (ข้อตกลง)', ' (รายงานผล)')), updated_at, submitted_at
       FROM pa_submissions WHERE teacher_id = ? AND status = 'revision'
     UNION ALL
     SELECT 'research', id, title, updated_at, submitted_at
       FROM research_submissions WHERE teacher_id = ? AND status = 'revision'
     ORDER BY COALESCE(updated_at, submitted_at) DESC",
    'iii',
    [$uid, $uid, $uid]
);

// ---------- ตารางนิเทศที่กำลังจะถึง ----------
$schedules = db_all(
    "SELECT sc.*, CONCAT(s.prefix, s.first_name, ' ', s.last_name) AS supervisor_name
     FROM supervision_schedules sc JOIN users s ON s.id = sc.supervisor_id
     WHERE sc.teacher_id = ? AND sc.status = 'scheduled' AND sc.supervise_date >= CURDATE()
     ORDER BY sc.supervise_date, sc.start_time LIMIT 5",
    'i',
    [$uid]
);
$unack = (int)db_value(
    'SELECT COUNT(*) FROM supervision_results res JOIN supervision_schedules sc ON sc.id = res.schedule_id
     WHERE sc.teacher_id = ? AND res.acknowledged_at IS NULL',
    'i',
    [$uid]
);

// ---------- ประกาศล่าสุด ----------
$news = db_all(
    'SELECT id, title, is_pinned, published_at FROM announcements
     WHERE is_published = 1 AND published_at <= NOW() ORDER BY is_pinned DESC, published_at DESC LIMIT 4'
);

$pageTitle = 'แดชบอร์ดครูผู้สอน';
$pageIcon = 'bi-speedometer2';
require APP_ROOT . '/includes/header.php';
?>

<div class="alert alert-light border d-flex align-items-center gap-3 mb-4">
    <img src="<?= e(avatar_url($user)) ?>" class="avatar-md" alt="">
    <div>
        <div class="fw-bold fs-5">สวัสดี <?= e(full_name($user)) ?></div>
        <div class="text-muted small"><?= e($user['position']) ?><?= $user['academic_standing'] ? ' · ' . e($user['academic_standing']) : '' ?> · <?= e($user['department_name'] ?? 'ไม่ระบุแผนก') ?></div>
    </div>
</div>

<?php if ($unack > 0): ?>
    <div class="alert alert-warning d-flex align-items-center">
        <i class="bi bi-bell-fill me-2 fs-5"></i>
        มีผลการนิเทศที่ยังไม่ได้กดรับทราบ <?= $unack ?> รายการ
        <a href="<?= e(url('teacher/supervision.php')) ?>" class="btn btn-sm btn-warning ms-auto">ดูผลการนิเทศ</a>
    </div>
<?php endif; ?>

<!-- การ์ดสรุปตัวเลข -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        'lesson_plan' => ['ic-navy', 'แผนการสอนที่ส่ง', 'teacher/lesson_plans.php'],
        'pa'          => ['ic-orange', 'PA ที่ส่ง', 'teacher/pa.php'],
        'research'    => ['ic-green', 'วิจัยที่ส่ง', 'teacher/research.php'],
    ];
    foreach ($cards as $t => [$ic, $label, $link]): $s = $summary[$t]; ?>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= e(url($link)) ?>" class="stat-card">
                <div class="stat-icon <?= $ic ?>"><i class="bi <?= e(submission_type_icon($t)) ?>"></i></div>
                <div>
                    <div class="stat-value"><?= $s['total'] ?></div>
                    <div class="stat-label"><?= e($label) ?></div>
                    <div class="stat-sub">
                        <span class="text-success">ผ่าน <?= $s['approved'] ?></span> ·
                        <span class="text-warning">รอตรวจ <?= $s['pending'] ?></span> ·
                        <span class="text-danger">แก้ไข <?= $s['revision'] ?></span>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= e(url('teacher/supervision.php')) ?>" class="stat-card">
            <div class="stat-icon ic-purple"><i class="bi bi-person-video3"></i></div>
            <div>
                <div class="stat-value"><?= $supCount ?></div>
                <div class="stat-label">การนิเทศการสอน</div>
                <div class="stat-sub">หัวหน้าแผนก <?= $supAvg !== null ? e(number_format((float)$supAvg, 2)) . ' (' . e(quality_level((float)$supAvg)) . ')' : '-' ?></div>
                <div class="stat-sub">นักเรียน <?= $stuAvg !== null ? e(number_format((float)$stuAvg, 2)) . ' (' . e(quality_level((float)$stuAvg)) . ')' : '-' ?></div>
            </div>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <?php require APP_ROOT . '/includes/partials/my_rounds.php'; ?>
    </div>

    <div class="col-xl-5">
        <!-- งานที่ต้องแก้ไข -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-pencil-square me-2 text-danger"></i>งานที่ต้องแก้ไข
                <?php if ($revisions): ?><span class="badge bg-danger ms-1"><?= count($revisions) ?></span><?php endif; ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($revisions as $rv): ?>
                    <li class="list-group-item d-flex align-items-center gap-2">
                        <i class="bi <?= e(submission_type_icon($rv['type'])) ?> text-danger"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="text-truncate fw-semibold"><?= e($rv['title']) ?></div>
                            <small class="text-muted"><?= e(submission_type_label($rv['type'])) ?></small>
                        </div>
                        <a href="<?= e(url(teacher_submit_page($rv['type']) . '?id=' . (int)$rv['id'])) ?>" class="btn btn-sm btn-outline-danger">แก้ไข</a>
                    </li>
                <?php endforeach; ?>
                <?php if (!$revisions): ?>
                    <li class="list-group-item empty-state"><i class="bi bi-emoji-smile"></i>ไม่มีงานที่ต้องแก้ไข</li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- ตารางนิเทศ -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-calendar-week me-2"></i>ตารางนิเทศที่กำลังจะถึง</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($schedules as $sc): ?>
                    <li class="list-group-item">
                        <div class="fw-semibold"><i class="bi bi-calendar-event text-accent me-1"></i>
                            <?= e(thai_date($sc['supervise_date'], false, true)) ?> เวลา <?= e(substr($sc['start_time'], 0, 5)) ?>–<?= e(substr($sc['end_time'], 0, 5)) ?> น.</div>
                        <div class="small text-muted"><?= e($sc['subject_name']) ?> · <?= e($sc['classroom']) ?></div>
                        <div class="small">ผู้นิเทศ: <?= e($sc['supervisor_name']) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$schedules): ?>
                    <li class="list-group-item empty-state">ยังไม่มีตารางนิเทศ</li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- ประกาศ -->
        <div class="card">
            <div class="card-header"><i class="bi bi-megaphone me-2"></i>ประกาศล่าสุด</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($news as $n): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('public/announcements.php?id=' . (int)$n['id'])) ?>">
                        <?php if ($n['is_pinned']): ?><i class="bi bi-pin-angle-fill text-accent me-1"></i><?php endif; ?>
                        <?= e($n['title']) ?>
                        <div class="small text-muted"><?= e(thai_date($n['published_at'])) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$news): ?><li class="list-group-item empty-state">ยังไม่มีประกาศ</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
