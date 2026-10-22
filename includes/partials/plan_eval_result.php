<?php
/**
 * ไฟล์: includes/partials/plan_eval_result.php
 * แสดงผลการประเมินแผนการจัดการเรียนรู้ (ข้อมูลทั่วไป ตอนที่ 1–3 สรุปรายด้าน ผ่าน/ไม่ผ่านเกณฑ์)
 * ใช้ร่วมกันในหน้าผู้ประเมิน / ครู / รายงาน
 * ต้องมีตัวแปร: $plan (จาก plan_eval_fetch_plan), $ev (จาก plan_eval_fetch หรือ null)
 */
$evItems = $ev ? plan_eval_items((int)$ev['id']) : ['check' => [], 'quality' => []];
$evCats = plan_eval_category_summary($evItems['quality']);
?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-journal-text"></i> ข้อมูลทั่วไป
        <span class="ms-auto"><?= !$ev ? '<span class="badge bg-secondary">ยังไม่ประเมิน</span>'
            : (plan_eval_is_final($ev) ? plan_eval_pass_badge((bool)$ev['is_passed']) : plan_eval_status_badge('draft')) ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3 small">
            <div class="col-md-6">
                <div class="text-muted">ผู้จัดทำ (ครูผู้สอน)</div>
                <div class="fw-semibold fs-6"><?= e($plan['teacher_name']) ?></div>
                <div><?= e($plan['teacher_position']) ?> · <?= e($plan['department_name'] ?? '-') ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted">รายวิชา</div>
                <div class="fw-semibold fs-6"><?= e($plan['subject_code'] . ' ' . $plan['subject_name']) ?></div>
                <div>ภาคเรียน <?= $plan['ay_year'] ? e($plan['ay_semester'] . '/' . $plan['ay_year']) : '-' ?> · <?= e($plan['round_name']) ?></div>
            </div>
            <?php if ($ev): ?>
                <div class="col-6 col-md-3"><div class="text-muted">หลักสูตร</div><?= e($ev['program']) ?></div>
                <div class="col-6 col-md-3"><div class="text-muted">ประเภทวิชา</div><?= e($ev['subject_category'] ?: '-') ?></div>
                <div class="col-6 col-md-3"><div class="text-muted">สาขาวิชา</div><?= e($ev['major'] ?: '-') ?></div>
                <div class="col-6 col-md-3"><div class="text-muted">ท-ป-น</div><?= e($ev['credit'] ?: '-') ?></div>
                <div class="col-6 col-md-3"><div class="text-muted">จำนวนหน่วยการเรียนรู้</div><?= (int)$ev['unit_count'] ?> หน่วย</div>
                <div class="col-6 col-md-3"><div class="text-muted">จำนวนแผนการจัดการเรียนรู้</div><?= (int)$ev['plan_count'] ?> แผน</div>
                <div class="col-6 col-md-3"><div class="text-muted">อ้างอิงมาตรฐานอาชีพฯ</div><?= $ev['has_standard'] ? 'มี' : 'ไม่มี' ?></div>
                <div class="col-6 col-md-3"><div class="text-muted">ฉบับที่ประเมิน</div>ฉบับที่ <?= (int)$ev['plan_version'] ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$ev): ?>
    <div class="card mb-4"><div class="empty-state"><i class="bi bi-hourglass-split"></i>ยังไม่มีผลการประเมินแผนการจัดการเรียนรู้</div></div>
