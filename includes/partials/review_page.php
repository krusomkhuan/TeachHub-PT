<?php
/**
 * ไฟล์: includes/partials/review_page.php
 * หน้าตรวจงาน (ใช้ร่วมกันทุกประเภทงาน ทั้ง admin และหัวหน้าแผนก)
 *
 * ตัวแปรที่ต้องเตรียมก่อน require:
 *   $user = require_role('admin') หรือ require_role('supervisor')
 *   $type = 'lesson_plan' | 'pa' | 'research'
 *
 * ขอบเขตข้อมูล:
 *   - admin      : ทุกแผนก
 *   - supervisor : เฉพาะครูในแผนกตนเอง และไม่รวมงานของตนเอง
 *
 * พารามิเตอร์: ?id= (ตรวจงาน), ?status=, ?round_id=, ?ay=, ?dept= (admin), ?q=
 */
$isAdmin = $user['role'] === 'admin';
// ผู้ตรวจตามตำแหน่ง (เช่น หัวหน้างานบุคลากร สำหรับ PA) เห็นงานประเภทนี้ทุกแผนก
$isDesignated = is_designated_reviewer($user, $type);
$scopeAll = $isAdmin || $isDesignated;
$deptScope = $scopeAll ? 0 : (int)$user['department_id'];
$self = review_page($user['role'], $type);
$typeLabel = submission_type_label($type);
$table = submission_table($type);
$errors = [];

// =====================================================================
// POST: บันทึกผลการตรวจ
// =====================================================================
if (is_post()) {
    csrf_verify();
    $sub = submission_fetch($type, (int)post('id'));
    if (!$sub || !can_review_submission($user, $sub)) {
        abort_page(403, 'ไม่มีสิทธิ์ตรวจงานนี้');
    }
    $result = post('result');
    $comment = mb_substr(post('comment'), 0, 5000);

    if (!in_array($result, ['approved', 'revision'], true)) {
        $errors['result'] = 'กรุณาเลือกผลการตรวจ';
    }
    if ($result === 'revision' && $comment === '') {
        $errors['comment'] = 'กรุณาระบุสิ่งที่ต้องแก้ไข เพื่อให้ครูแก้ได้ถูกต้อง';
    }
    if (!$isAdmin && $sub['status'] !== 'pending') {
        $errors['result'] = 'งานนี้ถูกตรวจแล้ว (เปลี่ยนผลได้เฉพาะผู้ดูแลระบบ)';
    }

    if (!$errors) {
        submission_review($type, $sub, $result, $comment, (int)$user['id']);
        log_activity('review_' . $type, ($result === 'approved' ? 'ผ่าน' : 'ให้แก้ไข') . ': ' . $sub['title'] . ' ของ ' . $sub['teacher_name']);
        flash('success', 'บันทึกผลการตรวจ "' . status_labels()[$result] . '" เรียบร้อยแล้ว');

        // ไปงานรอตรวจรายการถัดไปในขอบเขตเดียวกัน (ถ้ามี)
        $next = db_value(
            "SELECT s.id FROM `$table` s JOIN users u ON u.id = s.teacher_id
             WHERE s.status = 'pending' AND s.id <> ? AND (? = 0 OR u.department_id = ?) AND (? = 1 OR s.teacher_id <> ?)
             ORDER BY s.submitted_at LIMIT 1",
            'iiiii',
            [(int)$sub['id'], $deptScope, $deptScope, $isAdmin ? 1 : 0, (int)$user['id']]
        );
        redirect($self . ($next ? '?id=' . (int)$next : '?status=pending'));
    }
    $_GET['id'] = (string)$sub['id']; // แสดงหน้าเดิมพร้อมข้อผิดพลาด
}

