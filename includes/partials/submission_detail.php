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

<?php
// แผนการจัดการเรียนรู้: แสดงผลการประเมินแผน (แบบประเมิน 3 ตอน) และลิงก์ไปหน้าประเมิน/ดูผล
// ครูเจ้าของแผนเห็นผลเมื่อผู้ประเมิน "ส่งผลการประเมิน" แล้วเท่านั้น (ร่างไม่แสดง)
if ($subType === 'lesson_plan' && ($sub['doc_type'] ?? '') === 'plan'):
    $detailEval = db_one('SELECT * FROM plan_evaluations WHERE lesson_plan_id = ?', 'i', [(int)$sub['id']]);
    $detailOwn = (int)$sub['teacher_id'] === (int)$user['id'];
    $detailCanEval = can_evaluate_plan($user, $sub);
    $detailFinal = plan_eval_is_final($detailEval);
    if ($detailOwn && !$detailFinal) {
        $detailEval = null;
    }
    $detailLink = $detailOwn ? 'teacher/plan_evaluations.php?view=' : (is_plan_evaluator($user) ? 'review/plan_evaluations.php?view=' : '');
    if ($detailEval || $detailCanEval):
        $detailItems = $detailFinal ? plan_eval_items((int)$detailEval['id']) : ['check' => [], 'quality' => []];
        $detailCats = plan_eval_category_summary($detailItems['quality']);
        ?>
    <div class="card mb-4 border-<?= $detailFinal ? ($detailEval['is_passed'] ? 'success' : 'warning') : 'secondary-subtle' ?>">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-clipboard-data"></i> ผลการประเมินแผนการจัดการเรียนรู้
            <?php if ($detailEval): ?><span class="small text-muted fw-normal">(ฉบับที่ <?= (int)$detailEval['plan_version'] ?>)</span><?php endif; ?>
            <span class="ms-auto d-flex flex-wrap gap-1">
                <?php if ($detailEval && !$detailFinal): ?><?= plan_eval_status_badge('draft') ?><?php endif; ?>
                <?php if ($detailFinal && $detailLink !== ''): ?><a href="<?= e(url($detailLink . (int)$sub['id'])) ?>" class="btn btn-sm <?= $detailOwn && !$detailEval['acknowledged_at'] ? 'btn-warning' : 'btn-outline-primary' ?>"><i class="bi bi-eye"></i> <?= $detailOwn && !$detailEval['acknowledged_at'] ? 'ดูผลและกดรับทราบ' : 'ดูผลฉบับเต็ม' ?></a><?php endif; ?>
                <?php if ($detailCanEval): ?><a href="<?= e(url('review/plan_evaluations.php?evaluate=' . (int)$sub['id'])) ?>" class="btn btn-sm btn-accent"><i class="bi bi-clipboard-check"></i> <?= $detailEval ? ($detailFinal ? 'แก้ไขผลประเมิน' : 'ประเมินต่อ') : 'ประเมินแผนนี้' ?></a><?php endif; ?>
            </span>
        </div>
        <?php if (!$detailEval): ?>
            <div class="card-body small text-muted">แผนนี้ยังไม่ได้รับการประเมินคุณภาพ (แบบประเมินแผนการจัดการเรียนรู้)</div>
        <?php elseif (!$detailFinal): ?>
            <div class="card-body small text-muted">ผู้ประเมินบันทึกร่างไว้ ครูจะเห็นผลเมื่อผู้ประเมินกด "ส่งผลการประเมิน"</div>
        <?php else: ?>
            <div class="card-body">
                <div class="row g-3 align-items-center mb-2">
                    <div class="col-sm-4 text-center">
                        <div class="small text-muted">คะแนนรวม</div>
                        <div class="fs-3 fw-bold text-primary-th"><?= (int)$detailEval['total_score'] ?>/<?= (int)$detailEval['max_score'] ?></div>
                        <div class="small">ค่าเฉลี่ย <?= number_format((float)$detailEval['avg_score'], 2) ?></div>
                    </div>
                    <div class="col-sm-8">
                        <div class="mb-1"><?= plan_eval_level_badge((float)$detailEval['avg_score']) ?> <?= plan_eval_pass_badge((bool)$detailEval['is_passed']) ?>
                            <?php if (!$detailEval['check_passed']): ?><span class="small text-danger ms-1">ตอนที่ 1 รายการที่ต้องมียังไม่ครบ</span><?php endif; ?></div>
                        <div class="small"><strong>ผลการพิจารณา:</strong> <?= e(plan_eval_decision_text($detailEval)) ?></div>
                        <div class="small text-muted"><?= $detailEval['acknowledged_at'] ? 'ครูรับทราบผลแล้วเมื่อ ' . e(thai_datetime($detailEval['acknowledged_at'])) : 'ครูยังไม่ได้กดรับทราบผล' ?></div>
                    </div>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0 align-middle small">
                        <thead class="table-light"><tr><th>ข้อ</th><th>รายการประเมิน (ตอนที่ 2)</th><th class="text-center" style="width:70px">คะแนน</th></tr></thead>
                        <tbody>
                        <?php $dNo = 0; $dCat = null; foreach ($detailItems['quality'] as $dIt): $dNo++; ?>
                            <?php if ($dIt['category'] !== $dCat): $dCat = $dIt['category']; ?>
                                <tr class="table-light"><td colspan="3" class="fw-semibold text-primary-th"><?= e($dCat) ?>
                                    <span class="float-end fw-normal">เฉลี่ย <?= number_format($detailCats[$dCat]['avg'] ?? 0, 2) ?></span></td></tr>
                            <?php endif; ?>
                            <tr><td class="text-center"><?= $dNo ?></td><td><?= e($dIt['title']) ?></td>
                                <td class="text-center fw-semibold <?= (int)$dIt['score'] <= 2 ? 'text-danger' : '' ?>"><?= (int)$dIt['score'] ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="fw-semibold small mb-1"><i class="bi bi-star text-success me-1"></i>จุดเด่นของแผนการจัดการเรียนรู้</div>
                        <div class="border rounded p-2 bg-light small"><?= nl2br(e($detailEval['strengths'] ?: '-')) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="fw-semibold small mb-1"><i class="bi bi-lightbulb text-accent me-1"></i>สิ่งที่ควรปรับปรุง/พัฒนา</div>
                        <div class="border rounded p-2 bg-light small"><?= nl2br(e($detailEval['improvements'] ?: '-')) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif;
endif; ?>

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
