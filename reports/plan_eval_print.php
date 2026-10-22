<?php
/**
 * ไฟล์: reports/plan_eval_print.php?plan=lesson_plan_id
 * แบบประเมินแผนการจัดการเรียนรู้ รูปแบบ A4 ตามแบบฟอร์มของวิทยาลัย สำหรับพิมพ์ หรือ "บันทึกเป็น PDF" จากเบราว์เซอร์
 * สิทธิ์: ครูเจ้าของแผน, ผู้ประเมินตามขอบเขต, ผู้ดูรายงาน (หัวหน้าแผนกเฉพาะแผนกตนเอง)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$plan = plan_eval_fetch_plan(get_int('plan'));
if (!$plan || $plan['doc_type'] !== 'plan' || !can_view_plan_eval($user, $plan)) {
    abort_page(404, 'ไม่พบแผนการจัดการเรียนรู้');
}
if (!$plan['eval_id']) {
    abort_page(404, 'ยังไม่มีผลการประเมิน', 'ต้องบันทึกผลการประเมินก่อนจึงจะพิมพ์ได้');
}
$ev = plan_eval_fetch((int)$plan['eval_id']);
if (!plan_eval_is_final($ev) && !can_evaluate_plan($user, $plan)) {
    abort_page(404, 'ยังไม่มีผลการประเมิน', 'ผู้ประเมินยังไม่ได้ส่งผลการประเมิน');
}
$items = plan_eval_items((int)$ev['id']);
$cats = plan_eval_category_summary($items['quality']);
$levelCount = array_fill_keys([5, 4, 3, 2, 1], 0);
foreach ($items['quality'] as $it) {
    $levelCount[(int)$it['score']]++;
}
$box = fn(bool $on) => $on ? '☑' : '☐';
$evDate = strtotime($ev['updated_at'] ?: $ev['created_at']);
$logo = logo_url();
log_activity('plan_eval_print', 'พิมพ์แบบประเมินแผนฯ ' . $plan['subject_code'] . ' ของ ' . $plan['teacher_name']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แบบประเมินแผนการจัดการเรียนรู้ - <?= e($plan['subject_code'] . ' ' . $plan['teacher_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @page { size: A4; margin: 15mm 15mm 15mm 20mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 14.5px; color: #111; background: #e9ecef; margin: 0; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 15mm 15mm 15mm 20mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .head { text-align: center; margin-bottom: 8px; }
        .head h1 { font-size: 20px; margin: 0; }
        .head .sub { font-size: 15px; font-weight: 600; }
        h3 { font-size: 15.5px; margin: 12px 0 4px; }
        .info { line-height: 1.9; padding-left: 14px; }
        .dot { border-bottom: 1px dotted #333; padding: 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { border: 1px solid #555; padding: 3px 6px; vertical-align: top; }
        .grid th { background: #dfe6f3; text-align: center; vertical-align: middle; }
        .grid .sec td { background: #f1f1f1; font-weight: 600; }
        .grid .c { text-align: center; width: 38px; vertical-align: middle; }
        .grid .shade { background: #bdbdbd; }
        .note { font-size: 13px; margin-top: 3px; }
        .box { border: 1px solid #555; padding: 6px 10px; min-height: 56px; white-space: pre-wrap; }
        .opt { margin: 2px 0 2px 14px; }
        .sign { width: 60%; margin: 26px 0 0 auto; text-align: center; line-height: 1.9; page-break-inside: avoid; }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button, .toolbar a { font-family: inherit; font-size: 15px; padding: 8px 18px; border-radius: 8px; border: 0; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-print { background: #f47b20; color: #fff; }
        .btn-back { background: #1b2a4e; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            .toolbar { display: none; }
            .grid tr { page-break-inside: avoid; }
            .grid th, .grid .sec td, .grid .shade { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media screen and (max-width: 820px) {
            .sheet { width: 100%; padding: 16px; min-height: auto; }
            .sign { width: 100%; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <?php if (!plan_eval_is_final($ev)): ?><div style="color:#b02a37;font-weight:600;margin-bottom:8px">ผลนี้ยังเป็นร่าง (ยังไม่ได้ส่งผลให้ครู)</div><?php endif; ?>
    <button type="button" class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์ / บันทึกเป็น PDF</button>
    <a href="javascript:window.close()" class="btn-back" onclick="if(!window.opener){history.back();return false;}">ปิดหน้านี้</a>
    <div style="font-size:13px;color:#555;margin-top:6px">เลือกเครื่องพิมพ์ "Save as PDF / บันทึกเป็น PDF" เพื่อบันทึกเป็นไฟล์ PDF</div>
</div>

<div class="sheet">
    <div class="head">
        <h1>แบบประเมินแผนการจัดการเรียนรู้</h1>
        <div class="sub"><?= e(college_name()) ?> &nbsp; สำนักงานคณะกรรมการการอาชีวศึกษา</div>
    </div>

    <h3>ข้อมูลทั่วไป</h3>
    <div class="info">
        ชื่อผู้จัดทำ (ครูผู้สอน) <span class="dot"><?= e($plan['teacher_name']) ?></span>
        &nbsp; แผนกวิชา <span class="dot"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)($ev['eval_department_name'] ?? $plan['department_name'] ?? '-'))) ?></span><br>
        หลักสูตร <?= $box($ev['program'] === 'ปวช.') ?> ปวช. <?= $box($ev['program'] === 'ปวส.') ?> ปวส.
        &nbsp; ประเภทวิชา <span class="dot"><?= e($ev['subject_category'] ?: '-') ?></span>
        &nbsp; สาขาวิชา <span class="dot"><?= e($ev['major'] ?: '-') ?></span><br>
        รหัสวิชา <span class="dot"><?= e($plan['subject_code']) ?></span>
        &nbsp; ชื่อวิชา <span class="dot"><?= e($plan['subject_name']) ?></span>
        &nbsp; ท-ป-น <span class="dot"><?= e($ev['credit'] ?: '-') ?></span><br>
        จำนวนหน่วยการเรียนรู้ <span class="dot"><?= (int)$ev['unit_count'] ?></span> หน่วย
        &nbsp; จำนวนแผนการจัดการเรียนรู้ <span class="dot"><?= (int)$ev['plan_count'] ?></span> แผน<br>
        อ้างอิงมาตรฐานอาชีพ/หลักสูตรการฝึกยกระดับฝีมือ &nbsp; <?= $box((bool)$ev['has_standard']) ?> มี &nbsp; <?= $box(!$ev['has_standard']) ?> ไม่มี
    </div>

    <h3>ระดับคุณภาพ (ตอนที่ 2)</h3>
    <table class="grid">
        <thead><tr><th style="width:90px">ระดับคะแนน</th><th style="width:150px">ระดับคุณภาพ</th><th>ความหมาย</th></tr></thead>
        <tbody>
        <?php foreach (plan_eval_score_labels() as $n => [$label, $meaning]): ?>
            <tr><td class="c" style="width:auto"><?= $n ?></td><td style="text-align:center"><?= e($label) ?></td><td><?= e($meaning) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>ตอนที่ 1 การตรวจสอบองค์ประกอบของแผนการจัดการเรียนรู้</h3>
    <table class="grid">
        <thead><tr><th style="width:38px">ที่</th><th>รายการ</th><th class="c">มี</th><th class="c">ไม่มี</th><th style="width:62px">ไม่<br>เกี่ยวข้อง</th></tr></thead>
        <tbody>
        <?php $lastCat = null; $no = 0; foreach ($items['check'] as $it): $no++; ?>
            <?php if ($it['category'] !== $lastCat): $lastCat = $it['category']; ?>
                <tr class="sec"><td colspan="5"><?= e($it['category']) ?></td></tr>
            <?php endif; ?>
            <tr>
                <td class="c"><?= $no ?></td>
                <td><?= e($it['title']) ?></td>
                <td class="c"><?= $it['check_result'] === 'yes' ? '✓' : '' ?></td>
                <td class="c"><?= $it['check_result'] === 'no' ? '✓' : '' ?></td>
                <td class="c<?= $it['is_required'] ? ' shade' : '' ?>"><?= $it['check_result'] === 'na' ? '✓' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="note">หมายเหตุ ช่องแรเงาในคอลัมน์ “ไม่เกี่ยวข้อง” หมายถึง รายการที่ต้องมีทุกรายวิชา</div>

    <h3>ตอนที่ 2 การประเมินคุณภาพของแผนการจัดการเรียนรู้</h3>
    <table class="grid">
        <thead>
        <tr><th rowspan="2" style="width:38px">ข้อ</th><th rowspan="2">รายการประเมิน</th><th colspan="5">ระดับคุณภาพ</th></tr>
        <tr><?php foreach ([5, 4, 3, 2, 1] as $n): ?><th class="c"><?= $n ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php $lastCat = null; $no = 0; foreach ($items['quality'] as $it): $no++; ?>
            <?php if ($it['category'] !== $lastCat): $lastCat = $it['category']; ?>
                <tr class="sec"><td colspan="7"><?= e($it['category']) ?></td></tr>
            <?php endif; ?>
            <tr>
                <td class="c"><?= $no ?></td>
                <td><?= e($it['title']) ?></td>
                <?php foreach ([5, 4, 3, 2, 1] as $n): ?><td class="c"><?= (int)$it['score'] === $n ? '✓' : '' ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        <tr class="sec"><td colspan="2" style="text-align:right">รวมคะแนนแต่ละระดับ</td>
            <?php foreach ([5, 4, 3, 2, 1] as $n): ?><td class="c"><?= $levelCount[$n] * $n ?></td><?php endforeach; ?></tr>
        <tr class="sec"><td colspan="2" style="text-align:right">รวมคะแนนทั้งหมด (คะแนนเต็ม <?= (int)$ev['max_score'] ?> คะแนน)</td>
            <td colspan="5" style="text-align:center"><?= (int)$ev['total_score'] ?></td></tr>
        </tbody>
    </table>

    <h3>สรุปผลการประเมินรายด้าน</h3>
    <table class="grid">
        <thead><tr><th>ด้านที่ประเมิน</th><th style="width:60px">จำนวนข้อ</th><th style="width:70px">คะแนนเต็ม</th><th style="width:75px">คะแนนที่ได้</th><th style="width:70px">ค่าเฉลี่ย</th></tr></thead>
        <tbody>
        <?php foreach ($cats as $cat => $c): ?>
            <tr><td><?= e($cat) ?></td><td class="c" style="width:auto"><?= $c['n'] ?></td><td class="c" style="width:auto"><?= $c['n'] * 5 ?></td>
                <td class="c" style="width:auto"><?= $c['sum'] ?></td><td class="c" style="width:auto"><?= number_format($c['avg'], 2) ?></td></tr>
        <?php endforeach; ?>
        <tr class="sec"><td style="text-align:center">รวม</td><td class="c" style="width:auto"><?= (int)$ev['item_count'] ?></td><td class="c" style="width:auto"><?= (int)$ev['max_score'] ?></td>
            <td class="c" style="width:auto"><?= (int)$ev['total_score'] ?></td><td class="c" style="width:auto"><?= number_format((float)$ev['avg_score'], 2) ?></td></tr>
        </tbody>
    </table>

    <h3>การแปลผล <span style="font-weight:400">ค่าเฉลี่ย = คะแนนรวมที่ได้ ÷ จำนวนข้อ</span></h3>
    <table class="grid">
        <thead><tr><th>ค่าเฉลี่ย</th><th>ร้อยละของคะแนนเต็ม</th><th>ระดับคุณภาพ</th></tr></thead>
        <tbody>
        <?php foreach (plan_eval_level_table() as [$range, $pct, $label]): ?>
            <tr><td style="text-align:center"><?= e($range) ?></td><td style="text-align:center"><?= e($pct) ?></td><td style="text-align:center"><?= e($label) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="note"><strong>เกณฑ์การผ่าน</strong> ค่าเฉลี่ยรวมตั้งแต่ 3.51 ขึ้นไป (ระดับดี) และตอนที่ 1 รายการที่ต้องมีทุกรายวิชาต้อง “มี” ครบทุกรายการ</div>
    <div style="margin-top:8px">
        ค่าเฉลี่ยรวม <span class="dot"><?= number_format((float)$ev['avg_score'], 2) ?></span>
        &nbsp; ระดับคุณภาพ <span class="dot"><?= e(plan_eval_level((float)$ev['avg_score'])) ?></span>
        &nbsp; <?= $box((bool)$ev['is_passed']) ?> ผ่านเกณฑ์ &nbsp; <?= $box(!$ev['is_passed']) ?> ไม่ผ่านเกณฑ์
    </div>

    <h3>ตอนที่ 3 ข้อเสนอแนะและผลการพิจารณา</h3>
    <div style="font-weight:600">จุดเด่นของแผนการจัดการเรียนรู้</div>
    <div class="box"><?= e($ev['strengths'] ?: '-') ?></div>
    <div style="font-weight:600;margin-top:6px">สิ่งที่ควรปรับปรุง/พัฒนา</div>
    <div class="box"><?= e($ev['improvements'] ?: '-') ?></div>
    <div style="font-weight:600;margin-top:6px">ผลการพิจารณา</div>
    <?php foreach (plan_eval_decisions() as $k => $label): ?>
        <div class="opt"><?= $box($ev['decision'] === $k) ?> <?= e($label) ?><?php if ($k === 'resubmit'): ?>
            ภายในวันที่ <span class="dot"><?= $ev['decision'] === 'resubmit' && $ev['resubmit_by'] ? e(thai_date($ev['resubmit_by'], false, true)) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' ?></span><?php endif; ?></div>
    <?php endforeach; ?>

    <div class="sign">
        ลงชื่อ ........................................................ ผู้ประเมิน<br>
        ( <?= e($ev['evaluator_name'] ?? '....................................................') ?> )<br>
        ตำแหน่ง <?= e($ev['evaluator_position'] ?: '....................................................') ?><br>
        วันที่ <?= (int)date('j', $evDate) ?> เดือน <?= e(thai_months(false)[(int)date('n', $evDate)]) ?> พ.ศ. <?= (int)date('Y', $evDate) + 543 ?>
    </div>
    <div style="margin-top:14px;font-size:12px;color:#666;text-align:right">
        <?= $ev['acknowledged_at'] ? 'ครูผู้สอนรับทราบผลเมื่อ ' . e(thai_datetime($ev['acknowledged_at'])) . ' · ' : '' ?>พิมพ์จากระบบ <?= e(system_name()) ?> เมื่อ <?= e(thai_datetime(date('Y-m-d H:i:s'))) ?>
    </div>
</div>
</body>
</html>
