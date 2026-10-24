<?php
/**
 * ไฟล์: public/index.php
 * หน้าแรก (สาธารณะ): โลโก้ ชื่อระบบ สโลแกน, สถิติภาพรวม, งานที่เปิดรับ+นับถอยหลัง, ประกาศล่าสุด, เอกสารดาวน์โหลด
 */
require_once __DIR__ . '/../config/config.php';

// ---------- สถิติภาพรวม ----------
$stats = [];
foreach (submission_types() as $t) {
    $table = submission_table($t); // ชื่อตารางจาก whitelist
    $row = db_one("SELECT COUNT(*) AS total, SUM(status = 'approved') AS approved FROM `$table`");
    $stats[$t] = ['total' => (int)$row['total'], 'approved' => (int)$row['approved']];
}
$stats['supervision'] = [
    'total'    => (int)db_value('SELECT COUNT(*) FROM supervision_results'),
    'approved' => (int)db_value('SELECT COUNT(*) FROM supervision_results WHERE acknowledged_at IS NOT NULL'),
];
$teacherCount = (int)db_value(
    "SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('teacher','supervisor')"
);

// ---------- รอบที่เปิดรับ / กำลังจะเปิด ----------
$rounds = db_all(
    "SELECT * FROM submission_rounds
     WHERE is_active = 1 AND (close_at >= NOW() OR (allow_late = 1 AND close_at >= NOW() - INTERVAL 30 DAY))
     ORDER BY (open_at > NOW()), close_at ASC
     LIMIT 6"
);

// ---------- ประกาศล่าสุด ----------
$news = db_all(
    'SELECT id, title, content, is_pinned, published_at FROM announcements
     WHERE is_published = 1 AND published_at <= NOW()
     ORDER BY is_pinned DESC, published_at DESC LIMIT 5'
);

// ---------- เอกสารดาวน์โหลดล่าสุด ----------
$docs = db_all(
    'SELECT id, title, category, original_name, file_size FROM downloads
     WHERE is_active = 1 AND stored_name IS NOT NULL ORDER BY created_at DESC LIMIT 5'
);

$logo = logo_url();
$pageTitle = 'หน้าแรก';
$activeNav = 'home';
$useChart = true;
require APP_ROOT . '/includes/public_header.php';
?>

