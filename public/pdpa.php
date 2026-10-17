<?php
/**
 * ไฟล์: public/pdpa.php
 * ข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล (PDPA) — เปิดดูได้ทุกคน ไม่ต้องล็อกอิน
 */
require_once __DIR__ . '/../config/config.php';

$activeNav = 'pdpa';
$pageTitle = 'ข้อตกลง PDPA';
require APP_ROOT . '/includes/public_header.php';
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">หน้าแรก</a></li>
            <li class="breadcrumb-item active" aria-current="page">ข้อตกลง PDPA</li>
        </ol>
    </nav>
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <article class="card">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h3 fw-bold text-primary-th mb-1">
                        <i class="bi bi-shield-check me-1"></i> ข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล (PDPA)
                    </h2>
                    <div class="text-muted small mb-4">
                        <?= e(system_name()) ?> · <?= e(college_name()) ?> · ฉบับที่ <?= e(pdpa_version()) ?>
                        · ประกาศใช้ <?= e(thai_date(pdpa_effective_date())) ?>
                    </div>

                    <?php if ($viewer && !empty($viewer['pdpa_accepted_at']) && !pdpa_needs_consent($viewer)): ?>
                        <div class="alert alert-success small">
                            <i class="bi bi-check-circle me-1"></i>
                            ท่านยอมรับข้อตกลงฉบับนี้แล้วเมื่อ <?= e(thai_datetime($viewer['pdpa_accepted_at'])) ?>
                        </div>
                    <?php elseif ($viewer && pdpa_needs_consent($viewer)): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-circle me-1"></i> ท่านยังไม่ได้ยอมรับข้อตกลงฉบับนี้
                            <a href="<?= e(url('auth/pdpa_consent.php')) ?>" class="alert-link">ยอมรับข้อตกลง</a>
                        </div>
                    <?php endif; ?>

                    <div class="pdpa-content lh-lg"><?= pdpa_render(pdpa_content()) ?></div>
                </div>
            </article>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/public_footer.php'; ?>
