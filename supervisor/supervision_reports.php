<?php
/**
 * ไฟล์: supervisor/supervision_reports.php
 * รายงานการนิเทศครูผู้สอน (หัวหน้าแผนกวิชาเป็นผู้จัดทำ)
 *   (ไม่มี)      รายการรายงานของแผนก
 *   ?new=1      จัดทำรายงานใหม่ (บันทึกเป็นร่าง)
 *   ?edit=id    แก้ไขร่าง/รายงานที่ถูกส่งกลับ
 * การดูรายละเอียด เสนอ และพิจารณา อยู่ที่ reports/supervision_report.php?id=
 * เสนอตามลำดับ: หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้ → รองผู้อำนวยการฝ่ายวิชาการ → ผู้อำนวยการ
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
$uid = (int)$user['id'];
$deptId = (int)$user['department_id'];
$errors = [];
if (!$deptId) {
    abort_page(403, 'ยังไม่ได้กำหนดแผนกของท่าน', 'กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกวิชา');
}

// =====================================================================
// POST: บันทึกร่าง / ลบร่าง
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');
    $old = $id ? report_fetch($id) : null;
    if ($id && (!$old || !can_edit_report($user, $old))) {
        abort_page(403, 'ไม่สามารถแก้ไขรายงานนี้ได้', 'แก้ไขได้เฉพาะผู้จัดทำ และเฉพาะรายงานที่เป็นร่างหรือถูกส่งกลับแก้ไข');
    }

    if ($action === 'delete' && $old) {
        if ($old['submitted_at'] !== null) {
            flash('error', 'รายงานที่เคยเสนอแล้วลบไม่ได้ (มีประวัติการพิจารณา)');
        } else {
            db_exec('DELETE FROM supervision_reports WHERE id = ?', 'i', [$id]);
            log_activity('report_delete', 'ลบร่างรายงานการนิเทศ #' . $id);
            flash('success', 'ลบร่างรายงานแล้ว');
        }
        redirect('supervisor/supervision_reports.php');
    }

    if ($action === 'save') {
        $d = [
            'academic_year_id' => (int)post('academic_year_id'),
            'title'            => mb_substr(post('title'), 0, 255),
            'period_start'     => post('period_start'),
            'period_end'       => post('period_end'),
            'summary_overall'  => mb_substr(post('summary_overall'), 0, 10000),
            'strengths'        => mb_substr(post('strengths'), 0, 10000),
            'problems'         => mb_substr(post('problems'), 0, 10000),
            'suggestions'      => mb_substr(post('suggestions'), 0, 10000),
        ];
        if (!db_value('SELECT id FROM academic_years WHERE id = ?', 'i', [$d['academic_year_id']])) {
            $errors['academic_year_id'] = 'กรุณาเลือกภาคเรียน';
        }
        if ($d['title'] === '') {
            $errors['title'] = 'กรุณาระบุเรื่อง';
        }
        foreach (['period_start', 'period_end'] as $k) {
            if ($d[$k] !== '' && !DateTime::createFromFormat('Y-m-d', $d[$k])) {
                $errors['period'] = 'รูปแบบวันที่ไม่ถูกต้อง';
            }
        }
        if ($d['period_start'] !== '' && $d['period_end'] !== '' && $d['period_end'] < $d['period_start']) {
            $errors['period'] = 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น';
        }
        if (!$errors) {
            $params = [$d['academic_year_id'], $d['title'], $d['period_start'] ?: null, $d['period_end'] ?: null,
                $d['summary_overall'], $d['strengths'], $d['problems'], $d['suggestions']];
            if ($old) {
                db_exec(
                    'UPDATE supervision_reports SET academic_year_id = ?, title = ?, period_start = ?, period_end = ?,
                            summary_overall = ?, strengths = ?, problems = ?, suggestions = ? WHERE id = ?',
                    'isssssssi',
                    array_merge($params, [$id])
                );
                log_activity('report_update', 'แก้ไขรายงานการนิเทศ #' . $id);
                flash('success', 'บันทึกรายงานแล้ว ตรวจสอบความถูกต้องแล้วกด "เสนอรายงาน"');
            } else {
                $id = db_insert(
                    'INSERT INTO supervision_reports (academic_year_id, title, period_start, period_end, summary_overall, strengths,
                                                      problems, suggestions, department_id, author_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'isssssssii',
                    array_merge($params, [$deptId, $uid])
                );
                log_activity('report_create', 'จัดทำรายงานการนิเทศ #' . $id . ': ' . $d['title']);
                flash('success', 'บันทึกร่างรายงานแล้ว ตรวจสอบความถูกต้องแล้วกด "เสนอรายงาน"');
            }
            redirect('reports/supervision_report.php?id=' . $id);
        }
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$activeMenu = 'supervisor/supervision_reports.php';
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');

// =====================================================================
// ฟอร์มจัดทำ/แก้ไข
// =====================================================================
if (get('new') === '1' || get_int('edit') > 0 || post('action') === 'save') {
    $editId = get_int('edit') ?: (int)post('id');
    $editing = $editId ? report_fetch($editId) : null;
    if ($editId && (!$editing || !can_edit_report($user, $editing))) {
        abort_page(403, 'ไม่สามารถแก้ไขรายงานนี้ได้');
    }
    $current = current_academic_year();
    $deptName = preg_replace('/^แผนกวิชา/u', '', (string)($user['department_name'] ?? ''));
    $defaultTitle = 'รายงานผลการนิเทศการสอนครูผู้สอน แผนกวิชา' . $deptName . ' ภาคเรียนที่ ' . academic_label($current);
    $fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);
    $lastReturn = $editing && $editing['status'] === 'returned' ? db_one(
        "SELECT * FROM supervision_report_actions WHERE report_id = ? AND action = 'return' ORDER BY id DESC LIMIT 1",
        'i',
        [(int)$editing['id']]
    ) : null;

    $pageTitle = $editing ? 'แก้ไขรายงานการนิเทศ' : 'จัดทำรายงานการนิเทศครูผู้สอน';
    $pageIcon = 'bi-file-earmark-text';
    $breadcrumbs = ['รายงานการนิเทศ' => 'supervisor/supervision_reports.php', $pageTitle => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($lastReturn): ?>
        <div class="alert alert-danger">
            <div class="fw-bold"><i class="bi bi-arrow-return-left me-1"></i>ส่งกลับแก้ไขโดย <?= e($lastReturn['position']) ?></div>
            <?= nl2br(e((string)$lastReturn['comment'])) ?>
        </div>
    <?php endif; ?>
    <div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
        ระบบดึง <strong>ผลการนิเทศของครูในแผนก</strong> (ตารางคะแนนรายครู รายด้าน รายการนิเทศ และภาพถ่าย) มาใส่ในรายงานให้อัตโนมัติ
        ท่านเขียนเฉพาะสรุปผลและข้อเสนอแนะ แล้วเสนอตามลำดับ: หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้ → รองผู้อำนวยการฝ่ายวิชาการ → ผู้อำนวยการ</div>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-card-heading me-2"></i>ข้อมูลรายงาน</div>
            <div class="card-body row g-3">
                <div class="col-md-12">
                    <label class="form-label" for="title">เรื่อง *</label>
                    <input class="form-control<?= $invalid('title') ?>" name="title" id="title" maxlength="255" value="<?= e($fv('title', $defaultTitle)) ?>"><?= $err('title') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="academic_year_id">ภาคเรียน *</label>
                    <select class="form-select<?= $invalid('academic_year_id') ?>" name="academic_year_id" id="academic_year_id">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= (int)$y['id'] ?>" <?= (int)$fv('academic_year_id', (string)($current['id'] ?? '')) === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                        <?php endforeach; ?>
                    </select><?= $err('academic_year_id') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="period_start">นิเทศตั้งแต่วันที่ <span class="text-muted small">(ไม่บังคับ)</span></label>
                    <input type="date" class="form-control<?= $invalid('period') ?>" name="period_start" id="period_start" value="<?= e($fv('period_start')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="period_end">ถึงวันที่ <span class="text-muted small">(ไม่บังคับ)</span></label>
                    <input type="date" class="form-control<?= $invalid('period') ?>" name="period_end" id="period_end" value="<?= e($fv('period_end')) ?>">
                </div>
                <?php if (isset($errors['period'])): ?><div class="col-12 mt-0"><?= $err('period') ?></div><?php endif; ?>
                <div class="col-12 small text-muted mt-0">เว้นว่างวันที่ = นำผลการนิเทศทั้งภาคเรียนมารายงาน</div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>สรุปผลโดยหัวหน้าแผนก</div>
            <div class="card-body row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold" for="summary_overall">สรุปผลการนิเทศโดยภาพรวม <span class="text-muted small fw-normal">(จำเป็นก่อนเสนอ)</span></label>
                    <textarea class="form-control" name="summary_overall" id="summary_overall" rows="5" maxlength="10000"
                              placeholder="เช่น ครูในแผนกได้รับการนิเทศครบทุกคน ผลการนิเทศโดยรวมอยู่ในระดับดี ..."><?= e($fv('summary_overall')) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="strengths">จุดเด่น</label>
                    <textarea class="form-control" name="strengths" id="strengths" rows="5" maxlength="10000"><?= e($fv('strengths')) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="problems">ปัญหา/อุปสรรค</label>
                    <textarea class="form-control" name="problems" id="problems" rows="5" maxlength="10000"><?= e($fv('problems')) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="suggestions">ข้อเสนอแนะ/แนวทางพัฒนา <span class="text-muted small fw-normal">(จำเป็นก่อนเสนอ)</span></label>
                    <textarea class="form-control" name="suggestions" id="suggestions" rows="5" maxlength="10000"><?= e($fv('suggestions')) ?></textarea>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 justify-content-end">
            <a href="<?= e(url($editing ? 'reports/supervision_report.php?id=' . (int)$editing['id'] : 'supervisor/supervision_reports.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button type="submit" class="btn btn-accent px-4"><i class="bi bi-save me-1"></i> บันทึกและดูตัวอย่างรายงาน</button>
        </div>
    </form>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการรายงานของแผนก
// =====================================================================
$rows = db_all(
    "SELECT r.*, ay.year, ay.semester, CONCAT(a.first_name, ' ', a.last_name) AS author_name
     FROM supervision_reports r JOIN academic_years ay ON ay.id = r.academic_year_id JOIN users a ON a.id = r.author_id
     WHERE r.department_id = ? ORDER BY r.created_at DESC",
    'i',
    [$deptId]
);
$pageTitle = 'รายงานการนิเทศครูผู้สอน';
$pageIcon = 'bi-file-earmark-text';
$breadcrumbs = ['รายงานการนิเทศ' => null];
$pageActions = '<a href="' . e(url('supervisor/supervision_reports.php?new=1')) . '" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i>จัดทำรายงาน</a>';
require APP_ROOT . '/includes/header.php';
?>
<?php if (!report_chain_ready()): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
        ผู้ดูแลระบบยังกำหนดผู้พิจารณาไม่ครบ ท่านจัดทำร่างได้ แต่ยังเสนอรายงานไม่ได้</div>
<?php endif; ?>
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-2 small">
        <span class="fw-semibold me-2">ลำดับการเสนอ:</span>
        <span class="badge bg-primary-th">หัวหน้าแผนก (จัดทำ)</span><i class="bi bi-arrow-right"></i>
        <?php foreach (report_approval_chain() as $i => $s): ?>
            <span class="badge bg-light text-dark border"><?= $i ?>. <?= e($s['title']) ?><?= $s['name'] ? ' (' . e($s['name']) . ')' : '' ?></span>
            <?php if ($i < 3): ?><i class="bi bi-arrow-right"></i><?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>เรื่อง</th><th>ภาคเรียน</th><th>ผู้จัดทำ</th><th>สถานะ</th><th>เสนอเมื่อ</th><th class="no-sort text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['title']) ?></td>
                        <td><?= e($r['semester'] . '/' . $r['year']) ?></td>
                        <td class="small"><?= e($r['author_name']) ?></td>
                        <td><?= report_status_badge($r) ?></td>
                        <td class="small" data-order="<?= e((string)$r['submitted_at']) ?>"><?= e(thai_datetime($r['submitted_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url('reports/supervision_report.php?id=' . (int)$r['id'])) ?>" class="btn btn-sm <?= $r['status'] === 'returned' ? 'btn-danger' : 'btn-outline-primary' ?>">
                                <i class="bi bi-eye"></i> <?= $r['status'] === 'returned' ? 'แก้ไข/เสนอใหม่' : 'ดู' ?></a>
                            <?php if ($r['status'] === 'draft' && $r['submitted_at'] === null && (int)$r['author_id'] === $uid): ?>
                                <form method="post" class="d-inline" data-confirm="ลบร่างรายงานนี้?" data-confirm-icon="warning">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
