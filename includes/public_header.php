<?php
/**
 * ไฟล์: includes/public_header.php
 * ส่วนหัวของหน้าบ้าน (สาธารณะ)
 * ตัวแปร: $pageTitle, $activeNav (home | announcements | calendar | downloads | pdpa)
 */
$pageTitle = $pageTitle ?? 'หน้าแรก';
$activeNav = $activeNav ?? '';
check_session_timeout();
$viewer = current_user();
$logo = logo_url();

$navItems = [
    'home'          => ['หน้าแรก', 'public/index.php', 'bi-house'],
    'announcements' => ['ประกาศข่าวสาร', 'public/announcements.php', 'bi-megaphone'],
    'calendar'      => ['ปฏิทินส่งงาน', 'public/calendar.php', 'bi-calendar-event'],
    'downloads'     => ['ดาวน์โหลดเอกสาร', 'public/downloads.php', 'bi-cloud-download'],
    'docshub'       => ['Docs Hub', 'https://krusomkhuan.com/docshub/', 'bi-journal-text'],
    'prompthub'     => ['Prompt Hub', 'https://krusomkhuan.com/prompthub/public/', 'bi-stars'],
    'pdpa'          => ['ข้อตกลง PDPA', 'public/pdpa.php', 'bi-shield-check'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e(system_name() . ' — ' . setting('system_fullname') . ' ' . college_name()) ?>">
    <title><?= e($pageTitle) ?> | <?= e(system_name()) ?> <?= e(college_name()) ?></title>
    <link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
    <style>
        :root {
            --th-primary: <?= e(theme_color('color_primary', COLOR_PRIMARY_DEFAULT)) ?>;
            --th-accent: <?= e(theme_color('color_accent', COLOR_ACCENT_DEFAULT)) ?>;
        }
    </style>
</head>
<body data-server-now="<?= time() * 1000 ?>">

<nav class="navbar navbar-expand-xl navbar-dark pub-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('public/index.php')) ?>">
            <img src="<?= e($logo) ?>" alt="โลโก้">
            <span class="lh-1">
                <span class="fw-bold d-block"><?= e(system_name()) ?></span>
                <small class="opacity-75" style="font-size:.72rem"><?= e(college_name()) ?></small>
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pubNav"
                aria-controls="pubNav" aria-expanded="false" aria-label="เปิดเมนู">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="pubNav">
            <ul class="navbar-nav ms-auto mb-2 mb-xl-0">
                <?php foreach ($navItems as $key => [$label, $path, $icon]): ?>
                    <?php $external = str_starts_with($path, 'https://'); // ลิงก์ไปเว็บอื่น เปิดแท็บใหม่ ?>
                    <li class="nav-item">
                        <a class="nav-link px-xl-3<?= $activeNav === $key ? ' active' : '' ?>" href="<?= e($external ? $path : url($path)) ?>"<?= $external ? ' target="_blank" rel="noopener"' : '' ?>>
                            <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex gap-2 ms-xl-3 pb-2 pb-xl-0">
                <?php if ($viewer): ?>
                    <a href="<?= e(url(dashboard_url($viewer['role']))) ?>" class="btn btn-accent btn-sm px-3">
                        <i class="bi bi-speedometer2 me-1"></i> เข้าสู่หน้าทำงาน
                    </a>
                <?php else: ?>
                    <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-accent btn-sm px-3"><i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ</a>
                    <?php if (setting('allow_register', '1') === '1'): ?>
                        <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-outline-light btn-sm px-3">สมัครสมาชิก</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
