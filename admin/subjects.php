<?php
/**
 * ไฟล์: admin/subjects.php
 * จัดการรายวิชา (รหัส ชื่อ ท-ป-น ระดับชั้น แผนก) — ครูเลือกใช้ตอนส่งแผนการสอน/วิจัย/ตารางนิเทศ
 * ลบรายวิชาได้เสมอ เพราะงานที่ส่งแล้วเก็บรหัส/ชื่อวิชาไว้ในตัวงานเอง
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];
$departments = db_all('SELECT id, code, name FROM departments ORDER BY name');

// ไฟล์ตัวอย่างสำหรับนำเข้า (CSV UTF-8 เปิดด้วย Excel ได้)
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subjects_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['รหัสวิชา', 'ชื่อวิชา', 'ท-ป-น', 'ระดับชั้น', 'แผนก']);
    fputcsv($out, ['20104-2101', 'วงจรไฟฟ้ากระแสตรง', '1-3-2', 'ปวช.', 'POWER']);
    fputcsv($out, ['30104-2001', 'การออกแบบระบบไฟฟ้า', '2-3-3', 'ปวส.', 'ช่างไฟฟ้ากำลัง']);
    fclose($out);
    exit;
}

/**
 * อ่านไฟล์ .csv / .xlsx (ชีตแรก) เป็นอาร์เรย์ของแถว
 * @return array<int, array<int, string>>|null null = อ่านไฟล์ไม่ได้
 */
