<?php
/**
 * ไฟล์: reports/supervision_report_print.php?id=xx
 * พิมพ์ "บันทึกข้อความ" รายงานผลการนิเทศการสอน (A4) พร้อมช่องความเห็น/ลงนาม 3 ขั้น และภาคผนวกภาพการนิเทศ
 * ใช้ "พิมพ์ → บันทึกเป็น PDF" จากเบราว์เซอร์
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$rep = report_fetch(get_int('id'));
if (!$rep || !can_view_report($user, $rep)) {
    abort_page(404, 'ไม่พบรายงาน');
}
$data = report_data($rep);
$st = $data['stats'];
$chain = report_approval_chain();
$opinions = $rep['status'] === 'draft' ? [] : report_latest_opinions((int)$rep['id']);
$deptShort = preg_replace('/^แผนกวิชา/u', '', $rep['department_name']);
$memoDate = $rep['submitted_at'] ?: date('Y-m-d');
$watermark = ['draft' => 'ฉบับร่าง', 'returned' => 'ส่งกลับแก้ไข', 'pending' => 'อยู่ระหว่างพิจารณา', 'approved' => ''][$rep['status']];
// รูปภาคผนวก (สูงสุด 12 รูป)
$photos = [];
foreach ($data['details'] as $d) {
    foreach ($d['images'] as $img) {
        if (count($photos) < 12) {
            $photos[] = ['id' => $img, 'caption' => $d['teacher_name'] . ' · ' . thai_date($d['supervise_date']) . ' · ' . $d['subject_name']];
        }
    }
}
log_activity('report_print', 'พิมพ์รายงานการนิเทศ #' . (int)$rep['id']);
$dots = fn(int $n = 50) => str_repeat('.', $n);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($rep['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 20mm 20mm 20mm 30mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; font-size: 16px; line-height: 1.55; color: #000; background: #e9ecef; margin: 0; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 20mm 20mm 20mm 30mm; position: relative;
                 box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .memo-title { font-size: 29px; font-weight: 700; text-align: center; margin: 0 0 10px; letter-spacing: 1px; }
        .row { display: flex; gap: 8px; }
        .lbl { font-weight: 700; white-space: nowrap; }
        .line { border-bottom: 1px dotted #000; flex: 1; padding: 0 4px; }
        .indent { text-indent: 2.5cm; text-align: justify; }
        h4 { font-size: 16px; margin: 12px 0 2px; }
        .box { white-space: pre-wrap; margin-left: 1cm; text-align: justify; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin: 4px 0 6px; }
        th, td { border: 1px solid #000; padding: 2px 5px; text-align: center; }
        th { background: #f0f0f0; }
        td.l { text-align: left; }
        .sign { width: 60%; margin-left: 40%; text-align: center; margin-top: 18px; page-break-inside: avoid; }
        .sign .s { margin-top: 26px; }
        .approve { border: 1px solid #000; padding: 8px 12px; margin-top: 10px; page-break-inside: avoid; }
        .approve .head { font-weight: 700; }
        .approve .comment { min-height: 40px; white-space: pre-wrap; border-bottom: 1px dotted #999; margin: 4px 0; }
        .esign { font-size: 12px; color: #444; }
        .watermark { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 80px; font-weight: 700;
                     color: rgba(220, 53, 69, .12); transform: rotate(-25deg); pointer-events: none; }
        .photos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .photos figure { margin: 0; text-align: center; page-break-inside: avoid; }
        .photos img { width: 100%; height: 62mm; object-fit: cover; border: 1px solid #999; }
        .photos figcaption { font-size: 13px; }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button { font-family: inherit; font-size: 15px; padding: 8px 18px; border-radius: 8px; border: 0; background: #f47b20; color: #fff; cursor: pointer; }
        .page-break { page-break-before: always; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            .toolbar { display: none; }
        }
        @media screen and (max-width: 820px) { .sheet { width: 100%; padding: 16px; min-height: auto; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">🖨 พิมพ์ / บันทึกเป็น PDF</button>
    <div style="font-size:13px;color:#555;margin-top:6px">เลือกเครื่องพิมพ์ “บันทึกเป็น PDF” เพื่อเก็บเป็นไฟล์</div>
</div>

<div class="sheet">
    <?php if ($watermark): ?><div class="watermark"><?= e($watermark) ?></div><?php endif; ?>
    <div class="memo-title">บันทึกข้อความ</div>
    <div class="row"><span class="lbl">ส่วนราชการ</span><span class="line"><?= e($rep['department_name']) ?> <?= e(college_name()) ?></span></div>
    <div class="row">
        <span class="lbl">ที่</span><span class="line" style="flex:.8"></span>
        <span class="lbl">วันที่</span><span class="line"><?= e(thai_date($memoDate, false, true)) ?></span>
    </div>
    <div class="row"><span class="lbl">เรื่อง</span><span class="line"><?= e($rep['title']) ?></span></div>
    <hr style="border:0;border-top:1px solid #000;margin:8px 0">
    <div><span class="lbl">เรียน</span> ผู้อำนวยการ<?= e(college_name()) ?></div>

    <p class="indent" style="margin-top:8px">
        ตามที่<?= e($rep['department_name']) ?> ได้ดำเนินการนิเทศการสอนครูผู้สอนในแผนกวิชา ประจำภาคเรียนที่ <?= e($rep['ay_semester'] . '/' . $rep['ay_year']) ?>
        <?php if ($rep['period_start'] || $rep['period_end']): ?>ระหว่างวันที่ <?= e(thai_date($rep['period_start'], false, true)) ?> ถึงวันที่ <?= e(thai_date($rep['period_end'], false, true)) ?><?php endif; ?>
        เพื่อส่งเสริมและพัฒนาคุณภาพการจัดการเรียนรู้ บัดนี้ได้ดำเนินการเรียบร้อยแล้ว จึงขอรายงานผลการนิเทศ ดังนี้
    </p>

    <h4>1. ข้อมูลการนิเทศ</h4>
    <div class="box">ครูในแผนกวิชา <?= (int)$st['teachers'] ?> คน ได้รับการนิเทศ <?= (int)$st['supervised'] ?> คน คิดเป็นร้อยละ <?= e((string)$st['percent']) ?> รวม <?= (int)$st['times'] ?> ครั้ง
คะแนนเฉลี่ยผลการนิเทศ <?= $st['avg'] !== null ? number_format((float)$st['avg'], 2) : '-' ?> จากคะแนนเต็ม 5.00 อยู่ในระดับ <?= e($st['quality']) ?><?php if ($st['student_avg'] !== null): ?>

ผลการประเมินการสอนโดยนักเรียน นักศึกษา คะแนนเฉลี่ย <?= number_format((float)$st['student_avg'], 2) ?> อยู่ในระดับ <?= e(quality_level((float)$st['student_avg'])) ?><?php endif; ?></div>

    <table>
        <thead><tr><th style="width:30px">ที่</th><th>ชื่อ-สกุล</th><th>นิเทศ<br>(ครั้ง)</th><th>คะแนน<br>เฉลี่ย</th><th>ระดับคุณภาพ</th><th>คะแนนจาก<br>นักเรียน</th></tr></thead>
        <tbody>
        <?php foreach ($data['teachers'] as $i => $t): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="l"><?= e($t['name']) ?></td>
                <td><?= (int)$t['times'] ?: '-' ?></td>
                <td><?= $t['avg_score'] !== null ? number_format((float)$t['avg_score'], 2) : '-' ?></td>
                <td><?= $t['avg_score'] !== null ? e(quality_level((float)$t['avg_score'])) : 'ยังไม่ได้นิเทศ' ?></td>
                <td><?= $t['student_avg'] !== null ? number_format((float)$t['student_avg'], 2) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($data['categories']): ?>
        <table>
            <thead><tr><th>ด้านการประเมิน</th><th style="width:90px">คะแนนเฉลี่ย</th><th style="width:110px">ระดับคุณภาพ</th></tr></thead>
            <tbody>
            <?php foreach ($data['categories'] as $cat => $avg): ?>
                <tr><td class="l"><?= e($cat) ?></td><td><?= number_format($avg, 2) ?></td><td><?= e(quality_level($avg)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h4>2. สรุปผลการนิเทศโดยภาพรวม</h4>
    <div class="box"><?= e($rep['summary_overall'] ?: '-') ?></div>
    <h4>3. จุดเด่น</h4>
    <div class="box"><?= e($rep['strengths'] ?: '-') ?></div>
    <h4>4. ปัญหา/อุปสรรค</h4>
    <div class="box"><?= e($rep['problems'] ?: '-') ?></div>
    <h4>5. ข้อเสนอแนะ/แนวทางพัฒนา</h4>
    <div class="box"><?= e($rep['suggestions'] ?: '-') ?></div>

    <p class="indent" style="margin-top:12px">จึงเรียนมาเพื่อโปรดทราบและพิจารณา</p>

    <div class="sign">
        <div class="s">(ลงชื่อ) <?= $dots(45) ?></div>
        <div>( <?= e($rep['author_name']) ?> )</div>
        <div>หัวหน้าแผนกวิชา<?= e($deptShort) ?></div>
        <?php if ($rep['submitted_at'] && $rep['status'] !== 'draft'): ?><div class="esign">เสนอผ่านระบบ <?= e(system_name()) ?> เมื่อ <?= e(thai_datetime($rep['submitted_at'])) ?></div><?php endif; ?>
    </div>

    <?php foreach ($chain as $i => $s): $o = $opinions[$i] ?? null; ?>
        <div class="approve">
            <div class="head"><?= $i === 3 ? 'ความเห็น/ข้อสั่งการ' : 'ความเห็น' ?><?= e($s['title']) ?></div>
            <?php if ($o): ?>
                <div>☑ <?= e(report_action_label($o['action'], $i)) ?></div>
                <div class="comment"><?= e($o['comment'] ?: ' ') ?></div>
            <?php else: ?>
                <div><?= $i === 3 ? '☐ ทราบ/อนุมัติ &nbsp;&nbsp; ☐ อื่น ๆ' : '☐ เห็นควรเสนอ &nbsp;&nbsp; ☐ อื่น ๆ' ?></div>
                <div class="comment"> </div>
            <?php endif; ?>
            <div class="sign" style="margin-top:6px">
                <div class="s">(ลงชื่อ) <?= $dots(45) ?></div>
                <div>( <?= e($o ? (string)$o['user_name'] : ($s['name'] ?: $dots(40))) ?> )</div>
                <div><?= e($s['title']) ?></div>
                <div><?= $o ? 'วันที่ ' . e(thai_date($o['created_at'], false, true)) : 'วันที่ ' . $dots(30) ?></div>
                <?php if ($o): ?><div class="esign">พิจารณาผ่านระบบ <?= e(system_name()) ?> เมื่อ <?= e(thai_datetime($o['created_at'])) ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($photos): ?>
        <div class="page-break"></div>
        <div class="memo-title" style="font-size:22px">ภาคผนวก ภาพการนิเทศการสอน</div>
        <div style="text-align:center;margin-bottom:10px"><?= e($rep['department_name']) ?> ภาคเรียนที่ <?= e($rep['ay_semester'] . '/' . $rep['ay_year']) ?></div>
        <div class="photos">
            <?php foreach ($photos as $p): ?>
                <figure>
                    <img src="<?= e(url('supervision_image.php?id=' . (int)$p['id'])) ?>" alt="ภาพการนิเทศ">
                    <figcaption><?= e($p['caption']) ?></figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
