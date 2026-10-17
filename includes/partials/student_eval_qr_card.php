<?php
/**
 * ไฟล์: includes/partials/student_eval_qr_card.php
 * การ์ดแสดง QR Code + ลิงก์แบบประเมินสำหรับนักเรียน (แสดงเฉพาะ admin และครูเจ้าของรายการ)
 * ต้องมีตัวแปร: $se (จาก student_eval_fetch) — หน้าที่ใช้ต้องตั้ง $useQr = true ก่อน include footer
 */
$evalUrl = student_eval_url($se['token']);
?>
<div class="card h-100 text-center">
    <div class="card-header"><i class="bi bi-qr-code me-2"></i>QR Code ให้นักเรียนสแกนประเมิน</div>
    <div class="card-body">
        <div class="d-inline-block p-2 bg-white border rounded mb-3" data-qr="<?= e($evalUrl) ?>" data-qr-size="200" aria-label="QR Code แบบประเมิน"></div>
        <div class="input-group input-group-sm mb-2">
            <input type="text" class="form-control" value="<?= e($evalUrl) ?>" readonly id="evalUrl<?= (int)$se['id'] ?>" aria-label="ลิงก์แบบประเมิน">
            <button class="btn btn-outline-secondary" type="button" data-copy="#evalUrl<?= (int)$se['id'] ?>" title="คัดลอกลิงก์"><i class="bi bi-clipboard"></i></button>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a href="<?= e(url('reports/student_eval_qr.php?id=' . (int)$se['id'])) ?>" target="_blank" class="btn btn-sm btn-accent"><i class="bi bi-printer me-1"></i>พิมพ์ป้าย QR</a>
            <a href="<?= e($evalUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right me-1"></i>เปิดแบบประเมิน</a>
        </div>
        <?php if (site_url_is_local()): ?>
            <div class="alert alert-warning small mt-3 mb-0 text-start">
                <i class="bi bi-exclamation-triangle me-1"></i>ลิงก์นี้เป็น <strong>localhost</strong> โทรศัพท์นักเรียนจะเปิดไม่ได้
                ให้ผู้ดูแลระบบตั้ง “URL หลักของระบบ” ในหน้าตั้งค่าระบบ</div>
        <?php endif; ?>
        <div class="small text-muted mt-2">นักเรียนไม่ต้องเป็นสมาชิก · ไม่ระบุตัวตน · 1 เครื่อง/เบราว์เซอร์ ประเมินได้ 1 ครั้ง</div>
    </div>
</div>