function subjects_read_file(string $path, string $ext): ?array
{
    $rows = [];
    if ($ext === 'csv') {
        $fh = fopen($path, 'r');
        if (!$fh) {
            return null;
        }
        while (($r = fgetcsv($fh)) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        if ($rows && isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$rows[0][0]);
        }
        // ไฟล์ที่ Excel บันทึกเป็น CSV แบบ ANSI (TIS-620/Windows-874)
        foreach ($rows as &$r) {
            foreach ($r as &$c) {
                $c = (string)$c;
                if (!mb_check_encoding($c, 'UTF-8')) {
                    $c = (string)@iconv('Windows-874', 'UTF-8//IGNORE', $c);
                }
            }
        }
        unset($r, $c);
        return $rows;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($xml);
        foreach ($sx->si ?? [] as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r ?? [] as $run) {
                $t .= (string)$run->t;
            }
            $shared[] = $t;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    $sx = $sheet !== false ? @simplexml_load_string($sheet) : false;
    if (!$sx) {
        return null;
    }
    foreach ($sx->sheetData->row ?? [] as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', (string)$c['r'])) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $type = (string)$c['t'];
            if ($type === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = (string)$c->v;
            }
            $r[max(0, $col - 1)] = $v;
        }
        if ($r) {
            $r += array_fill(0, max(array_keys($r)) + 1, '');
            ksort($r);
            $rows[max(0, (int)$row['r'] - 1)] = $r; // คีย์ = เลขแถวใน Excel - 1 (ใช้แจ้งแถวที่ผิด)
        }
    }
    return $rows;
}

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'import') {
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
        $rows = null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'กรุณาเลือกไฟล์ที่จะนำเข้า');
        } elseif (!in_array($ext, ['csv', 'xlsx'], true)) {
            flash('error', 'รองรับเฉพาะไฟล์ .csv หรือ .xlsx');
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            flash('error', 'ไฟล์ใหญ่เกิน 2 MB');
        } elseif (($rows = subjects_read_file($f['tmp_name'], $ext)) === null) {
            flash('error', 'อ่านไฟล์ไม่ได้ กรุณาใช้ไฟล์ตามแบบฟอร์มตัวอย่าง');
        }
        if ($rows !== null) {
            // ค้นแผนกได้ทั้ง id / รหัสแผนก / ชื่อเต็ม / ชื่อไม่มีคำว่า "แผนกวิชา"
            $deptMap = [];
            foreach ($departments as $d) {
                foreach ([(string)$d['id'], strtoupper((string)$d['code']), $d['name'], preg_replace('/^แผนกวิชา/u', '', $d['name'])] as $k) {
                    $deptMap[mb_strtolower(trim($k))] = (int)$d['id'];
                }
            }
            $update = isset($_POST['update_existing']);
            $added = $updated = $skipped = 0;
            $rowErrors = [];
            foreach ($rows as $i => $r) {
                $r = array_map(fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v)), $r);
                $line = $i + 1;
                if (implode('', $r) === '') {
                    continue;
                }
                $code = strtoupper($r[0] ?? '');
                if ($i === 0 && !preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
                    continue; // แถวหัวตาราง
                }
                $name = mb_substr($r[1] ?? '', 0, 200);
                $credit = $r[2] ?? '';
                $level = rtrim($r[3] ?? '', '.') . '.';
                $deptId = $deptMap[mb_strtolower($r[4] ?? '')] ?? 0;
                $problem = match (true) {
                    !preg_match('/^[A-Z0-9-]{2,20}$/', $code) => 'รหัสวิชาไม่ถูกต้อง',
                    $name === ''                              => 'ไม่มีชื่อวิชา',
                    $credit !== '' && !preg_match('/^\d{1,2}-\d{1,2}-\d{1,2}$/', $credit) => 'ท-ป-น ไม่ถูกต้อง (' . $credit . ')',
                    !in_array($level, ['ปวช.', 'ปวส.'], true) => 'ระดับชั้นต้องเป็น ปวช. หรือ ปวส.',
                    $deptId === 0                             => 'ไม่พบแผนก "' . ($r[4] ?? '') . '"',
                    default                                   => null,
                };
                if ($problem) {
                    $rowErrors[] = 'แถว ' . $line . ($code !== '' ? ' (' . $code . ')' : '') . ': ' . $problem;
                    continue;
                }
                $existing = db_value('SELECT id FROM subjects WHERE code = ?', 's', [$code]);
                if ($existing) {
                    if (!$update) {
                        $skipped++;
                        continue;
                    }
                    db_exec('UPDATE subjects SET name = ?, credit = ?, level = ?, department_id = ? WHERE id = ?',
                        'sssii', [$name, $credit, $level, $deptId, (int)$existing]);
                    $updated++;
                } else {
                    db_insert('INSERT INTO subjects (code, name, credit, level, department_id, is_active) VALUES (?, ?, ?, ?, ?, 1)',
                        'ssssi', [$code, $name, $credit, $level, $deptId]);
                    $added++;
                }
            }
            log_activity('subject_import', 'นำเข้ารายวิชา เพิ่ม ' . $added . ' แก้ไข ' . $updated . ' ข้าม ' . $skipped . ' ผิดพลาด ' . count($rowErrors));
            $msg = 'นำเข้ารายวิชา: เพิ่มใหม่ ' . $added . ' · ปรับปรุง ' . $updated . ' · ข้าม (รหัสซ้ำ) ' . $skipped . ' · ผิดพลาด ' . count($rowErrors);
            if ($rowErrors) {
                $msg .= "\n\n" . implode("\n", array_slice($rowErrors, 0, 15)) . (count($rowErrors) > 15 ? "\n… และอีก " . (count($rowErrors) - 15) . ' แถว' : '');
            }
            flash($rowErrors ? 'important' : 'success', $msg);
        }
        redirect('admin/subjects.php');
    }

    if ($action === 'save') {
        $d = [
            'code'          => strtoupper(post('code')),
            'name'          => mb_substr(post('name'), 0, 200),
            'credit'        => post('credit'),
            'level'         => post('level'),
            'department_id' => (int)post('department_id'),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        ];
        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $d['code'])) {
            $errors['code'] = 'รหัสวิชาไม่ถูกต้อง เช่น 20104-2101';
        } elseif (db_value('SELECT id FROM subjects WHERE code = ? AND id <> ?', 'si', [$d['code'], $id])) {
            $errors['code'] = 'รหัสวิชานี้มีแล้ว';
        }
        if ($d['name'] === '') {
            $errors['name'] = 'กรุณากรอกชื่อวิชา';
        }
        if ($d['credit'] !== '' && !preg_match('/^\d{1,2}-\d{1,2}-\d{1,2}$/', $d['credit'])) {
            $errors['credit'] = 'รูปแบบ ท-ป-น เช่น 1-3-2';
        }
        if (!in_array($d['level'], ['ปวช.', 'ปวส.'], true)) {
            $errors['level'] = 'กรุณาเลือกระดับชั้น';
        }
        if (!in_array($d['department_id'], array_map('intval', array_column($departments, 'id')), true)) {
            $errors['department_id'] = 'กรุณาเลือกแผนก';
        }
        if (!$errors) {
            if ($id) {
                db_exec('UPDATE subjects SET code = ?, name = ?, credit = ?, level = ?, department_id = ?, is_active = ? WHERE id = ?',
                    'ssssiii', [$d['code'], $d['name'], $d['credit'], $d['level'], $d['department_id'], $d['is_active'], $id]);
            } else {
                db_insert('INSERT INTO subjects (code, name, credit, level, department_id, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    'ssssii', [$d['code'], $d['name'], $d['credit'], $d['level'], $d['department_id'], $d['is_active']]);
            }
            log_activity('subject_save', ($id ? 'แก้ไข' : 'เพิ่ม') . 'รายวิชา ' . $d['code'] . ' ' . $d['name']);
            flash('success', 'บันทึกรายวิชา ' . $d['code'] . ' แล้ว');
            redirect('admin/subjects.php' . ($id ? '' : '?new=1'));
        }
    }
    if ($action === 'delete') {
        $s = db_one('SELECT code, name FROM subjects WHERE id = ?', 'i', [$id]);
        if ($s) {
            db_exec('DELETE FROM subjects WHERE id = ?', 'i', [$id]);
            log_activity('subject_delete', 'ลบรายวิชา ' . $s['code'] . ' ' . $s['name']);
            flash('success', 'ลบรายวิชา ' . $s['code'] . ' แล้ว');
        }
        redirect('admin/subjects.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM subjects WHERE id = ?', 'i', [$editId]) : null;
$fDept = get_int('dept');
$rows = db_all(
    'SELECT s.*, d.name AS department_name,
        (SELECT COUNT(*) FROM lesson_plans lp WHERE lp.subject_code = s.code) AS plans
     FROM subjects s LEFT JOIN departments d ON d.id = s.department_id
     WHERE (? = 0 OR s.department_id = ?) ORDER BY s.code',
    'ii',
    [$fDept, $fDept]
);
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

$pageTitle = 'รายวิชา';
$pageIcon = 'bi-book';
$breadcrumbs = ['รายวิชา' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขรายวิชา' : 'เพิ่มรายวิชา' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-7">
                            <label class="form-label" for="code">รหัสวิชา *</label>
                            <input class="form-control<?= $invalid('code') ?>" name="code" id="code" maxlength="20" value="<?= e($fv('code')) ?>" placeholder="20104-2101"><?= $err('code') ?>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="credit">ท-ป-น</label>
                            <input class="form-control<?= $invalid('credit') ?>" name="credit" id="credit" maxlength="20" value="<?= e($fv('credit')) ?>" placeholder="1-3-2"><?= $err('credit') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="name">ชื่อวิชา *</label>
                            <input class="form-control<?= $invalid('name') ?>" name="name" id="name" maxlength="200" value="<?= e($fv('name')) ?>"><?= $err('name') ?>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="level">ระดับชั้น *</label>
                            <select class="form-select<?= $invalid('level') ?>" name="level" id="level">
                                <?php foreach (['ปวช.', 'ปวส.'] as $lv): ?><option value="<?= e($lv) ?>" <?= $fv('level', 'ปวช.') === $lv ? 'selected' : '' ?>><?= e($lv) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-7">
                            <label class="form-label" for="department_id">แผนกวิชา *</label>
                            <select class="form-select<?= $invalid('department_id') ?>" name="department_id" id="department_id">
                                <option value="">-- เลือก --</option>
                                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$fv('department_id', (string)$fDept) === (int)$d['id'] ? 'selected' : '' ?>><?= e(preg_replace('/^แผนกวิชา/u', '', $d['name'])) ?></option><?php endforeach; ?>
                            </select><?= $err('department_id') ?>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <?php if ($editing): ?><a href="<?= e(url('admin/subjects.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header"><i class="bi bi-file-earmark-arrow-up me-2"></i>นำเข้ารายวิชาจากไฟล์</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import">
                    <input type="file" class="form-control" name="file" accept=".csv,.xlsx" required aria-label="ไฟล์รายวิชา">
                    <div class="form-text">ไฟล์ .xlsx หรือ .csv คอลัมน์: รหัสวิชา · ชื่อวิชา · ท-ป-น · ระดับชั้น (ปวช./ปวส.) · แผนก (ชื่อหรือรหัสแผนก เช่น POWER) — ตั้งคอลัมน์ ท-ป-น เป็น "ข้อความ" ใน Excel เพื่อไม่ให้กลายเป็นวันที่</div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="update_existing" id="update_existing" value="1" checked>
                        <label class="form-check-label" for="update_existing">ปรับปรุงรายวิชาที่มีรหัสอยู่แล้ว</label>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <a href="?template=1" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i> ไฟล์ตัวอย่าง</a>
                        <button class="btn btn-primary flex-fill"><i class="bi bi-upload me-1"></i> นำเข้า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span><i class="bi bi-list-ul me-2"></i>รายวิชา (<?= count($rows) ?>)</span>
                <form method="get" class="ms-auto">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="กรองแผนก">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" data-order="[]">
                        <thead><tr><th>รหัส</th><th>ชื่อวิชา</th><th>ท-ป-น</th><th>ระดับ</th><th>แผนก</th><th>แผนที่ส่ง</th><th class="no-sort text-end"></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
                                <td class="text-nowrap"><?= e($r['code']) ?></td>
                                <td><?= e($r['name']) ?><?= $r['is_active'] ? '' : ' <span class="badge bg-secondary">ปิด</span>' ?></td>
                                <td><?= e($r['credit']) ?></td>
                                <td><?= e($r['level']) ?></td>
                                <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td>
                                <td><?= (int)$r['plans'] ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <form method="post" class="d-inline" data-confirm="ลบรายวิชา <?= e($r['code']) ?>? (งานที่ส่งแล้วยังเก็บชื่อวิชาไว้)" data-confirm-icon="warning">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
