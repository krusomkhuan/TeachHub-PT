<?php
/**
 * ไฟล์: includes/partials/home_visit_detail.php
 * แสดงรายละเอียดรายงานการเยี่ยมบ้าน + ภาพถ่าย + พิกัด (ใช้ทั้งหน้าครู ผู้ตรวจ และรายงานสรุป)
 * ต้องมีตัวแปร: $hv (จาก home_visit_fetch)
 */
$hvImages = home_visit_images((int)$hv['id']);
$hvText = function (string $label, ?string $value): void {
    echo '<div class="text-muted small">' . e($label) . '</div>'
        . '<div class="border rounded p-2 bg-light mb-3">' . (nl2br(e((string)$value)) ?: '<span class="text-muted">-</span>') . '</div>';
};
?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-house-heart"></i>
        <span>เยี่ยมบ้าน <?= e($hv['student_name']) ?> (<?= e($hv['student_code']) ?>)</span>
        <span class="ms-auto"><?= home_visit_risk_badge($hv['risk_level']) ?> <?= status_badge($hv['status']) ?></span>
    </div>
    <div class="card-body">
        <dl class="row mb-3">
            <dt class="col-sm-4 text-muted fw-normal small">ครูที่ปรึกษา</dt>
            <dd class="col-sm-8"><?= e($hv['teacher_name']) ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">กลุ่มเรียน / แผนก</dt>
            <dd class="col-sm-8"><?= e($hv['group_name']) ?> · <?= e($hv['department_name'] ?? '-') ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">วันที่เยี่ยมบ้าน</dt>
            <dd class="col-sm-8"><?= e(thai_date($hv['visit_date'], false, true)) ?> (ปีการศึกษา <?= (int)$hv['school_year'] ?>)</dd>
            <dt class="col-sm-4 text-muted fw-normal small">ผู้ให้ข้อมูล</dt>
            <dd class="col-sm-8"><?= e($hv['informant_name'] ?: '-') ?><?= $hv['informant_relation'] ? ' (' . e($hv['informant_relation']) . ')' : '' ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">ที่อยู่</dt>
            <dd class="col-sm-8"><?= e($hv['address'] ?: '-') ?>
                <?php if ($hv['latitude'] !== null && $hv['longitude'] !== null): ?>
                    <a class="ms-1 small text-nowrap" target="_blank" rel="noopener noreferrer"
                       href="https://www.google.com/maps?q=<?= e($hv['latitude'] . ',' . $hv['longitude']) ?>"><i class="bi bi-geo-alt"></i> ดูแผนที่</a>
                <?php endif; ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">อาศัยอยู่กับ / เศรษฐกิจ</dt>
            <dd class="col-sm-8"><?= e($hv['living_with'] ?: '-') ?> · <?= e(home_visit_economic_labels()[$hv['economic_status']] ?? '-') ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">ส่งเมื่อ</dt>
            <dd class="col-sm-8 mb-0"><?= e(thai_datetime($hv['submitted_at'])) ?>
                <?php if ($hv['updated_at']): ?><span class="text-muted small">· แก้ไขล่าสุด <?= e(thai_datetime($hv['updated_at'])) ?></span><?php endif; ?></dd>
        </dl>
        <?php
        $hvText('สภาพครอบครัวและที่อยู่อาศัย', $hv['family_info']);
        $hvText('ด้านการเรียนและพฤติกรรม', $hv['learning_behavior']);
        $hvText('การช่วยเหลือ / ข้อเสนอแนะ', $hv['assistance']);
        ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-images me-2"></i>ภาพถ่ายการเยี่ยมบ้าน (<?= count($hvImages) ?> ภาพ)</div>
    <div class="card-body">
        <?php if ($hvImages): ?>
            <div class="row g-2">
                <?php foreach ($hvImages as $im): ?>
                    <div class="col-6 col-md-4">
                        <a href="<?= e(url('home_visit_image.php?id=' . (int)$im['id'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url('home_visit_image.php?id=' . (int)$im['id'])) ?>" class="img-fluid rounded border" style="aspect-ratio:4/3;object-fit:cover;width:100%" alt="ภาพการเยี่ยมบ้าน">
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-muted small">ไม่มีภาพถ่าย</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($hv['reviewed_at']): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-chat-left-text me-2"></i>ผลการตรวจล่าสุด</div>
        <div class="card-body small">
            <div class="mb-1"><?= status_badge($hv['status'] === 'pending' ? 'revision' : $hv['status']) ?>
                <span class="text-muted ms-1">โดย <?= e($hv['reviewer_name'] ?? '-') ?> · <?= e(thai_datetime($hv['reviewed_at'])) ?></span>
                <?php if ($hv['status'] === 'pending'): ?><span class="text-muted">(ครูแก้ไขและส่งใหม่แล้ว)</span><?php endif; ?></div>
            <?= nl2br(e((string)$hv['review_comment'])) ?: '<span class="text-muted">- ไม่มีความเห็น -</span>' ?>
        </div>
    </div>
<?php endif; ?>
<?php unset($hvImages, $hvText, $im); ?>