// =====================================================================
// หน้าตรวจงาน (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $sub = submission_fetch($type, $viewId);
    if (!$sub || !can_view_submission($user, $sub) || (!$isAdmin && (int)$sub['teacher_id'] === (int)$user['id'])) {
        abort_page(404, 'ไม่พบงาน', 'งานนี้ไม่มีอยู่ หรืออยู่นอกขอบเขตที่ท่านตรวจได้');
    }
    $canReview = can_review_submission($user, $sub) && ($isAdmin || $sub['status'] === 'pending');

    $pageTitle = 'ตรวจ' . $typeLabel;
    $pageIcon = submission_type_icon($type);
    $breadcrumbs = [$typeLabel => $self, $sub['teacher_name'] => null];
    $activeMenu = $self;
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <?php require APP_ROOT . '/includes/partials/submission_detail.php'; ?>
        </div>
        <div class="col-xl-4">
            <div class="card mb-4 border-top border-4 border-warning">
                <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>บันทึกผลการตรวจ (ฉบับที่ <?= (int)$sub['current_version'] ?>)</div>
                <div class="card-body">
                    <?php if ($canReview): ?>
                        <?php if ($sub['status'] !== 'pending'): ?>
                            <div class="alert alert-info small">งานนี้ตรวจแล้ว (<?= e(status_labels()[$sub['status']]) ?>) การบันทึกใหม่จะเปลี่ยนผลการตรวจ</div>
                        <?php endif; ?>
                        <form method="post" novalidate data-confirm="ยืนยันบันทึกผลการตรวจ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                            <div class="mb-3">
                                <div class="btn-group w-100" role="group" aria-label="ผลการตรวจ">
                                    <input type="radio" class="btn-check" name="result" id="rApproved" value="approved" <?= post('result') === 'approved' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success" for="rApproved"><i class="bi bi-check-circle me-1"></i>ผ่าน</label>
                                    <input type="radio" class="btn-check" name="result" id="rRevision" value="revision" <?= post('result') === 'revision' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-danger" for="rRevision"><i class="bi bi-pencil-square me-1"></i>ให้แก้ไข</label>
                                </div>
                                <?php if (isset($errors['result'])): ?><div class="text-danger small mt-1"><?= e($errors['result']) ?></div><?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comment">ความเห็น / สิ่งที่ต้องแก้ไข</label>
                                <textarea class="form-control<?= isset($errors['comment']) ? ' is-invalid' : '' ?>" name="comment" id="comment" rows="6"
                                          maxlength="5000" placeholder="ระบุข้อเสนอแนะ (จำเป็นเมื่อเลือก &quot;ให้แก้ไข&quot;)"><?= old('comment') ?></textarea>
                                <?php if (isset($errors['comment'])): ?><div class="invalid-feedback"><?= e($errors['comment']) ?></div><?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-accent w-100" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกผลการตรวจ</button>
                        </form>
                    <?php else: ?>
                        <div class="text-muted"><i class="bi bi-lock me-1"></i>
                            <?= $sub['status'] === 'pending' ? 'ท่านไม่มีสิทธิ์ตรวจงานนี้' : 'งานนี้ตรวจแล้ว: ' . e(status_labels()[$sub['status']]) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>ติดต่อครูผู้ส่ง</div>
                <div class="card-body small">
                    <div class="fw-semibold"><?= e($sub['teacher_name']) ?></div>
                    <div><i class="bi bi-envelope me-1"></i><?= e($sub['teacher_email']) ?></div>
                    <div><i class="bi bi-telephone me-1"></i><?= e($sub['teacher_phone'] ?: '-') ?></div>
                </div>
            </div>
            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ
// =====================================================================
$fStatus = get('status');
$fRound = get_int('round_id');
$fAy = get_int('ay', (int)(current_academic_year()['id'] ?? 0));
$fDept = $scopeAll ? get_int('dept') : $deptScope;
if ($fRound > 0) {
    $fAy = 0; // ระบุรอบแล้วไม่ต้องกรองภาคเรียนซ้ำ
}

$where = ['1 = 1'];
$types = '';
$params = [];
if (in_array($fStatus, ['pending', 'approved', 'revision'], true)) {
    $where[] = 's.status = ?';
    $types .= 's';
    $params[] = $fStatus;
} elseif ($fStatus === 'late') {
    $where[] = 's.is_late = 1';
}
if ($fRound > 0) {
    $where[] = 's.round_id = ?';
    $types .= 'i';
    $params[] = $fRound;
}
if ($fAy > 0) {
    $where[] = 'r.academic_year_id = ?';
    $types .= 'i';
    $params[] = $fAy;
}
if ($fDept > 0 || !$scopeAll) {
    $where[] = 'u.department_id = ?';
    $types .= 'i';
    $params[] = $fDept;
}
if (!$isAdmin) {
    $where[] = 's.teacher_id <> ?';
    $types .= 'i';
    $params[] = (int)$user['id'];
}
$titleSql = submission_title_sql($type);
$rows = db_all(
    "SELECT s.id, s.status, s.is_late, s.current_version, s.submitted_at, s.updated_at, $titleSql AS title,
            r.name AS round_name, ay.year, ay.semester, d.name AS department_name,
            CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name
     FROM `$table` s
     JOIN submission_rounds r ON r.id = s.round_id
     LEFT JOIN academic_years ay ON ay.id = r.academic_year_id
     JOIN users u ON u.id = s.teacher_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(s.status, 'pending', 'revision', 'approved'), s.submitted_at",
    $types,
    $params
);

