<?php
/**
 * ไฟล์: hr/file.php?k=ชนิด&id=xx[&u=user_id]
 * เปิดไฟล์แนบของงานบุคลากร (uploads/hr) — ตรวจสิทธิ์ทุกครั้ง
 *   k=leave    ไฟล์แนบใบลา (id = hr_leaves.id)          → ผู้ที่ดูใบลาได้
 *   k=training เกียรติบัตร (id = hr_trainings.id)        → เจ้าของ + ผู้ดูรายงานบุคลากร
 *   k=order    ไฟล์คำสั่ง (id = hr_orders.id)           → ผู้ที่เห็นคำสั่ง
 *   k=attend   ภาพหลักฐานกิจกรรม (id = event_id, u = user_id) → เจ้าของ + ผู้ดูรายงานบุคลากร
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$id = get_int('id');
$stored = null;
$name = 'file';

switch (get('k')) {
    case 'leave':
        $row = hr_leave_fetch($id);
        if ($row && hr_leave_can_view($user, $row)) {
            [$stored, $name] = [$row['attach_stored'], $row['attach_original']];
        }
        break;
    case 'training':
        $row = db_one('SELECT * FROM hr_trainings WHERE id = ?', 'i', [$id]);
        if ($row && ((int)$row['user_id'] === (int)$user['id'] || can_view_hr_overview($user))) {
            [$stored, $name] = [$row['cert_stored'], $row['cert_original']];
        }
        break;
    case 'order':
        $row = db_one('SELECT * FROM hr_orders WHERE id = ?', 'i', [$id]);
        if ($row && hr_order_visible($user, $row)) {
            [$stored, $name] = [$row['file_stored'], $row['file_original']];
        }
        break;
    case 'attend':
        $owner = get_int('u');
        $row = db_one('SELECT * FROM hr_event_attendances WHERE event_id = ? AND user_id = ?', 'ii', [$id, $owner]);
        if ($row && ($owner === (int)$user['id'] || can_view_hr_overview($user))) {
            [$stored, $name] = [$row['photo_stored'], $row['photo_original']];
        }
        break;
}
if (!$stored) {
    abort_page(404, 'ไม่พบไฟล์', 'ไฟล์อาจถูกลบ หรือท่านไม่มีสิทธิ์เปิดไฟล์นี้');
}
// เปิดไฟล์ส่วนบุคคลของผู้อื่น (ใบลา/เกียรติบัตร/ภาพหลักฐาน) ต้องมีบันทึก — ไฟล์ของตัวเองและไฟล์คำสั่งไม่ต้องบันทึก
$ownerId = (int)($row['user_id'] ?? 0);
if ($ownerId > 0 && $ownerId !== (int)$user['id']) {
    $kinds = ['leave' => 'ไฟล์แนบใบลา #', 'training' => 'เกียรติบัตรการอบรม #', 'attend' => 'ภาพหลักฐานกิจกรรม #'];
    log_activity('hr_file_view', 'เปิด' . $kinds[get('k')] . $id . ' ของ ' . log_user_name($ownerId) . ': ' . $name);
}
send_file(HR_UPLOAD_DIR, $stored, (string)$name, true);
