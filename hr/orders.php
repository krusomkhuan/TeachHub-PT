<?php
/**
 * ไฟล์: hr/orders.php
 * คำสั่งที่เกี่ยวกับฉัน — คำสั่งที่มีชื่อผู้ใช้ และคำสั่งที่งานบุคลากรเผยแพร่ให้ทุกคน   ?fy= ปีงบประมาณ (all = ทุกปี)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$self = 'hr/orders.php';
$activeMenu = $self;

$all = get('fy') === 'all';
$fy = hr_fiscal_param();
[$from, $to] = $all ? ['1900-01-01', '2999-12-31'] : hr_fiscal_range($fy);
$rows = db_all(
    'SELECT o.*, (ou.user_id IS NOT NULL) AS named
     FROM hr_orders o LEFT JOIN hr_order_users ou ON ou.order_id = o.id AND ou.user_id = ?
     WHERE (ou.user_id IS NOT NULL OR o.is_public = 1) AND o.order_date BETWEEN ? AND ?
     ORDER BY o.order_date DESC, o.id DESC',
    'iss',
    [$uid, $from, $to]
);

$pageTitle = 'คำสั่งที่เกี่ยวกับฉัน';
$pageIcon = 'bi-file-earmark-text';
$breadcrumbs = ['คำสั่งที่เกี่ยวกับฉัน' => null];
if (can_manage_hr($user)) {
    $pageActions = '<a href="' . e(url('hr/orders_manage.php')) . '" class="btn btn-outline-primary"><i class="bi bi-gear me-1"></i> จัดการคำสั่ง</a>';
}
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $all ? 'selected' : '' ?>>ทุกปี</option>
                    <?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= !$all && $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                </select></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" data-order="[]">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>เรื่อง</th><th>ประเภท</th><th></th><th class="no-sort">ไฟล์</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= e($r['order_no']) ?></td>
                        <td class="text-nowrap" data-order="<?= e($r['order_date']) ?>"><?= e(thai_date($r['order_date'])) ?></td>
                        <td><?= e($r['title']) ?><?= $r['detail'] !== '' ? '<div class="small text-muted">' . e(str_limit($r['detail'], 140)) . '</div>' : '' ?></td>
                        <td class="small"><?= e($r['category']) ?></td>
                        <td><?= (int)$r['named'] ? '<span class="badge bg-primary">มีชื่อท่าน</span>' : '<span class="badge bg-light text-dark border">ประกาศทั่วไป</span>' ?></td>
                        <td><?php if ($r['file_stored']): ?><a href="<?= e(hr_file_url('order', (int)$r['id'])) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi <?= e(file_icon($r['file_original'])) ?>"></i> เปิด</a><?php else: ?>-<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
