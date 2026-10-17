<?php
/**
 * ไฟล์: reports/supervision_report.php?id=xx
 * ดูรายงานการนิเทศครูผู้สอน + เสนอ/พิจารณาตามลำดับ
 *   - หัวหน้าแผนก (ผู้จัดทำ): แก้ไขร่าง และ "เสนอรายงาน" (ระบบบันทึกข้อมูล ณ เวลาที่เสนอ)
 *   - ผู้พิจารณาขั้นปัจจุบัน: "เห็นควรเสนอ" (ขั้น 1-2) / "ทราบ/อนุมัติ" (ขั้น 3) หรือ "ส่งกลับแก้ไข" พร้อมความเห็น
 *   - admin / ผู้บริหาร: ดูได้ทุกรายงานที่เสนอแล้ว
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$rep = report_fetch(get_int('id') ?: (int)post('id'));
if (!$rep || !can_view_report($user, $rep)) {
    abort_page(404, 'ไม่พบรายงาน', 'รายงานนี้ไม่มีอยู่ หรือท่านไม่มีสิทธิ์เปิดดู');
}
$chain = report_approval_chain();
$errors = [];

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- ผู้จัดทำเสนอรายงาน ----------
    if ($action === 'submit') {
        if (!can_edit_report($user, $rep)) {
            abort_page(403, 'ไม่สามารถเสนอรายงานนี้ได้');
        }
        if (!report_chain_ready()) {
            $errors['submit'] = 'ผู้ดูแลระบบยังกำหนดผู้พิจารณาไม่ครบ 3 ขั้น จึงยังเสนอไม่ได้';
        } elseif (trim((string)$rep['summary_overall']) === '' || trim((string)$rep['suggestions']) === '') {
            $errors['submit'] = 'กรุณากรอก "สรุปผลการนิเทศโดยภาพรวม" และ "ข้อเสนอแนะ/แนวทางพัฒนา" ก่อนเสนอ (กดแก้ไข)';
        }
        if (!$errors) {
            $snapshot = json_encode(
                report_build_data((int)$rep['department_id'], (int)$rep['academic_year_id'], $rep['period_start'], $rep['period_end']),
                JSON_UNESCAPED_UNICODE
            );
            db()->begin_transaction();
            try {
                db_exec("UPDATE supervision_reports SET status = 'pending', current_step = 1, snapshot = ?, submitted_at = NOW(), approved_at = NULL
                         WHERE id = ? AND status IN ('draft','returned')", 'si', [$snapshot, (int)$rep['id']]);
                db_insert('INSERT INTO supervision_report_actions (report_id, step, user_id, position, action) VALUES (?, 0, ?, ?, \'submit\')',
                    'iis', [(int)$rep['id'], $uid, 'หัวหน้า' . $rep['department_name']]);
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollback();
                throw $ex;
            }
            log_activity('report_submit', 'เสนอรายงานการนิเทศ #' . (int)$rep['id'] . ' ถึง' . $chain[1]['title']);
            flash('success', 'เสนอรายงานถึง ' . $chain[1]['title'] . ' แล้ว');
            redirect('reports/supervision_report.php?id=' . (int)$rep['id']);
        }
    }

    // ---------- ผู้พิจารณา ----------
    if ($action === 'decide') {
        if (!is_current_report_approver($user, $rep)) {
            abort_page(403, 'ท่านไม่ใช่ผู้พิจารณาขั้นนี้', 'รายงานอาจถูกพิจารณาไปแล้ว');
        }
        $decision = post('decision');
        $comment = mb_substr(post('comment'), 0, 5000);
        $step = (int)$rep['current_step'];
        if (!in_array($decision, ['forward', 'return'], true)) {
            $errors['decision'] = 'กรุณาเลือกผลการพิจารณา';
        } elseif ($decision === 'return' && $comment === '') {
            $errors['comment'] = 'กรุณาระบุสิ่งที่ต้องแก้ไข';
        }
        if (!$errors) {
            db()->begin_transaction();
            try {
                // ล็อกแถว ตรวจว่ายังอยู่ขั้นเดิม (กันกดซ้ำ/พิจารณาพร้อมกัน)
                $now = db_one('SELECT status, current_step FROM supervision_reports WHERE id = ? FOR UPDATE', 'i', [(int)$rep['id']]);
                if ($now['status'] !== 'pending' || (int)$now['current_step'] !== $step) {
                    db()->rollback();
                    flash('warning', 'รายงานนี้ถูกพิจารณาไปแล้ว');
                    redirect('reports/supervision_report.php?id=' . (int)$rep['id']);
                }
                if ($decision === 'return') {
                    db_exec("UPDATE supervision_reports SET status = 'returned', current_step = 0 WHERE id = ?", 'i', [(int)$rep['id']]);
                    $act = 'return';
                } elseif ($step >= 3) {
                    db_exec("UPDATE supervision_reports SET status = 'approved', current_step = 0, approved_at = NOW() WHERE id = ?", 'i', [(int)$rep['id']]);
                    $act = 'approve';
                } else {
                    db_exec('UPDATE supervision_reports SET current_step = ? WHERE id = ?', 'ii', [$step + 1, (int)$rep['id']]);
                    $act = 'forward';
                }
                db_insert(
                    'INSERT INTO supervision_report_actions (report_id, step, user_id, position, action, comment) VALUES (?, ?, ?, ?, ?, ?)',
                    'iiisss',
                    [(int)$rep['id'], $step, $uid, $chain[$step]['title'], $act, $comment !== '' ? $comment : null]
                );
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollback();
                throw $ex;
            }
            log_activity('report_' . $act, report_action_label($act, $step) . ' รายงานการนิเทศ #' . (int)$rep['id'] . ' (' . $chain[$step]['title'] . ')');
            $msg = ['return' => 'ส่งกลับให้หัวหน้าแผนกแก้ไขแล้ว', 'approve' => 'ลงนามทราบ/อนุมัติรายงานแล้ว',
                'forward' => 'เสนอต่อ ' . ($chain[$step + 1]['title'] ?? '') . ' แล้ว'][$act];
            flash('success', $msg);
            redirect('reports/supervision_report.php?id=' . (int)$rep['id']);
        }
    }
}

$data = report_data($rep);
$opinions = in_array($rep['status'], ['draft'], true) ? [] : report_latest_opinions((int)$rep['id']);
$history = report_actions((int)$rep['id']);
$isApprover = is_current_report_approver($user, $rep);
$canEdit = can_edit_report($user, $rep);

$pageTitle = 'รายงานการนิเทศครูผู้สอน';
$pageIcon = 'bi-file-earmark-text';
$activeMenu = $user['role'] === 'supervisor' ? 'supervisor/supervision_reports.php' : 'reports/supervision_reports.php';
$breadcrumbs = ['รายงานการนิเทศ' => $activeMenu, $rep['department_name'] => null];
$pageActions = ($canEdit ? '<a href="' . e(url('supervisor/supervision_reports.php?edit=' . (int)$rep['id'])) . '" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>แก้ไข</a>' : '')
    . '<a href="' . e(url('reports/supervision_report_print.php?id=' . (int)$rep['id'])) . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>พิมพ์บันทึกข้อความ / PDF</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start gap-2">
            <div>
                <h5 class="fw-bold text-primary-th mb-1"><?= e($rep['title']) ?></h5>
                <div class="small text-muted"><?= e($rep['department_name']) ?> · ภาคเรียน <?= e($rep['ay_semester'] . '/' . $rep['ay_year']) ?>
                    <?php if ($rep['period_start'] || $rep['period_end']): ?> · ช่วงวันที่ <?= e(thai_date($rep['period_start'])) ?> – <?= e(thai_date($rep['period_end'])) ?><?php endif; ?>
                    · ผู้จัดทำ <?= e($rep['author_name']) ?></div>
            </div>
            <div class="ms-auto"><?= report_status_badge($rep) ?></div>
        </div>

        <!-- ลำดับการเสนอ -->
        <div class="row g-2 mt-3 text-center small">
            <?php
            $steps = [0 => ['title' => 'หัวหน้า' . $rep['department_name'], 'name' => $rep['author_name']]] + $chain;
            foreach ($steps as $i => $s):
                if ($i === 0) {
                    $state = $rep['submitted_at'] && $rep['status'] !== 'draft' && $rep['status'] !== 'returned' ? 'done' : 'current';
                } elseif ($rep['status'] === 'approved' || isset($opinions[$i]) && $opinions[$i]['action'] !== 'return') {
                    $state = 'done';
                } elseif ($rep['status'] === 'pending' && (int)$rep['current_step'] === $i) {
                    $state = 'current';
                } elseif (isset($opinions[$i]) && $opinions[$i]['action'] === 'return') {
                    $state = 'returned';
                } else {
                    $state = 'waiting';
                }
                $cls = ['done' => 'border-success bg-success bg-opacity-10', 'current' => 'border-warning bg-warning bg-opacity-10',
                    'returned' => 'border-danger bg-danger bg-opacity-10', 'waiting' => 'border-light-subtle'][$state];
                $icon = ['done' => 'bi-check-circle-fill text-success', 'current' => 'bi-hourglass-split text-warning',
                    'returned' => 'bi-arrow-return-left text-danger', 'waiting' => 'bi-circle text-muted'][$state];
                ?>
                <div class="col-6 col-lg-3">
                    <div class="border rounded-3 p-2 h-100 <?= $cls ?>">
                        <i class="bi <?= $icon ?> fs-5"></i>
                        <div class="fw-semibold"><?= $i === 0 ? 'ผู้จัดทำ' : 'ขั้นที่ ' . $i ?></div>
                        <div><?= e($s['title']) ?></div>
                        <div class="text-muted"><?= e($s['name'] ?: '— ยังไม่กำหนด —') ?></div>
                        <?php if ($i > 0 && isset($opinions[$i])): ?><div class="text-muted"><?= e(thai_datetime($opinions[$i]['created_at'])) ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($rep['status'] === 'returned' && ($ret = end($history)) && $ret['action'] === 'return'): ?>
    <div class="alert alert-danger">
        <div class="fw-bold"><i class="bi bi-arrow-return-left me-1"></i>ส่งกลับแก้ไขโดย <?= e($ret['position']) ?> (<?= e((string)$ret['user_name']) ?>) เมื่อ <?= e(thai_datetime($ret['created_at'])) ?></div>
        <?= nl2br(e((string)$ret['comment'])) ?>
    </div>
<?php endif; ?>

<?php if ($canEdit): ?>
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1">
                <div class="fw-semibold"><i class="bi bi-send me-1"></i>เสนอรายงาน</div>
                <div class="small text-muted">ตรวจสอบข้อมูลด้านล่าง แล้วกดเสนอถึง <?= e($chain[1]['title']) ?><?= $chain[1]['name'] ? ' (' . e($chain[1]['name']) . ')' : '' ?>
                    — เมื่อเสนอแล้วจะแก้ไขไม่ได้ จนกว่าจะถูกส่งกลับ</div>
                <?php if (isset($errors['submit'])): ?><div class="text-danger small mt-1"><?= e($errors['submit']) ?></div><?php endif; ?>
            </div>
            <form method="post" data-confirm="เสนอรายงานการนิเทศถึง <?= e($chain[1]['title']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="submit"><input type="hidden" name="id" value="<?= (int)$rep['id'] ?>">
                <button class="btn btn-accent px-4" <?= report_chain_ready() ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i>เสนอรายงาน</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($isApprover): $step = (int)$rep['current_step']; ?>
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-header"><i class="bi bi-pen me-2"></i>พิจารณาในฐานะ <?= e($chain[$step]['title']) ?></div>
        <div class="card-body">
            <form method="post" novalidate data-confirm="ยืนยันผลการพิจารณา?">
                <?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="<?= (int)$rep['id'] ?>">
                <div class="btn-group mb-3" role="group" aria-label="ผลการพิจารณา">
                    <input type="radio" class="btn-check" name="decision" id="dForward" value="forward" <?= post('decision') === 'forward' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-success" for="dForward"><i class="bi bi-check2-circle me-1"></i>
                        <?= $step >= 3 ? 'ทราบ/อนุมัติ' : 'เห็นควรเสนอ ' . e($chain[$step + 1]['title']) ?></label>
                    <input type="radio" class="btn-check" name="decision" id="dReturn" value="return" <?= post('decision') === 'return' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-danger" for="dReturn"><i class="bi bi-arrow-return-left me-1"></i>ส่งกลับแก้ไข</label>
                </div>
                <?php if (isset($errors['decision'])): ?><div class="text-danger small mb-2"><?= e($errors['decision']) ?></div><?php endif; ?>
                <label class="form-label" for="comment">ความเห็น<?= $step >= 3 ? ' / ข้อสั่งการ' : '' ?></label>
                <textarea class="form-control<?= isset($errors['comment']) ? ' is-invalid' : '' ?>" name="comment" id="comment" rows="3" maxlength="5000"
                          placeholder="<?= $step >= 3 ? 'เช่น ทราบ ให้แผนกวิชาดำเนินการพัฒนาตามข้อเสนอแนะ' : 'เช่น เห็นควรเสนอเพื่อโปรดทราบ' ?>"><?= old('comment') ?></textarea>
                <?php if (isset($errors['comment'])): ?><div class="invalid-feedback"><?= e($errors['comment']) ?></div><?php endif; ?>
                <button class="btn btn-accent mt-3 px-4"><i class="bi bi-save me-1"></i>บันทึกการพิจารณา</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($opinions): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-chat-square-text me-2"></i>ความเห็นผู้พิจารณา</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($opinions as $i => $o): ?>
                <li class="list-group-item">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="fw-semibold"><?= e($o['position']) ?></span>
                        <span class="badge <?= $o['action'] === 'return' ? 'bg-danger' : 'bg-success' ?>"><?= e(report_action_label($o['action'], $i)) ?></span>
                        <span class="small text-muted ms-auto"><?= e((string)$o['user_name']) ?> · <?= e(thai_datetime($o['created_at'])) ?></span>
                    </div>
                    <div class="mt-1"><?= $o['comment'] ? nl2br(e($o['comment'])) : '<span class="text-muted">-</span>' ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/partials/supervision_report_body.php'; ?>

<?php if ($history): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-clock-history me-2"></i>ประวัติการเสนอ/พิจารณา</div>
        <ul class="list-group list-group-flush small">
            <?php foreach ($history as $h): ?>
                <li class="list-group-item d-flex flex-wrap gap-2">
                    <span class="text-muted"><?= e(thai_datetime($h['created_at'])) ?></span>
                    <span class="fw-semibold"><?= e(report_action_label($h['action'], (int)$h['step'])) ?></span>
                    <span><?= e($h['position']) ?> (<?= e((string)$h['user_name']) ?>)</span>
                    <?php if ($h['comment']): ?><span class="text-muted">— <?= e(str_limit($h['comment'], 120)) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
