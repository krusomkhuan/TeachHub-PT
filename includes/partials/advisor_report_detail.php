<?php
/**
 * ไฟล์: includes/partials/advisor_report_detail.php
 * แสดงรายละเอียดรายงานการพบนักเรียนของครูที่ปรึกษา + ภาพถ่าย (ใช้ทั้งหน้าครู ผู้ตรวจ และรายงานสรุป)
 * ต้องมีตัวแปร: $rep (จาก advisor_report_fetch)
 */
$repImages = advisor_report_images((int)$rep['id']);
$repAttend = (int)$rep['present_count'] + (int)$rep['late_count'];
?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-people"></i>
        <span>รายงานการพบนักเรียน <?= e($rep['group_name']) ?> · <?= e(advisor_date_label($rep['meet_date'])) ?></span>
        <span class="ms-auto"><?= status_badge($rep['status'], (bool)$rep['is_late']) ?></span>
    </div>
    <div class="card-body">
        <dl class="row mb-3">
            <dt class="col-sm-4 text-muted fw-normal small">ครูที่ปรึกษา</dt>
            <dd class="col-sm-8"><?= e($rep['teacher_name']) ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">กลุ่มเรียน / แผนก</dt>
            <dd class="col-sm-8"><?= e($rep['group_name']) ?> · <?= e($rep['department_name'] ?? '-') ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">วันที่พบนักเรียน</dt>
            <dd class="col-sm-8"><?= e(thai_date($rep['meet_date'], false, true)) ?> (ภาคเรียน <?= e($rep['ay_semester'] . '/' . $rep['ay_year']) ?>)</dd>
            <dt class="col-sm-4 text-muted fw-normal small">ส่งเมื่อ</dt>
            <dd class="col-sm-8"><?= e(thai_datetime($rep['submitted_at'])) ?>
                <?php if ($rep['updated_at']): ?><span class="text-muted small">· แก้ไขล่าสุด <?= e(thai_datetime($rep['updated_at'])) ?></span><?php endif; ?></dd>
        </dl>

        <div class="row g-2 text-center mb-3">
            <?php foreach ([['ทั้งหมด', 'total_students', 'text-primary-th'], ['มา', 'present_count', 'text-success'], ['มาสาย', 'late_count', 'text-warning'],
                            ['ลา', 'leave_count', 'text-info'], ['ขาด', 'absent_count', 'text-danger']] as [$label, $col, $cls]): ?>
                <div class="col">
                    <div class="border rounded py-2">
                        <div class="fs-4 fw-bold <?= $cls ?>"><?= (int)$rep[$col] ?></div>
                        <div class="small text-muted"><?= e($label) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mb-3">อัตราการเข้าพบ (มา + มาสาย) <strong><?= percent($repAttend, (int)$rep['total_students']) ?>%</strong></div>

        <div class="text-muted small">เรื่องที่พูดคุย / แจ้งนักเรียน</div>
        <div class="border rounded p-2 bg-light mb-3"><?= nl2br(e((string)$rep['topics'])) ?></div>
        <div class="text-muted small">นักเรียนที่มีปัญหาและการช่วยเหลือ</div>
        <div class="border rounded p-2 bg-light"><?= nl2br(e((string)$rep['problems'])) ?: '<span class="text-muted">- ไม่มี -</span>' ?></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-images me-2"></i>ภาพถ่ายการพบนักเรียน (<?= count($repImages) ?> ภาพ)</div>
    <div class="card-body">
        <?php if ($repImages): ?>
            <div class="row g-2">
                <?php foreach ($repImages as $im): ?>
                    <div class="col-6 col-md-4">
                        <a href="<?= e(url('advisor_image.php?id=' . (int)$im['id'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url('advisor_image.php?id=' . (int)$im['id'])) ?>" class="img-fluid rounded border" style="aspect-ratio:4/3;object-fit:cover;width:100%" alt="ภาพการพบนักเรียน">
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-muted small">ไม่มีภาพถ่าย</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($rep['reviewed_at']): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-chat-left-text me-2"></i>ผลการตรวจล่าสุด</div>
        <div class="card-body small">
            <div class="mb-1"><?= status_badge($rep['status'] === 'pending' ? 'revision' : $rep['status']) ?>
                <span class="text-muted ms-1">โดย <?= e($rep['reviewer_name'] ?? '-') ?> · <?= e(thai_datetime($rep['reviewed_at'])) ?></span>
                <?php if ($rep['status'] === 'pending'): ?><span class="text-muted">(ครูแก้ไขและส่งใหม่แล้ว)</span><?php endif; ?></div>
            <?= nl2br(e((string)$rep['review_comment'])) ?: '<span class="text-muted">- ไม่มีความเห็น -</span>' ?>
        </div>
    </div>
<?php endif; ?>
<?php unset($repImages, $repAttend, $im, $label, $col, $cls); ?>
