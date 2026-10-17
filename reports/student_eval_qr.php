<?php
/**
 * ไฟล์: reports/student_eval_qr.php?id=xx
 * ป้าย QR Code (A4/A5) สำหรับติดหน้าห้องหรือฉายขึ้นจอ ให้นักเรียนสแกนประเมินการสอน
 * สิทธิ์: admin และครูเจ้าของรายการเท่านั้น (ป้องกันผู้อื่นนำลิงก์ไปกรอกแทน)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$se = student_eval_fetch(get_int('id'));
if (!$se || !can_see_student_eval_qr($user, $se)) {
    abort_page(404, 'ไม่พบแบบประเมิน');
}
$evalUrl = student_eval_url($se['token']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR Code ประเมินการสอน - <?= e($se['teacher_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 15mm; }
        body { font-family: 'Sarabun', sans-serif; background: #e9ecef; margin: 0; color: #111; }
        .sheet { width: 180mm; margin: 16px auto; background: #fff; padding: 14mm; text-align: center; border-top: 10px solid #f47b20; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .sheet img.logo { width: 70px; height: 70px; object-fit: contain; }
        h1 { font-size: 28px; margin: 8px 0 2px; color: #1b2a4e; }
        .sub { font-size: 17px; }
        #qr { display: inline-block; padding: 12px; border: 3px solid #1b2a4e; border-radius: 16px; margin: 18px 0 10px; }
        .teacher { font-size: 24px; font-weight: 700; color: #1b2a4e; }
        .info { font-size: 18px; }
        .steps { text-align: left; display: inline-block; font-size: 16px; margin-top: 14px; }
        .url { font-size: 12px; color: #555; word-break: break-all; margin-top: 10px; }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button { font-family: inherit; font-size: 15px; padding: 8px 18px; border-radius: 8px; border: 0; background: #f47b20; color: #fff; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0 auto; } .toolbar { display: none; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">🖨 พิมพ์ป้าย QR Code</button></div>
<div class="sheet">
    <img src="<?= e(logo_url()) ?>" alt="โลโก้" class="logo">
    <div class="sub"><?= e(college_name()) ?></div>
    <h1>ประเมินการสอนของครู</h1>
    <div class="sub">สแกน QR Code ด้วยกล้องโทรศัพท์ · ไม่ต้องสมัครสมาชิก · ไม่ต้องใส่ชื่อ</div>
    <div id="qr"></div>
    <div class="teacher"><?= e($se['teacher_name']) ?></div>
    <div class="info"><?= e($se['subject_name']) ?><?= $se['classroom'] ? ' · ' . e($se['classroom']) : '' ?></div>
    <div class="info">ประเมินได้ถึง <?= e(thai_datetime($se['close_at'], true)) ?></div>
    <div class="steps">
        1. เปิดกล้องโทรศัพท์ สแกน QR Code ด้านบน<br>
        2. ให้คะแนนแต่ละข้อ 1–5 ตามความเป็นจริง<br>
        3. กด “ส่งแบบประเมิน” (ประเมินได้ 1 ครั้งต่อเครื่อง)
    </div>
    <div class="url"><?= e($evalUrl) ?></div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    new QRCode(document.getElementById('qr'), { text: <?= json_encode($evalUrl) ?>, width: 300, height: 300, correctLevel: QRCode.CorrectLevel.M });
</script>
</body>
</html>
