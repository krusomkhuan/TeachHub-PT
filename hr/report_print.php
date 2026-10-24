<?php
/**
 * ไฟล์: hr/report_print.php?tab=staff|leave|training|events&fy=&dept=
 * รายงานสรุปบุคลากรรูปแบบ A4 แนวนอน สำหรับพิมพ์ หรือ "บันทึกเป็น PDF" จากเบราว์เซอร์ (ผู้ดูแลงานบุคลากร + ผู้บริหาร)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_view_hr_overview($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับงานบุคลากรและผู้บริหาร');
}
$tab = isset(hr_report_types()[get('tab')]) ? get('tab') : 'staff';
$fy = hr_fiscal_param();
$deptId = get_int('dept');
$report = hr_report_data($tab, $fy, $deptId);
$deptName = $deptId ? (string)db_value('SELECT name FROM departments WHERE id = ?', 'i', [$deptId]) : '';
log_activity('hr_export', 'พิมพ์/PDF: ' . $report['title']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($report['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 13.5px; color: #111; background: #e9ecef; margin: 0; }
        .sheet { width: 297mm; min-height: 210mm; margin: 16px auto; background: #fff; padding: 12mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .head { text-align: center; margin-bottom: 10px; }
        .head h1 { font-size: 19px; margin: 0; }
        .head div { font-size: 14.5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #555; padding: 3px 5px; vertical-align: top; }
        th { background: #dfe6f3; text-align: center; vertical-align: middle; }
        td.n { text-align: right; white-space: nowrap; }
        tr { page-break-inside: avoid; }
        .foot { margin-top: 8px; font-size: 12.5px; color: #444; display: flex; }
        .foot span:last-child { margin-left: auto; }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button, .toolbar a { font-family: inherit; font-size: 15px; padding: 8px 18px; border-radius: 8px; border: 0; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-print { background: #f47b20; color: #fff; }
        .btn-back { background: #1b2a4e; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            .toolbar { display: none; }
            th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead { display: table-header-group; }
        }
        @media screen and (max-width: 1100px) { .sheet { width: 100%; overflow-x: auto; padding: 12px; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์ / บันทึกเป็น PDF</button>
    <a href="javascript:window.close()" class="btn-back" onclick="if(!window.opener){history.back();return false;}">ปิดหน้านี้</a>
    <div style="font-size:13px;color:#555;margin-top:6px">เลือกเครื่องพิมพ์ "Save as PDF / บันทึกเป็น PDF" เพื่อบันทึกเป็นไฟล์ PDF (กระดาษ A4 แนวนอน)</div>
</div>
<div class="sheet">
    <div class="head">
        <h1><?= e($report['title']) ?></h1>
        <div><?= e(college_name()) ?><?= $deptName !== '' ? ' — ' . e($deptName) : '' ?></div>
    </div>
    <table>
        <thead><tr><?php foreach ($report['header'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($report['rows'] as $r): ?>
            <tr><?php foreach ($r as $i => $c): ?><td<?= in_array($i, $report['num'], true) ? ' class="n"' : '' ?>><?= e($c) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['header']) ?>" style="text-align:center">ไม่มีข้อมูล</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="foot"><span>รวม <?= count($report['rows']) ?> คน</span><span>พิมพ์เมื่อ <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?> · <?= e(system_name()) ?></span></div>
</div>
</body>
</html>
