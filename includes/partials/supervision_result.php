<?php
/**
 * ไฟล์: includes/partials/supervision_result.php
 * แสดงข้อมูลตารางนิเทศและผลการนิเทศ (คะแนนรายข้อ รายด้าน ค่าเฉลี่ย จุดเด่น ข้อเสนอแนะ รูปหลักฐาน)
 * ใช้ร่วมกันใน admin / supervisor / teacher / executive
 * ต้องมีตัวแปร: $sc (จาก schedule_fetch)
 * จะเพิ่มสคริปต์กราฟลงใน $pageScripts
 */
$resScores = $sc['result_id'] ? supervision_scores((int)$sc['result_id']) : [];
$resImages = $sc['result_id'] ? supervision_images((int)$sc['result_id']) : [];
$catAvg = supervision_category_avg($resScores);
?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-person-video3"></i> ข้อมูลการนิเทศ
        <span class="ms-auto"><?= schedule_status_badge($sc['status']) ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3 small">
            <div class="col-md-6">
                <div class="text-muted">ผู้รับการนิเทศ</div>
                <div class="fw-semibold fs-6"><?= e($sc['teacher_name']) ?></div>
                <div><?= e($sc['teacher_position']) ?><?= $sc['teacher_standing'] ? ' · ' . e($sc['teacher_standing']) : '' ?> · <?= e($sc['department_name'] ?? '-') ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted">ผู้นิเทศ</div>
                <div class="fw-semibold fs-6"><?= e($sc['supervisor_name']) ?></div>
                <div><?= e($sc['supervisor_position']) ?></div>
            </div>
            <div class="col-md-4"><div class="text-muted">วันที่นิเทศ</div><?= e(thai_date($sc['supervise_date'], false, true)) ?></div>
            <div class="col-md-4"><div class="text-muted">เวลา</div><?= e(substr($sc['start_time'], 0, 5)) ?>–<?= e(substr($sc['end_time'], 0, 5)) ?> น.</div>
            <div class="col-md-4"><div class="text-muted">ภาคเรียน</div><?= $sc['ay_year'] ? e($sc['ay_semester'] . '/' . $sc['ay_year']) : '-' ?></div>
            <div class="col-md-8"><div class="text-muted">รายวิชา</div><?= e($sc['subject_name'] ?: '-') ?></div>
            <div class="col-md-4"><div class="text-muted">ห้องเรียน/ชั้น</div><?= e($sc['classroom'] ?: '-') ?></div>
            <?php if ($sc['note']): ?><div class="col-12"><div class="text-muted">หมายเหตุ</div><?= e($sc['note']) ?></div><?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$sc['result_id']): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-hourglass-split"></i>ยังไม่มีผลการนิเทศ</div></div>
<?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card h-100 text-center">
                <div class="card-body d-flex flex-column justify-content-center">
                    <div class="text-muted">คะแนนเฉลี่ยรวม</div>
                    <div class="display-4 fw-bold text-primary-th"><?= e(number_format((float)$sc['avg_score'], 2)) ?></div>
                    <div class="mb-2">จากคะแนนเต็ม 5.00</div>
                    <div class="fs-5"><?= quality_badge((float)$sc['avg_score']) ?></div>
                    <hr>
                    <?php if ($sc['acknowledged_at']): ?>
                        <div class="text-success small"><i class="bi bi-check2-circle me-1"></i>ครูรับทราบผลแล้วเมื่อ<br><?= e(thai_datetime($sc['acknowledged_at'])) ?></div>
                    <?php else: ?>
                        <div class="text-warning small"><i class="bi bi-clock me-1"></i>ครูยังไม่ได้กดรับทราบผล</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-bullseye me-2"></i>คะแนนเฉลี่ยรายด้าน</div>
                <div class="card-body">
                    <?php if (count($catAvg) >= 3): ?>
                        <div class="chart-box-sm"><canvas id="catChart"></canvas></div>
                    <?php else: ?>
                        <?php foreach ($catAvg as $cat => $avg): ?>
                            <div class="d-flex justify-content-between small"><span><?= e($cat) ?></span><span><?= number_format($avg, 2) ?></span></div>
                            <div class="progress progress-thin mb-2"><div class="progress-bar bg-accent" style="width: <?= $avg * 20 ?>%"></div></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-table me-2"></i>ผลการประเมินรายข้อ</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle">
                <thead>
                <tr class="text-center"><th class="text-start">รายการประเมิน</th><?php foreach ([5, 4, 3, 2, 1] as $n): ?><th style="width:52px"><?= $n ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php $lastCat = null; foreach ($resScores as $s): ?>
                    <?php if ($s['category'] !== $lastCat): $lastCat = $s['category']; ?>
                        <tr class="table-light"><td colspan="6" class="fw-semibold"><?= e($s['category']) ?>
                            <span class="float-end text-muted small">เฉลี่ย <?= number_format($catAvg[$s['category']] ?? 0, 2) ?></span></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td><?= e($s['criteria_title']) ?></td>
                        <?php foreach ([5, 4, 3, 2, 1] as $n): ?>
                            <td class="text-center"><?= (int)$s['score'] === $n ? '<i class="bi bi-check-lg fs-5 text-success"></i>' : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header text-success"><i class="bi bi-star me-2"></i>จุดเด่น</div>
                <div class="card-body"><?= $sc['strengths'] ? nl2br(e($sc['strengths'])) : '<span class="text-muted">-</span>' ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header text-accent"><i class="bi bi-lightbulb me-2"></i>ข้อเสนอแนะ</div>
                <div class="card-body"><?= $sc['suggestions'] ? nl2br(e($sc['suggestions'])) : '<span class="text-muted">-</span>' ?></div>
            </div>
        </div>
    </div>

    <?php if ($resImages): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-images me-2"></i>รูปภาพหลักฐาน (<?= count($resImages) ?>)</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($resImages as $im): ?>
                        <div class="col-6 col-md-3">
                            <a href="<?= e(url('supervision_image.php?id=' . (int)$im['id'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('supervision_image.php?id=' . (int)$im['id'])) ?>" alt="หลักฐานการนิเทศ" loading="lazy"
                                     class="img-fluid rounded border" style="aspect-ratio:4/3;object-fit:cover;width:100%">
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php
    if (count($catAvg) >= 3) {
        $pageScripts = ($pageScripts ?? '') . 'document.addEventListener("DOMContentLoaded", function () {
            var d = ' . json_encode(['labels' => array_keys($catAvg), 'data' => array_values($catAvg)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
            new Chart(document.getElementById("catChart"), {
                type: "radar",
                data: { labels: d.labels, datasets: [{ label: "คะแนนเฉลี่ย", data: d.data, fill: true,
                    backgroundColor: "rgba(244,123,32,.2)", borderColor: TeachHub.cssVar("--th-accent", "#f47b20"), pointBackgroundColor: TeachHub.cssVar("--th-primary", "#1b2a4e") }] },
                options: { scales: { r: { min: 0, max: 5, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
            });
        });';
    }
    ?>
<?php endif; ?>
