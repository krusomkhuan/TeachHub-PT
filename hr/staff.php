<?php
/**
 * ไฟล์: hr/staff.php
 * ทะเบียนประวัติบุคลากร — รายชื่อบุคลากรทุกคน สถานะการกรอก/ตรวจสอบทะเบียนประวัติ (ผู้ดูแลงานบุคลากร + ผู้บริหาร)
 *   ?dept= ?type= ?state=none|pending|verified   ตัวกรอง
 * ส่งออกรายชื่อ Excel/PDF ที่ hr/reports.php?tab=staff
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_view_hr_overview($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับงานบุคลากรและผู้บริหาร');
}
$self = 'hr/staff.php';
$activeMenu = $self;
$isHr = can_manage_hr($user);
$fDept = get_int('dept');
$fType = in_array(get('type'), hr_staff_types(), true) ? get('type') : '';
$fState = in_array(get('state'), ['none', 'pending', 'verified'], true) ? get('state') : '';

$people = hr_people($fDept);
$state = fn(array $p) => !(int)$p['has_profile'] ? 'none' : (!empty($p['verified_at']) ? 'verified' : 'pending');
$counts = ['none' => 0, 'pending' => 0, 'verified' => 0];
foreach ($people as $p) {
    $counts[$state($p)]++;
}
$rows = array_values(array_filter($people, fn($p) => ($fType === '' || $p['staff_type'] === $fType) && ($fState === '' || $state($p) === $fState)));
$edu = hr_highest_educations();
$departments = db_all('SELECT id, name FROM departments ORDER BY name');
$q = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['dept' => $fDept ?: null, 'type' => $fType, 'state' => $fState], $over), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'ทะเบียนประวัติบุคลากร';
$pageIcon = 'bi-person-vcard';
$breadcrumbs = ['ทะเบียนประวัติบุคลากร' => null];
$pageActions = '<a href="' . e(url('hr/reports.php?tab=staff' . ($fDept ? '&dept=' . $fDept : '') . '&export=csv')) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i> ส่งออก Excel</a>'
    . ' <a href="' . e(url('hr/report_print.php?tab=staff' . ($fDept ? '&dept=' . $fDept : ''))) . '" target="_blank" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i> พิมพ์ / PDF</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['' => ['ทั้งหมด', count($people), 'btn-outline-primary'], 'none' => ['ยังไม่กรอก', $counts['none'], 'btn-outline-secondary'],
                       'pending' => ['รอตรวจสอบ', $counts['pending'], 'btn-outline-warning'], 'verified' => ['ตรวจสอบแล้ว', $counts['verified'], 'btn-outline-success']] as $k => [$label, $n, $cls]): ?>
        <a href="<?= e($q(['state' => $k])) ?>" class="btn btn-sm <?= $fState === $k ? str_replace('outline-', '', $cls) : $cls ?>"><?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $n ?></span></a>
    <?php endforeach; ?>
</div>
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="state" value="<?= e($fState) ?>">
            <div class="col-md-5"><label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                <select name="dept" id="dept" class="form-select"><option value="0">ทุกแผนก/งาน</option>
                    <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label small mb-1" for="type">ประเภทบุคลากร</label>
                <select name="type" id="type" class="form-select"><option value="">ทั้งหมด</option>
                    <?php foreach (hr_staff_types() as $t): ?><option <?= $fType === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable">
                <thead><tr><th>ชื่อ-สกุล</th><th>ตำแหน่ง/วิทยฐานะ</th><th>แผนก/งาน</th><th>ประเภท</th><th>อายุราชการ</th><th>วุฒิสูงสุด</th><th>ทะเบียนประวัติ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): $st = $state($p); ?>
                    <tr>
                        <td><?= e($p['full_name']) ?></td>
                        <td class="small"><?= e($p['position'] ?: '-') ?><?= $p['academic_standing'] ? '<div class="text-muted">' . e($p['academic_standing']) . '</div>' : '' ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$p['department_name']) ?: '-') ?></td>
                        <td class="small"><?= e($p['staff_type'] ?: '-') ?></td>
                        <td class="small" data-order="<?= e($p['service_start_date'] ?? '9999') ?>"><?= e(hr_service_length($p['service_start_date'])) ?></td>
                        <td class="small"><?= e($edu[(int)$p['id']] ?? '-') ?></td>
                        <td data-order="<?= $st ?>"><?= ['none' => '<span class="badge bg-secondary">ยังไม่กรอก</span>', 'pending' => '<span class="badge bg-warning text-dark">รอตรวจสอบ</span>',
                                'verified' => '<span class="badge bg-success">ตรวจสอบแล้ว</span>'][$st] ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url('hr/profile.php?id=' . (int)$p['id'])) ?>" class="btn btn-sm <?= $isHr && $st === 'pending' ? 'btn-accent' : 'btn-outline-primary' ?>"><i class="bi bi-eye"></i> <?= $isHr && $st === 'pending' ? 'ตรวจสอบ' : 'ดู' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
