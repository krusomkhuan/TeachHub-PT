<?php
/**
 * ไฟล์: includes/partials/report_alert.php
 * แจ้งเตือนบนแดชบอร์ด: รายงานการนิเทศที่รอท่านพิจารณา (ผู้พิจารณา) / ถูกส่งกลับแก้ไข (หัวหน้าแผนก)
 * ต้องมีตัวแปร $user
 */
if ($user['role'] === 'supervisor') {
    $alertN = (int)db_value(
        "SELECT COUNT(*) FROM supervision_reports WHERE department_id = ? AND status = 'returned'",
        'i',
        [(int)$user['department_id']]
    );
    if ($alertN > 0): ?>
        <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-arrow-return-left fs-5"></i>
            <span>รายงานการนิเทศของแผนกถูกส่งกลับให้แก้ไข <?= $alertN ?> ฉบับ</span>
            <a href="<?= e(url('supervisor/supervision_reports.php')) ?>" class="btn btn-sm btn-danger ms-auto">ดูและแก้ไข</a>
        </div>
    <?php endif;
} else {
    $alertN = report_pending_count((int)$user['id']);
    if ($alertN > 0): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-file-earmark-check fs-5"></i>
            <span>มีรายงานการนิเทศจากหัวหน้าแผนกรอท่านพิจารณา <strong><?= $alertN ?></strong> ฉบับ</span>
            <a href="<?= e(url('reports/supervision_reports.php?tab=mine')) ?>" class="btn btn-sm btn-warning ms-auto">พิจารณา</a>
        </div>
    <?php endif;
}
