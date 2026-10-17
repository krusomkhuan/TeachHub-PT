<?php
/**
 * ไฟล์: public/calendar.php
 * ปฏิทินกำหนดการส่งงาน — ปฏิทินรายเดือน (วันเปิด/วันปิดรับ) + รายการงานที่เปิดอยู่พร้อมนับถอยหลัง
 * พารามิเตอร์: ?ym=2026-10 (เดือนที่แสดง), ?type=lesson_plan|pa|research
 */
require_once __DIR__ . '/../config/config.php';

// ---------- เดือนที่แสดง ----------
$ym = get('ym', date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
    $ym = date('Y-m');
}
$firstDay = strtotime($ym . '-01');
$daysInMonth = (int)date('t', $firstDay);
$startWeekday = (int)date('w', $firstDay); // 0 = อาทิตย์
$prevYm = date('Y-m', strtotime('-1 month', $firstDay));
$nextYm = date('Y-m', strtotime('+1 month', $firstDay));

$type = get('type');
if (!in_array($type, submission_types(), true)) {
    $type = '';
}
$typeSql = $type !== '' ? ' AND type = ?' : '';
$typeTypes = $type !== '' ? 's' : '';
$typeParams = $type !== '' ? [$type] : [];

// ---------- เหตุการณ์ในเดือน (วันเปิด + วันปิด) ----------
$monthStart = date('Y-m-d 00:00:00', $firstDay);
$monthEnd = date('Y-m-t 23:59:59', $firstDay);
$monthRounds = db_all(
    "SELECT id, type, phase, name, open_at, close_at FROM submission_rounds
     WHERE is_active = 1 AND ((open_at BETWEEN ? AND ?) OR (close_at BETWEEN ? AND ?)) $typeSql
     ORDER BY open_at",
    'ssss' . $typeTypes,
    array_merge([$monthStart, $monthEnd, $monthStart, $monthEnd], $typeParams)
);
$events = []; // [วันที่ => [[class, label, title], ...]]
foreach ($monthRounds as $r) {
    foreach (['open' => $r['open_at'], 'close' => $r['close_at']] as $kind => $dt) {
        if (date('Y-m', strtotime($dt)) === $ym) {
            $day = (int)date('j', strtotime($dt));
            $events[$day][] = [
                $kind,
                ($kind === 'open' ? 'เปิด: ' : 'ปิด: ') . $r['name'],
                ($kind === 'open' ? 'เปิดรับ ' : 'ปิดรับ ') . thai_datetime($dt) . ' — ' . $r['name'],
            ];
        }
    }
}

// ---------- รายการรอบแยกสถานะ ----------
$allRounds = db_all(
    "SELECT * FROM submission_rounds
     WHERE is_active = 1 AND close_at >= NOW() - INTERVAL 60 DAY $typeSql
     ORDER BY close_at ASC",
    $typeTypes,
    $typeParams
);
$groups = ['open' => [], 'upcoming' => [], 'closed' => []];
foreach ($allRounds as $r) {
    $s = round_state($r);
    if ($s === 'open') {
        $groups['open'][] = $r;
    } elseif ($s === 'upcoming') {
        $groups['upcoming'][] = $r;
    } else {
        $groups['closed'][] = $r; // late + closed
    }
}
$groups['closed'] = array_reverse($groups['closed']);

