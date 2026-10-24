<?php
/**
 * ไฟล์: includes/partials/hr_leave_detail.php
 * รายละเอียดใบลา + ลำดับการพิจารณา (ใช้ใน hr/leaves.php และ hr/leave_review.php)
 * ตัวแปรที่ต้องมี: $leave (จาก hr_leave_fetch), $actions (จาก hr_leave_actions)
 */
$stepLabels = hr_leave_step_labels();
$decisionLabels = [
    'head'     => ['approve' => 'เห็นควรอนุญาต', 'reject' => 'ไม่ควรอนุญาต'],
    'deputy'   => ['approve' => 'เห็นควรอนุญาต', 'reject' => 'ไม่ควรอนุญาต'],
    'hr'       => ['approve' => 'ตรวจสอบแล้ว'],
    'director' => ['approve' => 'อนุญาต', 'reject' => 'ไม่อนุญาต'],
];
$leaveUsed = hr_leave_used((int)$leave['user_id'], (int)$leave['fiscal_year'], (int)$leave['id']);
?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center">
        <span><i class="bi <?= e(hr_leave_types()[$leave['leave_type']][2] ?? 'bi-calendar') ?> me-2"></i><?= e(hr_leave_type_label($leave['leave_type'])) ?></span>
        <span class="ms-auto"><?= hr_leave_status_badge($leave['status']) ?></span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">ผู้ลา</dt>
            <dd class="col-sm-8"><?= e($leave['user_name']) ?> <span class="text-muted small"><?= e($leave['position']) ?><?= $leave['department_name'] ? ' · ' . e($leave['department_name']) : '' ?></span></dd>
            <dt class="col-sm-4">วันที่ลา</dt>
            <dd class="col-sm-8"><?= e(hr_leave_range_text($leave)) ?> <span class="badge bg-primary-subtle text-primary-emphasis"><?= hr_days((float)$leave['days']) ?> วัน</span></dd>
            <dt class="col-sm-4">เหตุผล</dt><dd class="col-sm-8" style="white-space:pre-line"><?= e($leave['reason'] ?: '-') ?></dd>
            <dt class="col-sm-4">ระหว่างลาติดต่อได้ที่</dt><dd class="col-sm-8"><?= e($leave['contact'] ?: '-') ?></dd>
            <dt class="col-sm-4">ผู้ปฏิบัติงาน/สอนแทน</dt><dd class="col-sm-8"><?= e($leave['substitute'] ?: '-') ?></dd>
            <dt class="col-sm-4">ไฟล์แนบ</dt>
            <dd class="col-sm-8"><?php if ($leave['attach_stored']): ?>
                    <a href="<?= e(hr_file_url('leave', (int)$leave['id'])) ?>" target="_blank" rel="noopener"><i class="bi <?= e(file_icon($leave['attach_original'])) ?> me-1"></i><?= e($leave['attach_original']) ?></a>
                <?php else: ?>-<?php endif; ?></dd>
            <dt class="col-sm-4">ปีงบประมาณ</dt><dd class="col-sm-8"><?= (int)$leave['fiscal_year'] ?>
                <span class="small text-muted">— ลามาแล้ว (อนุญาต ก่อนใบนี้): ป่วย <?= hr_days($leaveUsed['sick']['days']) ?> · กิจ <?= hr_days($leaveUsed['personal']['days']) ?>
                    · พักผ่อน <?= hr_days($leaveUsed['vacation']['days']) ?> วัน</span></dd>
        </dl>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-ol me-2"></i>ลำดับการพิจารณา</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($actions as $a): ?>
            <li class="list-group-item">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-light text-dark border"><?= e($stepLabels[$a['step']] ?? $a['step']) ?></span>
                    <?php if ($a['decision'] !== ''): ?>
                        <span class="fw-semibold <?= $a['decision'] === 'reject' ? 'text-danger' : 'text-success' ?>">
                            <?= e($decisionLabels[$a['step']][$a['decision']] ?? $a['decision']) ?></span>
                    <?php endif; ?>
                    <span class="small text-muted ms-auto"><?= e($a['actor_name'] ?? '-') ?> · <?= e(thai_datetime($a['created_at'])) ?></span>
                </div>
                <?php if ($a['comment'] !== ''): ?><div class="small mt-1" style="white-space:pre-line"><?= e($a['comment']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if (in_array($leave['status'], hr_leave_pending_statuses(), true)): ?>
            <li class="list-group-item text-muted small"><i class="bi bi-hourglass-split me-1"></i><?= e(hr_leave_status_labels()[$leave['status']]) ?></li>
        <?php endif; ?>
    </ul>
</div>
<?php unset($stepLabels, $decisionLabels, $leaveUsed, $a);
