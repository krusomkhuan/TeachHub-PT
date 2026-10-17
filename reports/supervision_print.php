<?php
/**
 * ไฟล์: reports/supervision_print.php?id=schedule_id
 * แบบบันทึกการนิเทศการสอน รูปแบบ A4 สำหรับพิมพ์ หรือ "บันทึกเป็น PDF" จากเบราว์เซอร์
 * สิทธิ์: ครูผู้รับการนิเทศ, ผู้นิเทศ, หัวหน้าแผนก (แผนกเดียวกัน), admin, ผู้บริหาร
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$sc = schedule_fetch(get_int('id'));
if (!$sc || !can_view_schedule($user, $sc)) {
    abort_page(404, 'ไม่พบผลการนิเทศ');
}
if (!$sc['result_id']) {
    abort_page(404, 'ยังไม่มีผลการนิเทศ', 'ต้องบันทึกผลการนิเทศก่อนจึงจะพิมพ์ได้');
}
$scores = supervision_scores((int)$sc['result_id']);
$catAvg = supervision_category_avg($scores);
$images = supervision_images((int)$sc['result_id']);
$logo = logo_url();
log_activity('supervision_print', 'พิมพ์แบบบันทึกการนิเทศ #' . (int)$sc['id']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แบบบันทึกการนิเทศการสอน - <?= e($sc['teacher_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* หน้ากระดาษ A4 */
        @page { size: A4; margin: 15mm 15mm 15mm 20mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 15px; color: #111; background: #e9ecef; margin: 0; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 15mm 15mm 15mm 20mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .head { text-align: center; margin-bottom: 10px; }
        .head img { width: 70px; height: 70px; object-fit: contain; }
        .head h1 { font-size: 20px; margin: 6px 0 0; }
        .head .sub { font-size: 15px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 3px 4px; vertical-align: top; }
        .info .lbl { width: 22%; color: #333; font-weight: 600; }
        .score th, .score td { border: 1px solid #555; padding: 4px 6px; }
        .score th { background: #eef1f7; text-align: center; }
        .score .cat td { background: #f6f6f6; font-weight: 600; }
        .score .c { text-align: center; width: 34px; }
        .summary { margin-top: 8px; padding: 8px 10px; border: 1px solid #555; }
        .box { border: 1px solid #555; padding: 6px 10px; min-height: 60px; margin-top: 4px; white-space: pre-wrap; }
        h3 { font-size: 16px; margin: 12px 0 2px; }
        .imgs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .imgs img { width: 32%; height: 48mm; object-fit: cover; border: 1px solid #999; }
        .sign { display: flex; justify-content: space-around; margin-top: 28px; text-align: center; page-break-inside: avoid; }
        .sign div { width: 45%; }
        .sign .line { margin-top: 36px; }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button, .toolbar a { font-family: inherit; font-size: 15px; padding: 8px 18px; border-radius: 8px; border: 0; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-print { background: #f47b20; color: #fff; }
        .btn-back { background: #1b2a4e; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            .toolbar { display: none; }
            .score tr, .imgs img { page-break-inside: avoid; }
        }
        @media screen and (max-width: 820px) {
            .sheet { width: 100%; padding: 16px; min-height: auto; }
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
    <div class="head">
        <img src="<?= e($logo) ?>" alt="โลโก้">
        <h1>แบบบันทึกการนิเทศการสอน</h1>
        <div class="sub"><?= e(college_name()) ?> · ภาคเรียนที่ <?= $sc['ay_year'] ? e($sc['ay_semester'] . '/' . $sc['ay_year']) : '-' ?></div>
    </div>

    <table class="info">
        <tr><td class="lbl">ผู้รับการนิเทศ</td><td><?= e($sc['teacher_name']) ?></td><td class="lbl">ตำแหน่ง</td><td><?= e($sc['teacher_position']) ?><?= $sc['teacher_standing'] ? ' ' . e($sc['teacher_standing']) : '' ?></td></tr>
        <tr><td class="lbl">แผนกวิชา</td><td><?= e($sc['department_name'] ?? '-') ?></td><td class="lbl">รายวิชา</td><td><?= e($sc['subject_name']) ?></td></tr>
        <tr><td class="lbl">วันที่นิเทศ</td><td><?= e(thai_date($sc['supervise_date'], false, true)) ?></td><td class="lbl">เวลา / ห้อง</td><td><?= e(substr($sc['start_time'], 0, 5)) ?>–<?= e(substr($sc['end_time'], 0, 5)) ?> น. · <?= e($sc['classroom'] ?: '-') ?></td></tr>
        <tr><td class="lbl">ผู้นิเทศ</td><td colspan="3"><?= e($sc['supervisor_name']) ?><?= $sc['supervisor_position'] ? ' (' . e($sc['supervisor_position']) . ')' : '' ?></td></tr>
    </table>

    <h3>ผลการประเมิน (5 = ดีมาก, 4 = ดี, 3 = ปานกลาง, 2 = พอใช้, 1 = ควรปรับปรุง)</h3>
    <table class="score">
        <thead><tr><th style="text-align:left">รายการประเมิน</th><th class="c">5</th><th class="c">4</th><th class="c">3</th><th class="c">2</th><th class="c">1</th></tr></thead>
        <tbody>
        <?php $lastCat = null; $no = 0; foreach ($scores as $s): $no++; ?>
            <?php if ($s['category'] !== $lastCat): $lastCat = $s['category']; ?>
                <tr class="cat"><td colspan="6"><?= e($s['category']) ?> (เฉลี่ย <?= number_format($catAvg[$s['category']] ?? 0, 2) ?>)</td></tr>
            <?php endif; ?>
            <tr>
                <td><?= $no ?>. <?= e($s['criteria_title']) ?></td>
                <?php foreach ([5, 4, 3, 2, 1] as $n): ?><td class="c"><?= (int)$s['score'] === $n ? '✓' : '' ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="summary">
        <strong>คะแนนเฉลี่ยรวม:</strong> <?= e(number_format((float)$sc['avg_score'], 2)) ?> จาก 5.00
        &nbsp;&nbsp; <strong>ระดับคุณภาพ:</strong> <?= e(quality_level((float)$sc['avg_score'])) ?>
        &nbsp;&nbsp; <strong>จำนวนรายการ:</strong> <?= count($scores) ?> ข้อ
    </div>

    <h3>จุดเด่น</h3>
    <div class="box"><?= e($sc['strengths'] ?: '-') ?></div>
    <h3>ข้อเสนอแนะ</h3>
    <div class="box"><?= e($sc['suggestions'] ?: '-') ?></div>

    <?php if ($images): ?>
        <h3>ภาพหลักฐานการนิเทศ</h3>
        <div class="imgs">
            <?php foreach (array_slice($images, 0, 6) as $im): ?>
                <img src="<?= e(url('supervision_image.php?id=' . (int)$im['id'])) ?>" alt="หลักฐาน">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="sign">
        <div>
            <div class="line">ลงชื่อ ........................................................</div>
            <div>( <?= e($sc['teacher_name']) ?> )</div>
            <div>ผู้รับการนิเทศ</div>
            <div style="font-size:13px">รับทราบผลเมื่อ <?= $sc['acknowledged_at'] ? e(thai_datetime($sc['acknowledged_at'])) : '................................' ?></div>
        </div>
        <div>
            <div class="line">ลงชื่อ ........................................................</div>
            <div>( <?= e($sc['supervisor_name']) ?> )</div>
            <div>ผู้นิเทศ</div>
            <div style="font-size:13px">บันทึกผลเมื่อ <?= e(thai_datetime($sc['result_updated_at'] ?: $sc['result_created_at'])) ?></div>
        </div>
    </div>
    <div style="margin-top:18px;font-size:12px;color:#666;text-align:right">พิมพ์จากระบบ <?= e(system_name()) ?> เมื่อ <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?></div>
</div>
</body>
</html>
