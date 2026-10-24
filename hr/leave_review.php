<?php
/**
 * ไฟล์: hr/leave_review.php
 * ตรวจสอบ/พิจารณาใบลา
 *   หัวหน้าแผนก (ความเห็น) → งานบุคลากร (ตรวจสอบ ปรับจำนวนวันได้) → รองผู้อำนวยการ (ความเห็น) → ผู้อำนวยการ (อนุญาต/ไม่อนุญาต)
 *   ?id=xx   ดู/พิจารณาใบลา
 *   ?fy= ?status= ?dept= ?type=   ตัวกรองรายการ (ผู้ดูแลงานบุคลากร/ผู้บริหารเห็นทุกคน · หัวหน้าแผนกเห็นแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$self = 'hr/leave_review.php';
$activeMenu = $self;
$isHr = can_manage_hr($user);
$seeAll = can_view_hr_overview($user);
$isHead = $user['role'] === 'supervisor';
$errors = [];

$myPending = hr_leave_pending_for($user);
if (!$seeAll && !$isHead && !$myPending && !get_int('id') && !is_post()) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้พิจารณาใบลา และงานบุคลากร');
}

// =====================================================================
// POST: บันทึกความเห็น/ผลการพิจารณา
// =====================================================================
if (is_post()) {
    csrf_verify();
    $leave = hr_leave_fetch((int)post('id'));
    if (!$leave || !hr_leave_can_act($user, $leave)) {
        abort_page(403, 'ไม่สามารถพิจารณาใบลานี้ได้', 'ใบลาอาจถูกพิจารณาไปแล้ว หรือไม่ใช่ขั้นที่ท่านรับผิดชอบ');
    }
    $status = $leave['status'];
    $step = hr_leave_status_step($status);
    $decision = $step === 'hr' ? 'approve' : post('decision');
    $comment = mb_substr(post('comment'), 0, 1000);
    if (!in_array($decision, ['approve', 'reject'], true)) {
        $errors['decision'] = 'กรุณาเลือกความเห็น';
    }
    if ($decision === 'reject' && $comment === '') {
        $errors['comment'] = 'กรุณาระบุเหตุผล';
    }
    $days = (float)$leave['days'];
    if ($step === 'hr') {
        $days = round((float)post('days') * 2) / 2;
        if ($days <= 0 || $days > 180) {
            $errors['days'] = 'จำนวนวันลาไม่ถูกต้อง';
        }
    }
    if (!$errors) {
        if ($step === 'director') {
            $next = $decision === 'approve' ? 'approved' : 'rejected';
        } else {
            $order = hr_leave_pending_statuses();
            $next = hr_leave_next_status($order[array_search($status, $order, true) + 1] ?? 'approved', $leave);
        }
        db_exec('UPDATE hr_leaves SET status = ?, days = ? WHERE id = ? AND status = ?', 'sdis', [$next, $days, (int)$leave['id'], $status]);
        if ($step === 'hr' && $days !== (float)$leave['days']) {
            $comment = trim('ปรับจำนวนวันลาจาก ' . hr_days((float)$leave['days']) . ' เป็น ' . hr_days($days) . ' วัน ' . $comment);
        }
        hr_leave_log_action((int)$leave['id'], $step, $decision, $comment);
        log_activity('hr_leave_action', hr_leave_step_labels()[$step] . ' ' . ($decision === 'approve' ? 'เห็นชอบ' : 'ไม่เห็นชอบ') . ' ใบลา #'
            . (int)$leave['id'] . ' ของ ' . $leave['user_name'] . ' → ' . hr_leave_status_labels()[$next]);
        flash('success', 'บันทึกแล้ว — สถานะใบลา: ' . hr_leave_status_labels()[$next]);
        $more = array_values(array_filter(hr_leave_pending_for($user), fn($l) => (int)$l['id'] !== (int)$leave['id']));
        redirect($self . ($more ? '?id=' . (int)$more[0]['id'] : ''));
    }
    $_GET['id'] = (string)$leave['id'];
}

// =====================================================================
// ดู/พิจารณาใบลา (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $leave = hr_leave_fetch($viewId);
    if (!$leave || !hr_leave_can_view($user, $leave)) {
        abort_page(404, 'ไม่พบใบลา');
    }
    if ((int)$leave['user_id'] === $uid && !$isHr) {
        redirect('hr/leaves.php?id=' . $viewId);
    }
    $actions = hr_leave_actions($viewId);
    $canAct = hr_leave_can_act($user, $leave);
    $step = hr_leave_status_step($leave['status']);
    $canCancel = $isHr && (int)$leave['user_id'] !== $uid && !in_array($leave['status'], ['cancelled', 'rejected'], true);

    $pageTitle = 'พิจารณาใบลา';
    $pageIcon = 'bi-calendar2-check';
    $breadcrumbs = ['ตรวจสอบ/พิจารณาใบลา' => $self, $leave['user_name'] => null];
    $pageActions = '<a href="' . e(url('hr/leave_print.php?id=' . $viewId)) . '" target="_blank" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i> พิมพ์ใบลา</a>';
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4">
        <div class="col-xl-8"><?php require APP_ROOT . '/includes/partials/hr_leave_detail.php'; ?></div>
        <div class="col-xl-4">
            <?php if ($canAct): ?>
                <div class="card mb-4 border-top border-4 border-warning">
                    <div class="card-header"><i class="bi bi-pen me-2"></i><?= e(hr_leave_step_labels()[$step]) ?></div>
                    <div class="card-body">
                        <form method="post" novalidate data-confirm="ยืนยันบันทึก?">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>">
                            <?php if ($step === 'hr'): ?>
                                <div class="mb-3">
                                    <label class="form-label" for="days">จำนวนวันลา (ตรวจสอบวันหยุดราชการ)</label>
                                    <input type="number" class="form-control<?= isset($errors['days']) ? ' is-invalid' : '' ?>" name="days" id="days" step="0.5" min="0.5" max="180"
                                           value="<?= e(is_post() ? post('days') : hr_days((float)$leave['days'])) ?>">
                                    <?php if (isset($errors['days'])): ?><div class="invalid-feedback"><?= e($errors['days']) ?></div><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="btn-group w-100 mb-3" role="group">
                                    <input type="radio" class="btn-check" name="decision" id="dOk" value="approve" <?= post('decision') === 'approve' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success" for="dOk"><i class="bi bi-check-circle me-1"></i><?= $step === 'director' ? 'อนุญาต' : 'เห็นควรอนุญาต' ?></label>
                                    <input type="radio" class="btn-check" name="decision" id="dNo" value="reject" <?= post('decision') === 'reject' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-danger" for="dNo"><i class="bi bi-x-circle me-1"></i><?= $step === 'director' ? 'ไม่อนุญาต' : 'ไม่ควรอนุญาต' ?></label>
                                </div>
                                <?php if (isset($errors['decision'])): ?><div class="text-danger small mb-2"><?= e($errors['decision']) ?></div><?php endif; ?>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label" for="comment">ความเห็น</label>
                                <textarea class="form-control<?= isset($errors['comment']) ? ' is-invalid' : '' ?>" name="comment" id="comment" rows="3" maxlength="1000"><?= old('comment') ?></textarea>
                                <?php if (isset($errors['comment'])): ?><div class="invalid-feedback"><?= e($errors['comment']) ?></div><?php endif; ?>
                            </div>
                            <button class="btn btn-accent w-100" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i>
                                <?= $step === 'hr' ? 'ตรวจสอบแล้ว ส่งต่อ' : 'บันทึก' ?></button>
                            <?php if ($step !== 'director'): ?><div class="form-text">ความเห็นจะส่งต่อให้ขั้นถัดไปพิจารณา — ผู้อำนวยการเป็นผู้อนุญาต</div><?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($canCancel): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header text-danger"><i class="bi bi-x-circle me-2"></i>ยกเลิกใบลา (งานบุคลากร)</div>
                    <div class="card-body">
                        <form method="post" action="<?= e(url('hr/leaves.php')) ?>" data-confirm="ยกเลิกใบลานี้? (ไม่นับเป็นวันลา)" data-confirm-icon="warning">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $viewId ?>">
                            <input class="form-control form-control-sm mb-2" name="cancel_reason" maxlength="500" required placeholder="เหตุผล เช่น ผู้ลายกเลิกวันลา / บันทึกซ้ำ">
                            <button class="btn btn-sm btn-outline-danger w-100">ยกเลิกใบลา</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <a href="<?= e(url($self . '?fy=' . (int)$leave['fiscal_year'])) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการ
// =====================================================================
$fy = hr_fiscal_param();
$fStatus = isset(hr_leave_status_labels()[get('status')]) || get('status') === 'pending' ? get('status') : '';
$fType = isset(hr_leave_types()[get('type')]) ? get('type') : '';
$fDept = $seeAll ? get_int('dept') : (int)$user['department_id'];
$rows = [];
if ($seeAll || $isHead) {
    $where = ['l.fiscal_year = ?'];
    $types = 'i';
    $params = [$fy];
    if ($fDept > 0) {
        $where[] = 'u.department_id = ?';
        $types .= 'i';
        $params[] = $fDept;
    }
    if ($fType !== '') {
        $where[] = 'l.leave_type = ?';
        $types .= 's';
        $params[] = $fType;
    }
    if ($fStatus === 'pending') {
        $where[] = "l.status LIKE 'pending%'";
    } elseif ($fStatus !== '') {
        $where[] = 'l.status = ?';
        $types .= 's';
        $params[] = $fStatus;
    }
    $rows = db_all(
        "SELECT l.*, d.name AS department_name, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name
         FROM hr_leaves l JOIN users u ON u.id = l.user_id LEFT JOIN departments d ON d.id = u.department_id
         WHERE " . implode(' AND ', $where) . ' ORDER BY l.start_date DESC, l.id DESC',
        $types,
        $params
    );
}
$departments = db_all('SELECT id, name FROM departments ORDER BY name');
$q = function (array $over) use ($fy, $fStatus, $fType, $fDept): string {
    $base = ['fy' => $fy, 'status' => $fStatus, 'type' => $fType, 'dept' => $fDept ?: null];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
};

$pageTitle = 'ตรวจสอบ/พิจารณาใบลา';
$pageIcon = 'bi-calendar2-check';
$breadcrumbs = ['ตรวจสอบ/พิจารณาใบลา' => null];
$pageActions = '';
if ($isHr) {
    $people = hr_people();
    $pageActions = '<button type="button" class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#recordModal"><i class="bi bi-calendar-plus me-1"></i> บันทึกใบลาแทน</button>';
}
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4 border-top border-4 border-warning">
    <div class="card-header"><i class="bi bi-hourglass-split me-2"></i>รอท่านพิจารณา <span class="badge bg-danger ms-1"><?= count($myPending) ?></span></div>
    <div class="card-body">
        <?php if (!$myPending): ?>
            <div class="text-muted">ไม่มีใบลาที่รอท่านพิจารณา</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>ผู้ลา</th><th>ประเภท</th><th>วันที่ลา</th><th class="text-end">วัน</th><th>ขั้น</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($myPending as $r): ?>
                        <tr>
                            <td><?= e($r['user_name']) ?> <span class="small text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></span></td>
                            <td><?= e(hr_leave_type_label($r['leave_type'])) ?></td>
                            <td class="text-nowrap"><?= e(hr_leave_range_text($r)) ?></td>
                            <td class="text-end"><?= hr_days((float)$r['days']) ?></td>
                            <td><?= hr_leave_status_badge($r['status']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-accent" href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>"><i class="bi bi-pen"></i> พิจารณา</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($seeAll || $isHead): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                    <select name="fy" id="fy" class="form-select">
                        <?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="status">สถานะ</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">ทั้งหมด</option><option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>ระหว่างพิจารณา (ทุกขั้น)</option>
                        <?php foreach (hr_leave_status_labels() as $k => $label): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="type">ประเภท</label>
                    <select name="type" id="type" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (hr_leave_types() as $k => [$label]): ?><option value="<?= $k ?>" <?= $fType === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php if ($seeAll): ?>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                        <select name="dept" id="dept" class="form-select">
                            <option value="0">ทุกแผนก/งาน</option>
                            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                    <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-list-ul me-2"></i>ใบลาปีงบประมาณ <?= $fy ?><?= !$seeAll ? ' — ' . e($user['department_name'] ?? 'แผนกของท่าน') : '' ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable" data-order="[]">
                    <thead><tr><th>เลขที่</th><th>ผู้ลา</th><th>แผนก</th><th>ประเภท</th><th>วันที่ลา</th><th class="text-end">วัน</th><th>สถานะ</th><th class="no-sort"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?><?= $r['recorded_by'] ? ' <i class="bi bi-pencil-square text-muted" title="งานบุคลากรบันทึกแทน"></i>' : '' ?></td>
                            <td><?= e($r['user_name']) ?></td>
                            <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name']) ?: '-') ?></td>
                            <td><?= e(hr_leave_type_label($r['leave_type'])) ?></td>
                            <td class="text-nowrap" data-order="<?= e($r['start_date']) ?>"><?= e(hr_leave_range_text($r)) ?></td>
                            <td class="text-end"><?= hr_days((float)$r['days']) ?></td>
                            <td data-order="<?= e($r['status']) ?>"><?= hr_leave_status_badge($r['status']) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> ดู</a>
                                <a href="<?= e(url('hr/leave_print.php?id=' . (int)$r['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="พิมพ์ใบลา"><i class="bi bi-printer"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isHr): ?>
    <div class="modal fade" id="recordModal" tabindex="-1" aria-labelledby="recordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="get" action="<?= e(url('hr/leaves.php')) ?>" class="modal-content">
                <div class="modal-header"><h2 class="modal-title fs-5" id="recordModalLabel">บันทึกใบลาแทนบุคลากร</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="new" value="1">
                    <label class="form-label" for="for">บุคลากร</label>
                    <select class="form-select" name="for" id="for" required>
                        <option value="">-- เลือก --</option>
                        <?php foreach ($people as $pp): if ((int)$pp['id'] === $uid) continue; ?>
                            <option value="<?= (int)$pp['id'] ?>"><?= e($pp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">ใช้บันทึกใบลากระดาษ/ใบลาที่ได้รับอนุญาตแล้ว — ใบลาของท่านเองให้ยื่นที่เมนู “การลา”</div>
                </div>
                <div class="modal-footer"><button class="btn btn-accent">ถัดไป</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