// นับจำนวนตามสถานะ (ในขอบเขตตัวกรองอื่น ๆ ยกเว้นสถานะ)
$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'revision' => 0, 'late' => 0];
$countWhere = array_values(array_filter($where, fn($w) => strpos($w, 's.status') === false && strpos($w, 's.is_late') === false));
$countTypes = in_array($fStatus, ['pending', 'approved', 'revision'], true) ? substr($types, 1) : $types;
$countParams = in_array($fStatus, ['pending', 'approved', 'revision'], true) ? array_slice($params, 1) : $params;
$c = db_one(
    "SELECT COUNT(*) AS c_all, COALESCE(SUM(s.status = 'pending'), 0) AS c_pending, COALESCE(SUM(s.status = 'approved'), 0) AS c_approved,
            COALESCE(SUM(s.status = 'revision'), 0) AS c_revision, COALESCE(SUM(s.is_late = 1), 0) AS c_late
     FROM `$table` s JOIN submission_rounds r ON r.id = s.round_id JOIN users u ON u.id = s.teacher_id
     WHERE " . implode(' AND ', $countWhere),
    $countTypes,
    $countParams
);
foreach ($counts as $k => $v) {
    $counts[$k] = (int)$c['c_' . $k];
}

$roundsList = db_all('SELECT id, name FROM submission_rounds WHERE type = ? ORDER BY close_at DESC', 's', [$type]);
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$departments = $scopeAll ? db_all('SELECT id, name FROM departments ORDER BY name') : [];
$q = function (array $over) use ($fStatus, $fRound, $fAy, $fDept, $scopeAll): string {
    $base = ['status' => $fStatus, 'round_id' => $fRound ?: null, 'ay' => $fAy, 'dept' => $scopeAll ? ($fDept ?: null) : null];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
};

$pageTitle = 'ตรวจ' . $typeLabel;
$pageIcon = submission_type_icon($type);
$breadcrumbs = [$typeLabel => null];
$activeMenu = $self;
require APP_ROOT . '/includes/header.php';
?>
<?php if ($isDesignated && !$isAdmin): ?>
    <div class="alert alert-light border small"><i class="bi bi-person-check me-1"></i>
        ท่านเป็นผู้ตรวจ<?= e(submission_type_label($type)) ?>ตามตำแหน่ง “<?= e($user['position']) ?>” — แสดงงานของครูทุกแผนก (ยกเว้นงานของท่านเอง)</div>
<?php elseif (!$isAdmin): ?>
    <div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>
        แสดงเฉพาะงานของครูใน<?= e($user['department_name'] ?? 'แผนก') ?> (งานของท่านเองจะตรวจโดยผู้ตรวจตามตำแหน่งหรือผู้ดูแลระบบ)</div>
<?php endif; ?>

<!-- ชิปสถานะ -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $chips = ['' => ['ทั้งหมด', 'all', 'btn-outline-primary'], 'pending' => ['รอตรวจ', 'pending', 'btn-outline-warning'],
        'revision' => ['ให้แก้ไข', 'revision', 'btn-outline-danger'], 'approved' => ['ผ่าน', 'approved', 'btn-outline-success'],
        'late' => ['ส่งช้า', 'late', 'btn-outline-secondary']];
    foreach ($chips as $k => [$label, $ck, $cls]): ?>
        <a href="<?= e($q(['status' => $k])) ?>" class="btn btn-sm <?= $fStatus === $k ? str_replace('outline-', '', $cls) : $cls ?>">
            <?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$ck] ?></span></a>
    <?php endforeach; ?>
</div>

<!-- ตัวกรอง -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="<?= e($fStatus) ?>">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <option value="0">ทุกภาคเรียน</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $fAy === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-<?= $scopeAll ? '4' : '7' ?>">
                <label class="form-label small mb-1" for="round_id">รอบการส่ง</label>
                <select name="round_id" id="round_id" class="form-select">
                    <option value="0">ทุกรอบ</option>
                    <?php foreach ($roundsList as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $fRound === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($scopeAll): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
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
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead>
                <tr><th>ครูผู้ส่ง</th><?php if ($scopeAll): ?><th>แผนก</th><?php endif; ?><th>งาน</th><th>รอบ</th><th>ฉบับ</th><th>สถานะ</th><th>ส่งเมื่อ</th><th class="no-sort"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['teacher_name']) ?></td>
                        <?php if ($scopeAll): ?><td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td><?php endif; ?>
                        <td><?= e($r['title']) ?></td>
                        <td class="small"><?= e($r['round_name']) ?></td>
                        <td class="text-center"><?= (int)$r['current_version'] ?></td>
                        <td data-order="<?= e($r['status']) ?>"><?= status_badge($r['status'], (bool)$r['is_late']) ?></td>
                        <td class="small" data-order="<?= e($r['submitted_at']) ?>"><?= e(thai_datetime($r['updated_at'] ?? $r['submitted_at'])) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm <?= $r['status'] === 'pending' ? 'btn-accent' : 'btn-outline-primary' ?> text-nowrap">
                                <?= $r['status'] === 'pending' ? '<i class="bi bi-clipboard-check"></i> ตรวจ' : '<i class="bi bi-eye"></i> ดู' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
