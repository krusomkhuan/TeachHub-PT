<?php
/**
 * ไฟล์: includes/partials/supervision_report_body.php
 * เนื้อหารายงานการนิเทศ: ตัวเลขสรุป, ผลรายครู, คะแนนรายด้าน, รายการนิเทศแต่ละครั้ง และข้อความที่หัวหน้าแผนกเขียน
 * ต้องมีตัวแปร: $rep (จาก report_fetch), $data (จาก report_data)
 */
$st = $data['stats'];
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= (int)$st['teachers'] ?></div><div class="stat-label">ครูในแผนก</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-person-check"></i></div><div><div class="stat-value"><?= (int)$st['supervised'] ?></div><div class="stat-label">ได้รับการนิเทศ (<?= e((string)$st['percent']) ?>%)</div><div class="stat-sub">รวม <?= (int)$st['times'] ?> ครั้ง</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-purple"><i class="bi bi-star"></i></div><div><div class="stat-value"><?= $st['avg'] !== null ? number_format((float)$st['avg'], 2) : '-' ?></div><div class="stat-label">คะแนนนิเทศเฉลี่ย</div><div class="stat-sub"><?= e($st['quality']) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-orange"><i class="bi bi-qr-code"></i></div><div><div class="stat-value"><?= $st['student_avg'] !== null ? number_format((float)$st['student_avg'], 2) : '-' ?></div><div class="stat-label">คะแนนจากนักเรียน</div><div class="stat-sub"><?= $st['student_avg'] !== null ? e(quality_level((float)$st['student_avg'])) : 'ยังไม่มีผล' ?></div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>ผลการนิเทศรายครู</div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 align-middle text-center">
            <thead><tr><th>#</th><th class="text-start">ชื่อ-สกุล</th><th>นิเทศ (ครั้ง)</th><th>นิเทศล่าสุด</th><th>คะแนนเฉลี่ย</th><th>ระดับคุณภาพ</th><th>รับทราบผล</th><th>คะแนนจากนักเรียน</th></tr></thead>
            <tbody>
            <?php foreach ($data['teachers'] as $i => $t): ?>
                <tr class="<?= (int)$t['times'] === 0 ? 'table-warning' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td class="text-start"><?= e($t['name']) ?><div class="small text-muted"><?= e($t['position']) ?></div></td>
                    <td><?= (int)$t['times'] ?: '<span class="text-danger small">ยังไม่นิเทศ</span>' ?></td>
                    <td class="small"><?= e(thai_date($t['last_date'])) ?></td>
                    <td><?= $t['avg_score'] !== null ? number_format((float)$t['avg_score'], 2) : '-' ?></td>
                    <td class="small"><?= $t['avg_score'] !== null ? e(quality_level((float)$t['avg_score'])) : '-' ?></td>
                    <td class="small"><?= (int)$t['times'] ? (int)$t['acked'] . '/' . (int)$t['times'] : '-' ?></td>
                    <td class="small"><?= $t['student_avg'] !== null ? number_format((float)$t['student_avg'], 2) . ' (' . (int)$t['student_n'] . ' คน)' : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['teachers']): ?><tr><td colspan="8" class="empty-state">ไม่มีครูในแผนก</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($data['categories']): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-bar-chart me-2"></i>คะแนนเฉลี่ยรายด้าน</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($data['categories'] as $cat => $avg): ?>
                    <tr><td><?= e($cat) ?></td><td style="width:45%"><div class="progress progress-thin mt-2"><div class="progress-bar bg-accent" style="width: <?= $avg * 20 ?>%"></div></div></td>
                        <td class="text-end fw-semibold" style="width:70px"><?= number_format($avg, 2) ?></td><td class="small" style="width:110px"><?= e(quality_level($avg)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-calendar-check me-2"></i>รายการนิเทศ (<?= count($data['details']) ?> ครั้ง)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>วันที่</th><th>ผู้รับการนิเทศ</th><th>วิชา/ห้อง</th><th>ผู้นิเทศ</th><th>คะแนน</th><th>รูป</th></tr></thead>
            <tbody>
            <?php foreach ($data['details'] as $d): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(thai_date($d['supervise_date'])) ?></td>
                    <td><?= e($d['teacher_name']) ?></td>
                    <td class="small"><?= e($d['subject_name']) ?><br><span class="text-muted"><?= e($d['classroom']) ?></span></td>
                    <td class="small"><?= e($d['supervisor_name']) ?></td>
                    <td><?= quality_badge((float)$d['avg_score']) ?></td>
                    <td class="text-nowrap">
                        <?php foreach ($d['images'] as $imgId): ?>
                            <a href="<?= e(url('supervision_image.php?id=' . (int)$imgId)) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('supervision_image.php?id=' . (int)$imgId)) ?>" alt="ภาพการนิเทศ" loading="lazy"
                                     style="width:48px;height:36px;object-fit:cover" class="rounded border"></a>
                        <?php endforeach; ?>
                        <?= $d['images'] ? '' : '<span class="text-muted small">-</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['details']): ?><tr><td colspan="6" class="empty-state">ยังไม่มีผลการนิเทศในช่วงเวลานี้</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php foreach (['summary_overall' => ['bi-clipboard-data', 'สรุปผลการนิเทศโดยภาพรวม'], 'strengths' => ['bi-star', 'จุดเด่น'],
                    'problems' => ['bi-exclamation-diamond', 'ปัญหา/อุปสรรค'], 'suggestions' => ['bi-lightbulb', 'ข้อเสนอแนะ/แนวทางพัฒนา']] as $k => [$ic, $label]): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi <?= $ic ?> me-2"></i><?= e($label) ?></div>
                <div class="card-body"><?= $rep[$k] ? nl2br(e($rep[$k])) : '<span class="text-muted">-</span>' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<div class="small text-muted mb-4"><i class="bi bi-database me-1"></i>
    <?= in_array($rep['status'], ['draft', 'returned'], true) ? 'ข้อมูลล่าสุด (ร่าง — ข้อมูลจะถูกบันทึกคงที่เมื่อกดเสนอรายงาน)' : 'ข้อมูล ณ วันที่เสนอ ' . e(thai_datetime($data['generated_at'])) ?></div>
