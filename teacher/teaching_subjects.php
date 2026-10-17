<?php
/**
 * ไฟล์: teacher/teaching_subjects.php
 * รายวิชาที่สอนในแต่ละภาคเรียน (ครู และหัวหน้าแผนกที่สอน)
 *   - ครูบันทึกรายวิชาที่สอนก่อน แล้วจึงส่งโครงการสอน/แผนการจัดการเรียนรู้ของวิชาเหล่านี้
 *   - เกณฑ์: ส่งโครงการสอนครบทุกรายวิชา + ส่งแผนการจัดการเรียนรู้อย่างน้อย 1 วิชา
 *   ?ay=xx เลือกภาคเรียน (ค่าเริ่มต้น = ภาคเรียนปัจจุบัน)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$errors = [];
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ayIds = array_map('intval', array_column($years, 'id'));
$ay = (int)(post('academic_year_id') ?: get_int('ay', (int)(current_academic_year()['id'] ?? 0)));
if (!in_array($ay, $ayIds, true)) {
    $ay = $ayIds[0] ?? 0;
}
$self = 'teacher/teaching_subjects.php';

if (is_post()) {
    csrf_verify();
    $action = post('action');

    if ($action === 'add') {
        $code = strtoupper(post('subject_code'));
        $name = mb_substr(post('subject_name'), 0, 200);
        $level = post('level');
        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
            $errors['subject_code'] = 'รหัสวิชาไม่ถูกต้อง (ตัวเลข/ตัวอักษรอังกฤษ/ขีด 2-20 ตัว)';
        }
        if ($name === '') {
            $errors['subject_name'] = 'กรุณากรอกชื่อวิชา';
        }
        if (!in_array($level, ['ปวช.', 'ปวส.'], true)) {
            $errors['level'] = 'กรุณาเลือกระดับชั้น';
        }
        if (!$errors && db_value(
            'SELECT id FROM teaching_subjects WHERE teacher_id = ? AND academic_year_id = ? AND subject_code = ?',
            'iis',
            [$uid, $ay, $code]
        )) {
            $errors['subject_code'] = 'มีรายวิชานี้ในภาคเรียนนี้แล้ว';
        }
        if (!$errors) {
            db_insert(
                'INSERT INTO teaching_subjects (teacher_id, academic_year_id, subject_code, subject_name, level) VALUES (?, ?, ?, ?, ?)',
                'iisss',
                [$uid, $ay, $code, $name, $level]
            );
            log_activity('teaching_subject_add', 'เพิ่มรายวิชาที่สอน ' . $code . ' ' . $name);
            flash('success', 'เพิ่มรายวิชา ' . $code . ' เรียบร้อยแล้ว');
            redirect($self . '?ay=' . $ay);
        }
    }

    if ($action === 'delete') {
        $row = db_one('SELECT * FROM teaching_subjects WHERE id = ? AND teacher_id = ?', 'ii', [(int)post('id'), $uid]);
        if (!$row) {
            abort_page(404, 'ไม่พบรายวิชา');
        }
        $used = db_value(
            'SELECT id FROM lesson_plans WHERE teacher_id = ? AND academic_year_id = ? AND subject_code = ? LIMIT 1',
            'iis',
            [$uid, (int)$row['academic_year_id'], $row['subject_code']]
        );
        if ($used) {
            flash('error', 'ลบไม่ได้ เพราะส่งโครงการสอน/แผนการจัดการเรียนรู้ของวิชานี้แล้ว');
        } else {
            db_exec('DELETE FROM teaching_subjects WHERE id = ?', 'i', [(int)$row['id']]);
            log_activity('teaching_subject_delete', 'ลบรายวิชาที่สอน ' . $row['subject_code']);
            flash('success', 'ลบรายวิชา ' . $row['subject_code'] . ' แล้ว');
        }
        redirect($self . '?ay=' . (int)$row['academic_year_id']);
    }
}

$rows = teaching_subjects_with_status($uid, $ay);
$comp = teaching_compliance($uid, $ay);
$ayRow = null;
foreach ($years as $y) {
    if ((int)$y['id'] === $ay) {
        $ayRow = $y;
    }
}
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$docBadge = function (?string $status, string $doc = ''): string {
    if ($status !== null) {
        return status_badge($status, false);
    }
    $badge = '<span class="badge bg-light text-muted border">ยังไม่ส่ง</span>';
    return $doc === '' ? $badge : $badge . ' <a href="' . e(url('teacher/lesson_plans.php?doc=' . $doc . '#submitForm')) . '" class="small">ส่ง</a>';
};

$pageTitle = 'รายวิชาที่สอน';
$pageIcon = 'bi-book';
$breadcrumbs = ['รายวิชาที่สอน' => null];
$activeMenu = $self;
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-sm-8">
                        <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                        <select name="ay" id="ay" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y['id'] ?>" <?= $ay === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?><?= (int)$y['is_current'] === 1 ? ' (ปัจจุบัน)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> แสดง</button></div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>รายวิชาที่สอน ภาคเรียน <?= e(academic_label($ayRow)) ?> (<?= count($rows) ?> วิชา)</div>
            <div class="card-body">
                <?php if (!$rows): ?>
                    <div class="empty-state"><i class="bi bi-book"></i>ยังไม่ได้บันทึกรายวิชาที่สอนในภาคเรียนนี้ — เพิ่มรายวิชาที่ฟอร์มด้านขวา</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>รหัส/ชื่อวิชา</th><th>ระดับ</th><th>โครงการสอน</th><th>แผนการจัดการเรียนรู้</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><div class="fw-semibold"><?= e($r['subject_code']) ?></div><small><?= e($r['subject_name']) ?></small></td>
                                    <td><?= e($r['level']) ?></td>
                                    <td><?= $r['outline_id'] ? '<a href="' . e(url('teacher/lesson_plans.php?id=' . $r['outline_id'])) . '">' . $docBadge($r['outline_status']) . '</a>' : $docBadge(null, 'outline') ?></td>
                                    <td><?= $r['plan_id'] ? '<a href="' . e(url('teacher/lesson_plans.php?id=' . $r['plan_id'])) . '">' . $docBadge($r['plan_status']) . '</a>' : $docBadge(null, 'plan') ?></td>
                                    <td class="text-end">
                                        <?php if (!$r['outline_id'] && !$r['plan_id']): ?>
                                            <form method="post" class="d-inline" data-confirm="ลบรายวิชา <?= e($r['subject_code']) ?> ออกจากรายวิชาที่สอน?" data-confirm-icon="warning">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <a href="<?= e(url('teacher/lesson_plans.php')) ?>" class="btn btn-accent"><i class="bi bi-upload me-1"></i> ไปส่งโครงการสอน / แผนการจัดการเรียนรู้</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4 border-top border-4 <?= $comp['ok'] ? 'border-success' : 'border-warning' ?>">
            <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>เกณฑ์การส่งภาคเรียนนี้</div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex align-items-center">
                    <span>โครงการสอนครบทุกรายวิชา<br><span class="text-muted">ส่งแล้ว <?= $comp['outlines'] ?>/<?= $comp['subjects'] ?> วิชา</span></span>
                    <span class="ms-auto"><?= compliance_badge($comp['outline_ok'], 'ครบ', 'ยังไม่ครบ') ?></span>
                </li>
                <li class="list-group-item d-flex align-items-center">
                    <span>แผนการจัดการเรียนรู้อย่างน้อย 1 วิชา<br><span class="text-muted">ส่งแล้ว <?= $comp['plans'] ?> วิชา (ผ่าน <?= $comp['plans_approved'] ?>)</span></span>
                    <span class="ms-auto"><?= compliance_badge($comp['plan_ok'], 'ครบ', 'ยังไม่ส่ง') ?></span>
                </li>
                <li class="list-group-item d-flex align-items-center fw-semibold">
                    ผลรวม <span class="ms-auto"><?= compliance_badge($comp['ok']) ?></span>
                </li>
            </ul>
            <div class="card-body small text-muted">
                รายวิชาที่หัวหน้าแผนกวิชาและนักเรียน นักศึกษาประเมิน คือรายวิชาที่แผนการจัดการเรียนรู้ “ผ่าน” แล้ว
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>เพิ่มรายวิชาที่สอน</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="academic_year_id" value="<?= $ay ?>">
                    <div class="mb-3">
                        <label class="form-label" for="subject_code">รหัสวิชา *</label>
                        <input class="form-control<?= $invalid('subject_code') ?>" name="subject_code" id="subject_code" maxlength="20" value="<?= old('subject_code') ?>" placeholder="เช่น 20104-2101"><?= $err('subject_code') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="subject_name">ชื่อวิชา *</label>
                        <input class="form-control<?= $invalid('subject_name') ?>" name="subject_name" id="subject_name" maxlength="200" value="<?= old('subject_name') ?>"><?= $err('subject_name') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="level">ระดับชั้น *</label>
                        <select class="form-select<?= $invalid('level') ?>" name="level" id="level">
                            <option value="">-- เลือก --</option>
                            <?php foreach (['ปวช.', 'ปวส.'] as $lv): ?>
                                <option value="<?= e($lv) ?>" <?= post('level') === $lv ? 'selected' : '' ?>><?= e($lv) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('level') ?>
                    </div>
                    <button type="submit" class="btn btn-accent w-100"><i class="bi bi-plus-lg me-1"></i> เพิ่มรายวิชา (ภาคเรียน <?= e(academic_label($ayRow)) ?>)</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
