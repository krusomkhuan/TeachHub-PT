<?php
/**
 * ไฟล์: includes/partials/my_rounds.php
 * การ์ด "งานที่ต้องส่ง" ของผู้ใช้ปัจจุบัน (ใช้ในแดชบอร์ดครูและหัวหน้าแผนก)
 * - แสดงรอบที่เปิดรับ/รับส่งช้า พร้อมสถานะการส่งของตนเอง
 * - งานที่เหลือเวลาน้อยกว่า 3 วันและยังไม่ส่ง แสดงเป็นสีแดง
 * ต้องมีตัวแปร $user
 */
$myRounds = active_rounds();
?>
<div class="card h-100">
    <div class="card-header d-flex align-items-center">
        <i class="bi bi-alarm me-2"></i> งานที่เปิดรับ / ต้องส่ง
        <a href="<?= e(url('public/calendar.php')) ?>" class="ms-auto small fw-normal">ปฏิทิน <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="card-body p-0">
        <?php if (!$myRounds): ?>
            <div class="empty-state"><i class="bi bi-cup-hot"></i>ขณะนี้ไม่มีงานที่เปิดรับ</div>
        <?php endif; ?>
        <?php foreach ($myRounds as $r):
            $table = submission_table($r['type']);
            $mine = db_one(
                "SELECT COUNT(*) AS c, COALESCE(SUM(status = 'revision'), 0) AS rev, COALESCE(SUM(status = 'approved'), 0) AS ok
                 FROM `$table` WHERE round_id = ? AND teacher_id = ?",
                'ii',
                [(int)$r['id'], (int)$user['id']]
            );
            $sent = (int)$mine['c'] > 0;
            $state = round_state($r);
            $remain = strtotime($r['close_at']) - time();
            $urgent = !$sent && $state === 'open' && $remain < 3 * 86400;
            ?>
            <div class="deadline-item<?= $urgent ? ' urgent' : '' ?>">
                <div class="deadline-icon <?= $urgent ? 'ic-red' : ($sent ? 'ic-green' : 'ic-orange') ?>">
                    <i class="bi <?= e(submission_type_icon($r['type'])) ?>"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate"><?= e($r['name']) ?></div>
                    <div class="small text-muted">
                        <?= e(submission_type_label($r['type'])) ?> · ปิดรับ <?= e(thai_datetime($r['close_at'])) ?>
                    </div>
                    <div class="small mt-1">
                        <?php if ($state === 'open'): ?>
                            <i class="bi bi-hourglass-split"></i> เหลือ
                            <span class="countdown-box" data-countdown="<?= strtotime($r['close_at']) * 1000 ?>"><?= e(remaining_text($remain)) ?></span>
                        <?php else: ?>
                            <?= round_state_badge($r) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end flex-shrink-0">
                    <?php if ($sent): ?>
                        <?php if ((int)$mine['rev'] > 0): ?>
                            <?= status_badge('revision') ?>
                        <?php elseif ((int)$mine['ok'] === (int)$mine['c']): ?>
                            <?= status_badge('approved') ?>
                        <?php else: ?>
                            <?= status_badge('pending') ?>
                        <?php endif; ?>
                        <?php if ($r['type'] === 'lesson_plan'): ?>
                            <div class="small text-muted mt-1"><?= (int)$mine['c'] ?> วิชา</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= status_badge('not_submitted') ?>
                    <?php endif; ?>
                    <div class="mt-2">
                        <a href="<?= e(url(teacher_submit_page($r['type']) . '?round_id=' . (int)$r['id'])) ?>"
                           class="btn btn-sm <?= $urgent ? 'btn-danger' : 'btn-accent' ?>">
                            <i class="bi bi-upload me-1"></i><?= $sent && $r['type'] !== 'lesson_plan' ? 'ดู/แก้ไข' : 'ส่งงาน' ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
