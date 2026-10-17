<?php
/**
 * ไฟล์: includes/partials/submission_detail.php
 * แสดงรายละเอียดงาน + ไฟล์ทุกเวอร์ชัน + ประวัติการตรวจ (ใช้ได้ทุกประเภทงาน ทุก role)
 * ต้องมีตัวแปร: $sub (จาก submission_fetch)
 */
$subType = $sub['_type'];
$detailFiles = submission_files($subType, (int)$sub['id']);
$detailReviews = submission_reviews($subType, (int)$sub['id']);
// ข้อความยาว (หลายบรรทัด) แสดงเป็นกล่องเต็มความกว้าง
$isLong = fn($v) => mb_strlen((string)$v) > 80 || strpos((string)$v, "\n") !== false;
?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi <?= e(submission_type_icon($subType)) ?>"></i>
        <span><?= e($sub['title']) ?></span>
        <span class="ms-auto"><?= status_badge($sub['status'], (bool)$sub['is_late']) ?></span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <?php foreach (submission_detail_fields($subType, $sub) as $label => $value): ?>
                <?php if ($isLong($value)): ?>
                    <dt class="col-12 text-muted fw-normal small mt-2"><?= e($label) ?></dt>
                    <dd class="col-12 border rounded p-2 bg-light"><?= nl2br(e((string)$value)) ?: '-' ?></dd>
                <?php else: ?>
                    <dt class="col-sm-4 text-muted fw-normal small"><?= e($label) ?></dt>
                    <dd class="col-sm-8"><?= e((string)$value) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
            <dt class="col-sm-4 text-muted fw-normal small">ส่งครั้งแรก</dt>
            <dd class="col-sm-8"><?= e(thai_datetime($sub['submitted_at'])) ?></dd>
            <dt class="col-sm-4 text-muted fw-normal small">เวอร์ชันปัจจุบัน</dt>
            <dd class="col-sm-8 mb-0">ฉบับที่ <?= (int)$sub['current_version'] ?></dd>
        </dl>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-files me-2"></i>ไฟล์ทุกเวอร์ชัน</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($detailFiles as $f):
                    $isPdf = strtolower(pathinfo($f['stored_name'], PATHINFO_EXTENSION)) === 'pdf'; ?>
                    <li class="list-group-item d-flex align-items-center gap-3">
                        <i class="bi <?= e(file_icon($f['original_name'])) ?> fs-3"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">
                                ฉบับที่ <?= (int)$f['version'] ?>
                                <?php if ((int)$f['version'] === (int)$sub['current_version']): ?><span class="badge bg-primary-th ms-1">ล่าสุด</span><?php endif; ?>
                                <?php if ($f['is_late']): ?><?= status_badge('late') ?><?php endif; ?>
                            </div>
                            <div class="small text-truncate"><?= e($f['original_name']) ?></div>
                            <div class="small text-muted"><?= e(format_bytes((int)$f['file_size'])) ?> · <?= e(thai_datetime($f['uploaded_at'])) ?></div>
                            <?php if ($f['note']): ?><div class="small fst-italic">หมายเหตุ: <?= e($f['note']) ?></div><?php endif; ?>
                        </div>
                        <div class="text-nowrap">
                            <?php if ($isPdf): ?>
                                <a href="<?= e(url('file.php?id=' . (int)$f['id'] . '&inline=1')) ?>" target="_blank" rel="noopener"
                                   class="btn btn-sm btn-outline-primary" title="เปิดดู" data-bs-toggle="tooltip"><i class="bi bi-eye"></i></a>
                            <?php endif; ?>
                            <a href="<?= e(url('file.php?id=' . (int)$f['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="ดาวน์โหลด" data-bs-toggle="tooltip"><i class="bi bi-download"></i></a>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$detailFiles): ?><li class="list-group-item empty-state">ไม่มีไฟล์</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-chat-left-text me-2"></i>ผลการตรวจและความเห็น</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($detailReviews as $rv): ?>
                    <li class="list-group-item">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <?= status_badge($rv['result']) ?>
                            <span class="small text-muted">ฉบับที่ <?= (int)$rv['version'] ?></span>
                            <span class="small text-muted ms-auto"><?= e(thai_datetime($rv['created_at'])) ?></span>
                        </div>
                        <div><?= $rv['comment'] !== null && $rv['comment'] !== '' ? nl2br(e($rv['comment'])) : '<span class="text-muted">- ไม่มีความเห็น -</span>' ?></div>
                        <div class="small text-muted mt-1"><i class="bi bi-person-check me-1"></i><?= e($rv['reviewer_name'] ?? 'ผู้ตรวจ') ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$detailReviews): ?><li class="list-group-item empty-state"><i class="bi bi-hourglass-split"></i>ยังไม่มีผลการตรวจ</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
