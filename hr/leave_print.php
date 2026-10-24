<?php
/**
 * ไฟล์: hr/leave_print.php?id=xx
 * พิมพ์ใบลาตามแบบราชการ (A4) หรือ "บันทึกเป็น PDF" จากเบราว์เซอร์
 *   ลาพักผ่อน → แบบใบลาพักผ่อน · ประเภทอื่น → แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว (ลาอื่น ๆ ใช้แบบเดียวกัน)
 * สิทธิ์: ผู้ลา ผู้พิจารณา หัวหน้าแผนกของผู้ลา ผู้ดูแลงานบุคลากร ผู้บริหาร (hr_leave_can_view)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$leave = hr_leave_fetch(get_int('id'));
if (!$leave || !hr_leave_can_view($user, $leave)) {
    abort_page(404, 'ไม่พบใบลา');
}
$actions = hr_leave_actions((int)$leave['id']);
$last = [];
foreach ($actions as $a) {
    $last[$a['step']] = $a; // ใช้ความเห็นล่าสุดของแต่ละขั้น
}
$type = $leave['leave_type'];
$isVacation = $type === 'vacation';
$fy = (int)$leave['fiscal_year'];
$before = hr_leave_used((int)$leave['user_id'], $fy, (int)$leave['id']);
$prev = hr_leave_previous($leave);
$quota = hr_vacation_quota((int)$leave['user_id'], $fy);
$months = thai_months(false);
$ts = strtotime($leave['created_at']);
$box = fn(bool $on) => $on ? '☑' : '☐';
$dot = fn($text, string $minWidth = '') => '<span class="dot"' . ($minWidth ? ' style="min-width:' . $minWidth . '"' : '') . '>' . e($text === '' || $text === null ? ' ' : $text) . '</span>';
$days = fn(float $d) => hr_days($d);
$signName = fn(?array $a) => $a ? (string)$a['actor_name'] : '';
$signPos = fn(?array $a) => $a ? trim((string)$a['actor_position'] . ($a['actor_standing'] ? ' ' . $a['actor_standing'] : '')) : '';
$decisionText = ['approve' => 'เห็นควรอนุญาต', 'reject' => 'ไม่ควรอนุญาต'];
$statsTypes = $isVacation ? ['vacation'] : (in_array($type, ['sick', 'personal', 'maternity'], true) ? ['sick', 'personal', 'maternity'] : [$type]);
$title = $isVacation ? 'แบบใบลาพักผ่อน' : (in_array($type, ['sick', 'personal', 'maternity'], true) ? 'แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว' : 'แบบใบ' . hr_leave_type_label($type));
$position = trim($leave['position'] . ($leave['academic_standing'] ? ' ' . $leave['academic_standing'] : ''));
$unit = ($leave['department_name'] ? $leave['department_name'] . ' ' : '') . college_name();
$draft = $leave['status'] !== 'approved';
log_activity('hr_leave_print', 'พิมพ์ใบลา #' . (int)$leave['id'] . ' ของ ' . $leave['user_name']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title . ' - ' . $leave['user_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @page { size: A4; margin: 15mm 15mm 15mm 22mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 15.5px; color: #111; background: #e9ecef; margin: 0; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 15mm 15mm 15mm 22mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); position: relative; }
        h1 { font-size: 20px; text-align: center; margin: 0 0 14px; }
        .right { margin-left: 50%; line-height: 1.9; }
        p { margin: 0; line-height: 2; }
        .indent { text-indent: 2.5em; }
        .dot { border-bottom: 1px dotted #333; padding: 0 6px; display: inline-block; min-width: 40px; text-align: center; text-indent: 0; line-height: 1.5; }
        .sign { text-align: center; line-height: 1.9; margin: 14px 0 0 45%; }
        .cols { display: flex; gap: 18px; margin-top: 18px; page-break-inside: avoid; }
        .cols > div { flex: 1; }
        .cols h3 { font-size: 15.5px; margin: 0 0 6px; text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; font-size: 14.5px; }
        th, td { border: 1px solid #444; padding: 3px 6px; text-align: center; }
        .small-sign { text-align: center; line-height: 1.8; margin-top: 10px; font-size: 14.5px; }
        .comment { min-height: 28px; border-bottom: 1px dotted #333; font-size: 14.5px; line-height: 1.7; }
        .stamp { position: absolute; top: 12mm; right: 15mm; border: 2px solid #b02a37; color: #b02a37; padding: 2px 10px; font-weight: 700; transform: rotate(-6deg); }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button, .toolbar a { font-family: inherit; font-size: 15px; padding: 8px 18px; border-radius: 8px; border: 0; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-print { background: #f47b20; color: #fff; }
        .btn-back { background: #1b2a4e; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            .toolbar { display: none; }
            .stamp { top: 0; right: 0; }
        }
        @media screen and (max-width: 820px) {
            .sheet { width: 100%; padding: 16px; min-height: auto; }
            .right, .sign { margin-left: 0; }
            .cols { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์ / บันทึกเป็น PDF</button>
    <a href="javascript:window.close()" class="btn-back" onclick="if(!window.opener){history.back();return false;}">ปิดหน้านี้</a>
    <div style="font-size:13px;color:#555;margin-top:6px">เลือกเครื่องพิมพ์ "Save as PDF / บันทึกเป็น PDF" เพื่อบันทึกเป็นไฟล์ PDF</div>
</div>

<div class="sheet">
    <?php if ($draft): ?><div class="stamp"><?= e(hr_leave_status_labels()[$leave['status']]) ?></div><?php endif; ?>
    <h1><?= e($title) ?></h1>
    <div class="right">
        เขียนที่ <?= $dot(college_name()) ?><br>
        วันที่ <?= $dot((string)(int)date('j', $ts)) ?> เดือน <?= $dot($months[(int)date('n', $ts)]) ?> พ.ศ. <?= $dot((string)((int)date('Y', $ts) + 543)) ?>
    </div>
    <p>เรื่อง &nbsp; ขอ<?= e(hr_leave_type_label($type)) ?></p>
    <p>เรียน &nbsp; ผู้อำนวยการ<?= e(college_name()) ?></p>

    <p class="indent">ข้าพเจ้า <?= $dot($leave['user_name'], '230px') ?> ตำแหน่ง <?= $dot($position ?: '-', '180px') ?>
        สังกัด <?= $dot($unit, '300px') ?>
    <?php if ($isVacation):
        $carry = max(0, $quota - (float)setting('hr_vacation_days', '10')); ?>
        มีวันลาพักผ่อนสะสม <?= $dot($days($carry)) ?> วันทำการ มีสิทธิลาพักผ่อนประจำปีนี้อีก <?= $dot($days($quota - $carry)) ?> วันทำการ
        รวมเป็น <?= $dot($days($quota)) ?> วันทำการ ขอลาพักผ่อนตั้งแต่วันที่ <?= $dot(thai_date($leave['start_date'], false, true)) ?>
        ถึงวันที่ <?= $dot(thai_date($leave['end_date'], false, true)) ?> มีกำหนด <?= $dot($days((float)$leave['days'])) ?> วัน
    <?php else: ?>
        ขอลา
        <?php if (in_array($type, ['sick', 'personal', 'maternity'], true)): ?>
            <?= $box($type === 'sick') ?> ป่วย <?= $box($type === 'personal') ?> กิจส่วนตัว <?= $box($type === 'maternity') ?> คลอดบุตร
        <?php else: ?>
            <?= $dot(hr_leave_type_label($type)) ?>
        <?php endif; ?>
        เนื่องจาก <?= $dot($leave['reason'] ?: '-', '260px') ?>
        ตั้งแต่วันที่ <?= $dot(thai_date($leave['start_date'], false, true)) ?> ถึงวันที่ <?= $dot(thai_date($leave['end_date'], false, true)) ?>
        <?= $leave['half_day'] ? '(' . ($leave['half_day'] === 'morning' ? 'ครึ่งวันเช้า' : 'ครึ่งวันบ่าย') . ')' : '' ?>
        มีกำหนด <?= $dot($days((float)$leave['days'])) ?> วัน
        ข้าพเจ้าได้ลา
        <?php if (in_array($type, ['sick', 'personal', 'maternity'], true)): $pt = $prev['leave_type'] ?? ''; ?>
            <?= $box($pt === 'sick') ?> ป่วย <?= $box($pt === 'personal') ?> กิจส่วนตัว <?= $box($pt === 'maternity') ?> คลอดบุตร
        <?php endif; ?>
        ครั้งสุดท้ายตั้งแต่วันที่ <?= $dot($prev ? thai_date($prev['start_date'], false, true) : '-') ?>
        ถึงวันที่ <?= $dot($prev ? thai_date($prev['end_date'], false, true) : '-') ?> มีกำหนด <?= $dot($prev ? $days((float)$prev['days']) : '-') ?> วัน
    <?php endif; ?>
        ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่ <?= $dot($leave['contact'] ?: '-', '260px') ?>
        <?= $leave['substitute'] !== '' ? 'ผู้ปฏิบัติงาน/สอนแทน ' . $dot($leave['substitute'], '180px') : '' ?>
    </p>

    <div class="sign">
        (ลงชื่อ) ...................................................<br>
        ( <?= e($leave['user_name']) ?> )
    </div>

    <div class="cols">
        <div>
            <h3>สถิติการลาในปีงบประมาณ <?= $fy ?></h3>
            <table>
                <thead><tr><th>ประเภทการลา</th><th>ลามาแล้ว<br>(วัน)</th><th>ลาครั้งนี้<br>(วัน)</th><th>รวมเป็น<br>(วัน)</th></tr></thead>
                <tbody>
                <?php foreach ($statsTypes as $t): $now = $t === $type ? (float)$leave['days'] : 0; ?>
                    <tr><td style="text-align:left"><?= e(str_replace('ลา', '', hr_leave_type_label($t)) ?: hr_leave_type_label($t)) ?></td>
                        <td><?= $days($before[$t]['days']) ?></td><td><?= $now ? $days($now) : '-' ?></td><td><?= $days($before[$t]['days'] + $now) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="small-sign">
                (ลงชื่อ) <?= $dot('', '150px') ?> ผู้ตรวจสอบ<br>
                ( <?= e($signName($last['hr'] ?? null) ?: '...................................') ?> )<br>
                ตำแหน่ง <?= e($signPos($last['hr'] ?? null) ?: '...................................') ?><br>
                วันที่ <?= e(isset($last['hr']) ? thai_date($last['hr']['created_at'], false, true) : '........./........./.........') ?>
            </div>
        </div>
        <div>
            <h3>ความเห็นผู้บังคับบัญชา</h3>
            <?php foreach (['head' => 'หัวหน้าแผนก', 'deputy' => 'รองผู้อำนวยการ'] as $st => $label): $a = $last[$st] ?? null; ?>
                <?php $dText = $a ? ($decisionText[$a['decision']] ?? '') : ''; ?>
                <div class="comment"><?= $a ? e($dText . ($a['comment'] !== '' && $a['comment'] !== $dText ? ' — ' . $a['comment'] : '')) : '' ?></div>
                <div class="small-sign" style="margin-top:4px">
                    (ลงชื่อ) <?= $dot('', '150px') ?><br>
                    ( <?= e($signName($a) ?: '...................................') ?> )<br>
                    ตำแหน่ง <?= e($st === 'head' && $leave['department_name'] ? 'หัวหน้า' . $leave['department_name'] : ($signPos($a) ?: $label)) ?> &nbsp; วันที่ <?= e($a ? thai_date($a['created_at'], false, true) : '......./......./.......') ?>
                </div>
            <?php endforeach; ?>
            <h3 style="margin-top:12px">คำสั่ง</h3>
            <?php $a = $last['director'] ?? null; ?>
            <div><?= $box($leave['status'] === 'approved') ?> อนุญาต &nbsp; <?= $box($leave['status'] === 'rejected') ?> ไม่อนุญาต</div>
            <div class="comment"><?= $a && $a['comment'] !== '' ? e($a['comment']) : '' ?></div>
            <div class="small-sign" style="margin-top:4px">
                (ลงชื่อ) <?= $dot('', '150px') ?><br>
                ( <?= e($signName($a) ?: '...................................') ?> )<br>
                ตำแหน่ง <?= e($signPos($a) ?: 'ผู้อำนวยการ' . college_name()) ?><br>
                วันที่ <?= e($a ? thai_date($a['created_at'], false, true) : '......./......./.......') ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
