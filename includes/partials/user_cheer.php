<?php
/**
 * ไฟล์: includes/partials/user_cheer.php
 * การ์ดข้อความชื่นชมผู้ใช้งานสม่ำเสมอ (เห็นเฉพาะเจ้าของ) — ใช้ในแดชบอร์ดทุก role
 * ต้องมี $user ; ถึงเกณฑ์ใหม่วันนี้จะแจ้ง toast ครั้งเดียว (ผ่าน flash ที่ footer แสดง)
 */
$cheer = user_cheer($user);
if ($cheer):
    if ($cheer['milestone'] !== null) {
        flash('success', $cheer['milestone']);
    }
?>
<div class="card cheer-card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <i class="bi <?= e($cheer['icon']) ?> cheer-icon"></i>
        <div class="flex-grow-1">
            <div class="fw-bold"><?= e($cheer['level']) ?></div>
            <div class="small"><?= e($cheer['message']) ?></div>
        </div>
        <div class="d-flex gap-3 text-center small">
            <div><div class="fs-5 fw-bold text-accent"><?= (int)$cheer['streak'] ?></div>วันต่อเนื่อง</div>
            <div><div class="fs-5 fw-bold text-primary-th"><?= (int)$cheer['month_days'] ?></div>วันในเดือนนี้</div>
            <div><div class="fs-5 fw-bold text-primary-th"><?= (int)$cheer['total_days'] ?></div>วันทั้งหมด</div>
        </div>
    </div>
</div>
<?php endif; ?>
