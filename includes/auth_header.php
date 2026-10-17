<?php
/**
 * ไฟล์: includes/auth_header.php
 * ส่วนหัวของหน้ากลุ่มยืนยันตัวตน (login / register / forgot / change password)
 * ตัวแปรที่ใช้: $pageTitle (ชื่อหน้า), $authWide (true = กล่องฟอร์มกว้าง ใช้กับหน้าสมัครสมาชิก)
 */
$pageTitle = $pageTitle ?? 'เข้าสู่ระบบ';
$authWide = $authWide ?? false;
$logo = logo_url();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(system_name()) ?></title>
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
<body class="auth-body">
<div class="auth-wrapper">
    <!-- ฝั่งซ้าย: แบรนด์ของระบบ -->
    <aside class="auth-brand">
        <a href="<?= e(url('public/index.php')) ?>" class="auth-brand-inner text-decoration-none">
            <img src="<?= e($logo) ?>" alt="โลโก้วิทยาลัย" class="auth-logo">
            <div class="auth-college"><?= e(college_name()) ?></div>
            <h1 class="auth-system"><?= e(system_name()) ?></h1>
            <div class="auth-fullname"><?= e(setting('system_fullname', 'ระบบงานพัฒนาหลักสูตรและการจัดการเรียนรู้')) ?></div>
            <div class="auth-slogan">“<?= e(setting('slogan', 'ทุกงานวิชาการครบ จบในที่เดียว')) ?>”</div>
        </a>
        <ul class="auth-features d-none d-lg-block">
            <li><i class="bi bi-journal-text"></i> ส่งโครงการสอน / แผนการจัดการเรียนรู้</li>
            <li><i class="bi bi-clipboard-check"></i> ข้อตกลงในการพัฒนางาน (PA)</li>
            <li><i class="bi bi-search"></i> วิจัยในชั้นเรียน</li>
            <li><i class="bi bi-person-video3"></i> นิเทศการสอนออนไลน์</li>
        </ul>
    </aside>

    <!-- ฝั่งขวา: ฟอร์ม -->
    <main class="auth-main">
        <div class="auth-card <?= $authWide ? 'auth-card-wide' : '' ?>">
            <?php foreach (flash_get() as $f):
                $cls = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'][$f['type']] ?? 'info'; ?>
                <div class="alert alert-<?= $cls ?> alert-dismissible fade show" role="alert">
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
                </div>
            <?php endforeach; ?>