$pageTitle = 'ปฏิทินกำหนดการส่งงาน';
$activeNav = 'calendar';
require APP_ROOT . '/includes/public_header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <h3 class="section-title mb-0">ปฏิทินกำหนดการส่งงาน</h3>
        <form method="get" class="ms-auto d-flex gap-2">
            <input type="hidden" name="ym" value="<?= e($ym) ?>">
            <select name="type" class="form-select" onchange="this.form.submit()" aria-label="กรองประเภทงาน">
                <option value="">งานทุกประเภท</option>
                <?php foreach (submission_types() as $t): ?>
                    <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(submission_type_label($t)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- งานที่เปิดรับอยู่ -->
    <h5 class="fw-bold mt-2 mb-3 text-success"><i class="bi bi-door-open me-1"></i> เปิดรับอยู่ตอนนี้ (<?= count($groups['open']) ?>)</h5>
    <?php if ($groups['open']): ?>
        <div class="row g-3 mb-4">
            <?php foreach ($groups['open'] as $r): ?>
                <div class="col-md-6 col-lg-4"><?= round_card_html($r) ?></div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card mb-4"><div class="empty-state">ไม่มีงานที่เปิดรับในขณะนี้</div></div>
    <?php endif; ?>

    <!-- ปฏิทินรายเดือน -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center">
            <a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(['ym' => $prevYm, 'type' => $type])) ?>" aria-label="เดือนก่อน"><i class="bi bi-chevron-left"></i></a>
            <div class="mx-auto fs-5"><?= e(thai_months(false)[(int)date('n', $firstDay)]) ?> <?= thai_year($firstDay) ?></div>
            <a class="btn btn-sm btn-outline-secondary me-2" href="?<?= e(http_build_query(['ym' => date('Y-m'), 'type' => $type])) ?>">เดือนนี้</a>
            <a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(['ym' => $nextYm, 'type' => $type])) ?>" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-bordered cal-table mb-0">
                <thead>
                <tr>
                    <?php foreach (['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'] as $wd): ?><th><?= $wd ?></th><?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php
                $cell = 0;
                $today = date('Y-m-d');
                echo '<tr>';
                for ($i = 0; $i < $startWeekday; $i++, $cell++) {
                    echo '<td class="other-month"></td>';
                }
                for ($d = 1; $d <= $daysInMonth; $d++, $cell++) {
                    if ($cell > 0 && $cell % 7 === 0) {
                        echo '</tr><tr>';
                    }
                    $isToday = ($ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)) === $today;
                    echo '<td class="' . ($isToday ? 'today' : '') . '"><div class="cal-day">' . $d . '</div>';
                    foreach ($events[$d] ?? [] as [$cls, $label, $title]) {
                        echo '<span class="cal-event ' . e($cls) . '" title="' . e($title) . '" data-bs-toggle="tooltip">' . e($label) . '</span>';
                    }
                    echo '</td>';
                }
                while ($cell % 7 !== 0) {
                    echo '<td class="other-month"></td>';
                    $cell++;
                }
                echo '</tr>';
                ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white small">
            <span class="cal-event open d-inline-block me-2">เปิด</span> วันเปิดรับ
            <span class="cal-event close d-inline-block ms-3 me-2">ปิด</span> วันปิดรับ
        </div>
    </div>

    <div class="row g-4">
        <!-- กำลังจะเปิด -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3 text-info"><i class="bi bi-hourglass-top me-1"></i> กำลังจะเปิดรับ (<?= count($groups['upcoming']) ?>)</h5>
            <?php foreach ($groups['upcoming'] as $r): ?>
                <div class="mb-3"><?= round_card_html($r) ?></div>
            <?php endforeach; ?>
            <?php if (!$groups['upcoming']): ?><div class="card"><div class="empty-state">ไม่มี</div></div><?php endif; ?>
        </div>
        <!-- ปิดรับแล้ว -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3 text-secondary"><i class="bi bi-lock me-1"></i> ปิดรับแล้ว (60 วันล่าสุด)</h5>
            <div class="card">
                <ul class="list-group list-group-flush rounded-3">
                    <?php foreach ($groups['closed'] as $r): ?>
                        <li class="list-group-item d-flex align-items-center gap-2 py-3">
                            <i class="bi <?= e(submission_type_icon($r['type'])) ?> text-muted fs-5"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?= e($r['name']) ?></div>
                                <small class="text-muted">ปิดรับ <?= e(thai_datetime($r['close_at'])) ?></small>
                            </div>
                            <?= round_state_badge($r) ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$groups['closed']): ?><li class="list-group-item empty-state">ไม่มี</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/public_footer.php'; ?>