<?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card h-100 text-center">
                <div class="card-body d-flex flex-column justify-content-center">
                    <div class="text-muted">ค่าเฉลี่ยรวม (ตอนที่ 2)</div>
                    <div class="display-4 fw-bold text-primary-th"><?= e(number_format((float)$ev['avg_score'], 2)) ?></div>
                    <div class="mb-2">คะแนนรวม <?= (int)$ev['total_score'] ?>/<?= (int)$ev['max_score'] ?>
                        (ร้อยละ <?= percent((float)$ev['total_score'], (float)$ev['max_score']) ?>)</div>
                    <div class="fs-5 mb-2"><?= plan_eval_level_badge((float)$ev['avg_score']) ?></div>
                    <div><?= plan_eval_pass_badge((bool)$ev['is_passed']) ?></div>
                    <?php if (!$ev['check_passed']): ?><div class="small text-danger mt-1">ตอนที่ 1 รายการที่ต้องมียังไม่ครบ</div><?php endif; ?>
                    <hr>
                    <?php if (!plan_eval_is_final($ev)): ?>
                        <div class="text-warning small"><i class="bi bi-pencil me-1"></i>ร่าง — ยังไม่ได้ส่งผลให้ครู</div>
                    <?php elseif ($ev['acknowledged_at']): ?>
                        <div class="text-success small"><i class="bi bi-check2-circle me-1"></i>ครูรับทราบผลแล้วเมื่อ<br><?= e(thai_datetime($ev['acknowledged_at'])) ?></div>
                    <?php else: ?>
                        <div class="text-warning small"><i class="bi bi-clock me-1"></i>ครูยังไม่ได้กดรับทราบผล</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-bullseye me-2"></i>สรุปผลการประเมินรายด้าน</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>ด้านที่ประเมิน</th><th class="text-center">จำนวนข้อ</th><th class="text-center">คะแนนเต็ม</th><th class="text-center">คะแนนที่ได้</th><th class="text-center">ค่าเฉลี่ย</th></tr></thead>
                        <tbody>
                        <?php foreach ($evCats as $cat => $c): ?>
                            <tr><td class="small"><?= e($cat) ?></td><td class="text-center"><?= $c['n'] ?></td><td class="text-center"><?= $c['n'] * 5 ?></td>
                                <td class="text-center"><?= $c['sum'] ?></td><td class="text-center"><?= plan_eval_level_badge($c['avg']) ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="fw-semibold table-light"><td>รวม</td><td class="text-center"><?= (int)$ev['item_count'] ?></td><td class="text-center"><?= (int)$ev['max_score'] ?></td>
                            <td class="text-center"><?= (int)$ev['total_score'] ?></td><td class="text-center"><?= number_format((float)$ev['avg_score'], 2) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-list-check me-2"></i>ตอนที่ 1 การตรวจสอบองค์ประกอบของแผนการจัดการเรียนรู้</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle">
                <thead class="text-center"><tr><th style="width:44px">ที่</th><th class="text-start">รายการ</th><th style="width:70px">มี</th><th style="width:70px">ไม่มี</th><th style="width:90px">ไม่เกี่ยวข้อง</th></tr></thead>
                <tbody>
                <?php $lastCat = null; $no = 0; foreach ($evItems['check'] as $it): $no++; ?>
                    <?php if ($it['category'] !== $lastCat): $lastCat = $it['category']; ?>
                        <tr class="table-light"><td colspan="5" class="fw-semibold text-primary-th"><?= e($it['category']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="<?= $it['is_required'] && $it['check_result'] !== 'yes' ? 'table-danger' : '' ?>">
                        <td class="text-center"><?= $no ?></td>
                        <td><?= e($it['title']) ?><?= $it['is_required'] ? ' <span class="badge bg-light text-dark border fw-normal">ต้องมี</span>' : '' ?></td>
                        <?php foreach (['yes', 'no', 'na'] as $k): ?>
                            <td class="text-center<?= $k === 'na' && $it['is_required'] ? ' bg-secondary-subtle' : '' ?>"><?= $it['check_result'] === $k ? '<i class="bi bi-check-lg fs-5 ' . ($k === 'no' ? 'text-danger' : 'text-success') . '"></i>' : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-table me-2"></i>ตอนที่ 2 การประเมินคุณภาพของแผนการจัดการเรียนรู้</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle">
                <thead class="text-center"><tr><th style="width:44px">ข้อ</th><th class="text-start">รายการประเมิน</th><?php foreach ([5, 4, 3, 2, 1] as $n): ?><th style="width:44px"><?= $n ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php $lastCat = null; $no = 0; foreach ($evItems['quality'] as $it): $no++; ?>
                    <?php if ($it['category'] !== $lastCat): $lastCat = $it['category']; ?>
                        <tr class="table-light"><td colspan="7" class="fw-semibold text-primary-th"><?= e($it['category']) ?>
                            <span class="float-end fw-normal small">เฉลี่ย <?= number_format($evCats[$it['category']]['avg'] ?? 0, 2) ?></span></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-center"><?= $no ?></td>
                        <td><?= e($it['title']) ?></td>
                        <?php foreach ([5, 4, 3, 2, 1] as $n): ?><td class="text-center"><?= (int)$it['score'] === $n ? '<i class="bi bi-check-lg fs-5 text-success"></i>' : '' ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-chat-square-quote me-2"></i>ตอนที่ 3 ข้อเสนอแนะและผลการพิจารณา</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="fw-semibold mb-1"><i class="bi bi-star text-success me-1"></i>จุดเด่นของแผนการจัดการเรียนรู้</div>
                    <div class="border rounded p-2 bg-light small" style="min-height:70px"><?= nl2br(e($ev['strengths'] ?: '-')) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-accent me-1"></i>สิ่งที่ควรปรับปรุง/พัฒนา</div>
                    <div class="border rounded p-2 bg-light small" style="min-height:70px"><?= nl2br(e($ev['improvements'] ?: '-')) ?></div>
                </div>
                <div class="col-12">
                    <div class="fw-semibold mb-1"><i class="bi bi-flag me-1"></i>ผลการพิจารณา</div>
                    <div class="alert <?= $ev['decision'] === 'resubmit' ? 'alert-danger' : ($ev['decision'] === 'approve' ? 'alert-success' : 'alert-warning') ?> mb-0 py-2">
                        <?= e(plan_eval_decision_text($ev)) ?></div>
                </div>
                <div class="col-12 small text-muted">
                    <i class="bi bi-person-check me-1"></i>ผู้ประเมิน: <?= e($ev['evaluator_name'] ?? '-') ?><?= $ev['evaluator_position'] ? ' (' . e($ev['evaluator_position']) . ')' : '' ?>
                    · บันทึกเมื่อ <?= e(thai_datetime($ev['updated_at'] ?: $ev['created_at'])) ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
