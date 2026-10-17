<?php
/**
 * ไฟล์: includes/header.php
 * ส่วนหัวของหน้าหลังบ้าน (ทุก role) — รวม Sidebar และแถบด้านบน
 *
 * ตัวแปรที่หน้าเรียกใช้ต้องเตรียม:
 *   $user        = require_role(...) หรือ require_login()
 *   $pageTitle   = ชื่อหน้า
 * ตัวแปรเสริม:
 *   $pageIcon    = ไอคอน Bootstrap Icons เช่น 'bi-speedometer2'
 *   $breadcrumbs = ['ชื่อ' => 'ลิงก์', 'หน้าปัจจุบัน' => null]
 *   $pageActions = HTML ปุ่มด้านขวาของหัวเรื่อง
 */
if (!isset($user) || !is_array($user)) {
    $user = require_login();
}
$pageTitle = $pageTitle ?? 'แดชบอร์ด';
$pageIcon = $pageIcon ?? 'bi-grid';
$breadcrumbs = $breadcrumbs ?? [];
$pageActions = $pageActions ?? '';
$logo = logo_url();
$headerAy = current_academic_year();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> | <?= e(system_name()) ?></title>
    <link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
    <style>
        :root {
            --th-primary: <?= e(theme_color('color_primary', COLOR_PRIMARY_DEFAULT)) ?>;
            --th-accent: <?= e(theme_color('color_accent', COLOR_ACCENT_DEFAULT)) ?>;
        }
    </style>
</head>
<body class="app-body" data-server-now="<?= time() * 1000 ?>">

<div class="app-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <!-- แถบด้านบน -->
        <header class="app-topbar">
            <button class="btn btn-link text-dark d-lg-none me-2 p-0 fs-3" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-label="เปิดเมนู">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-title d-none d-md-block">
                <span class="text-muted small"><?= e(college_name()) ?></span>
            </div>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="badge rounded-pill text-bg-light border d-none d-sm-inline">
                    <i class="bi bi-calendar3 me-1"></i> ภาคเรียน <?= e(academic_label($headerAy)) ?>
                </span>
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle text-dark"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?= e(avatar_url($user)) ?>" alt="" class="avatar-sm me-2">
                        <span class="d-none d-md-inline lh-sm">
                            <span class="fw-semibold d-block"><?= e(full_name($user)) ?></span>
                            <small class="text-muted"><?= e(role_label($user['role'])) ?></small>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><h6 class="dropdown-header"><?= e($user['username']) ?></h6></li>
                        <li><a class="dropdown-item" href="<?= e(url('auth/profile.php')) ?>"><i class="bi bi-person-circle me-2"></i>โปรไฟล์ของฉัน</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('auth/change_password.php')) ?>"><i class="bi bi-key me-2"></i>เปลี่ยนรหัสผ่าน</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('public/index.php')) ?>"><i class="bi bi-house me-2"></i>หน้าแรกเว็บไซต์</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= e(url('auth/logout.php?_csrf=' . csrf_token())) ?>"
                               data-confirm-link="ต้องการออกจากระบบใช่หรือไม่?" data-confirm-title="ออกจากระบบ">
                                <i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- เนื้อหาหลัก -->
        <main class="app-content">
            <div class="page-head d-flex flex-wrap align-items-center gap-2 mb-4">
                <div>
                    <h1 class="page-title"><i class="bi <?= e($pageIcon) ?> me-2"></i><?= e($pageTitle) ?></h1>
                    <?php if ($breadcrumbs): ?>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb small mb-0">
                                <li class="breadcrumb-item"><a href="<?= e(url(dashboard_url($user['role']))) ?>">หน้าหลัก</a></li>
                                <?php foreach ($breadcrumbs as $label => $link): ?>
                                    <?php if ($link): ?>
                                        <li class="breadcrumb-item"><a href="<?= e(url($link)) ?>"><?= e($label) ?></a></li>
                                    <?php else: ?>
                                        <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    <?php endif; ?>
                </div>
                <?php if ($pageActions): ?>
                    <div class="ms-auto d-flex flex-wrap gap-2"><?= $pageActions /* HTML ที่หน้าสร้างเองและ escape แล้ว */ ?></div>
                <?php endif; ?>
            </div>