<!-- ส่วนหัว Hero -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-md-auto text-center">
                <img src="<?= e($logo) ?>" alt="โลโก้<?= e(college_name()) ?>" class="hero-logo">
            </div>
            <div class="col-md">
                <div class="opacity-75 mb-1"><?= e(college_name()) ?></div>
                <h1 class="mb-1"><?= e(system_name()) ?></h1>
                <div class="fs-5 mb-2"><?= e(setting('system_fullname', 'ระบบการส่งงานและรายงานกิจกรรม')) ?></div>
                <div class="slogan mb-4">“<?= e(setting('slogan', 'ทุกงานวิชาการครบ จบในที่เดียว')) ?>”</div>
                <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-md-start">
                    <?php if ($viewer): ?>
                        <a href="<?= e(url(dashboard_url($viewer['role']))) ?>" class="btn btn-accent btn-lg px-4">
                            <i class="bi bi-speedometer2 me-1"></i> เข้าสู่หน้าทำงาน</a>
                    <?php else: ?>
                        <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-accent btn-lg px-4">
                            <i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ</a>
                        <?php if (setting('allow_register', '1') === '1'): ?>
                            <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-outline-light btn-lg px-4">
                                <i class="bi bi-person-plus me-1"></i> สมัครสมาชิก</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container">
    <!-- การ์ดสถิติ -->
    <div class="row g-3 hero-stats">
        <?php
        $cards = [
            'lesson_plan' => ['ic-navy', 'แผนการจัดการเรียนรู้'],
            'pa'          => ['ic-orange', 'ข้อตกลง PA'],
            'research'    => ['ic-green', 'วิจัยในชั้นเรียน'],
            'supervision' => ['ic-purple', 'นิเทศการสอน'],
        ];
        foreach ($cards as $t => [$ic, $label]): ?>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon <?= $ic ?>"><i class="bi <?= e(submission_type_icon($t)) ?>"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stats[$t]['total']) ?></div>
                        <div class="stat-label"><?= e($label) ?></div>
                        <div class="stat-sub"><?= $t === 'supervision' ? 'รับทราบผลแล้ว' : 'ผ่านแล้ว' ?> <?= number_format($stats[$t]['approved']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- งานที่เปิดรับ -->
    <div class="d-flex align-items-center mt-5 mb-3">
        <h4 class="section-title mb-0">กำหนดการส่งงาน</h4>
        <a href="<?= e(url('public/calendar.php')) ?>" class="ms-auto small">ดูปฏิทินทั้งหมด <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php if ($rounds): ?>
        <div class="row g-3">
            <?php foreach ($rounds as $r): ?>
                <div class="col-md-6 col-lg-4"><?= round_card_html($r) ?></div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card"><div class="empty-state"><i class="bi bi-calendar-x"></i>ขณะนี้ยังไม่มีรอบการส่งงานที่เปิดรับ</div></div>
    <?php endif; ?>

    <div class="row g-4 mt-2">
        <!-- ประกาศข่าวสาร -->
        <div class="col-lg-7">
            <div class="d-flex align-items-center mb-3">
                <h4 class="section-title mb-0">ประกาศข่าวสาร</h4>
                <a href="<?= e(url('public/announcements.php')) ?>" class="ms-auto small">ทั้งหมด <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="card">
                <?php if ($news): ?>
                    <?php foreach ($news as $n): ?>
                        <a class="news-item" href="<?= e(url('public/announcements.php?id=' . (int)$n['id'])) ?>">
                            <div class="news-title">
                                <?php if ($n['is_pinned']): ?><i class="bi bi-pin-angle-fill text-accent me-1"></i><?php endif; ?>
                                <?= e($n['title']) ?>
                            </div>
                            <div class="small text-muted mb-1"><i class="bi bi-clock me-1"></i><?= e(thai_date($n['published_at'], false, true)) ?></div>
                            <div class="small"><?= e(str_limit($n['content'], 140)) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-megaphone"></i>ยังไม่มีประกาศ</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- สถิติ + ดาวน์โหลด -->
        <div class="col-lg-5">
            <h4 class="section-title">สถิติงานที่ส่งแล้ว</h4>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="chart-box-sm"><canvas id="statChart" aria-label="กราฟจำนวนงานที่ส่งแยกตามประเภท"></canvas></div>
                    <div class="small text-muted text-center mt-2">ครูผู้สอนในระบบ <?= number_format($teacherCount) ?> คน</div>
                </div>
            </div>

            <?php
            // นับหลัง public_header (visit_track) เพื่อให้รวมการเข้าชมครั้งนี้ด้วย
            $visits = visit_stats();
            $onlineCount = presence_online_count();
            $onlineUsers = $viewer ? presence_online_users(30) : [];
            ?>
            <h4 class="section-title">สถิติผู้เข้าชม</h4>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-2 text-center">
                        <?php foreach (['today' => 'วันนี้', 'month' => 'เดือนนี้', 'total' => 'ทั้งหมด'] as $k => $label): ?>
                            <div class="col-4">
                                <div class="fs-4 fw-bold text-primary-th"><?= number_format($visits[$k]) ?></div>
                                <div class="small text-muted"><?= e($label) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <div class="d-flex align-items-center gap-2">
                        <span class="online-dot"></span>
                        <span>ผู้ใช้ออนไลน์ขณะนี้ <strong><?= number_format($onlineCount) ?></strong> คน</span>
                    </div>
                    <?php if ($onlineUsers): ?>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php foreach ($onlineUsers as $ou): ?>
                                <img src="<?= e(avatar_url($ou)) ?>" alt="<?= e(full_name($ou)) ?>" class="avatar-sm"
                                     title="<?= e(full_name($ou) . ($ou['department_name'] ? ' · ' . $ou['department_name'] : '')) ?>"
                                     data-bs-toggle="tooltip">
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!$viewer && $onlineCount > 0): ?>
                        <div class="small text-muted mt-1">เข้าสู่ระบบเพื่อดูรายชื่อผู้ที่ออนไลน์</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex align-items-center mb-3">
                <h4 class="section-title mb-0">ดาวน์โหลดแบบฟอร์ม</h4>
                <a href="<?= e(url('public/downloads.php')) ?>" class="ms-auto small">ทั้งหมด <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="card">
                <?php if ($docs): ?>
                    <div class="list-group list-group-flush rounded-3">
                        <?php foreach ($docs as $d): ?>
                            <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3"
                               href="<?= e(url('public/download.php?id=' . (int)$d['id'])) ?>">
                                <i class="bi <?= e(file_icon($d['original_name'])) ?> fs-3"></i>
                                <span class="flex-grow-1">
                                    <span class="fw-semibold d-block"><?= e($d['title']) ?></span>
                                    <small class="text-muted"><?= e(download_categories()[$d['category']] ?? '') ?> · <?= e(format_bytes((int)$d['file_size'])) ?></small>
                                </span>
                                <i class="bi bi-download text-accent"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-cloud-download"></i>ยังไม่มีเอกสารให้ดาวน์โหลด</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$chartData = [
    'labels'   => ['แผนการสอน', 'PA', 'วิจัยในชั้นเรียน', 'นิเทศการสอน'],
    'total'    => [$stats['lesson_plan']['total'], $stats['pa']['total'], $stats['research']['total'], $stats['supervision']['total']],
    'approved' => [$stats['lesson_plan']['approved'], $stats['pa']['approved'], $stats['research']['approved'], $stats['supervision']['approved']],
];
$pageScripts = 'document.addEventListener("DOMContentLoaded", function () {
    var d = ' . json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    new Chart(document.getElementById("statChart"), {
        type: "bar",
        data: {
            labels: d.labels,
            datasets: [
                { label: "ส่งแล้วทั้งหมด", data: d.total, backgroundColor: TeachHub.cssVar("--th-primary", "#1b2a4e"), borderRadius: 6 },
                { label: "ผ่าน/รับทราบแล้ว", data: d.approved, backgroundColor: TeachHub.cssVar("--th-accent", "#f47b20"), borderRadius: 6 }
            ]
        },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
});';
require APP_ROOT . '/includes/public_footer.php';
