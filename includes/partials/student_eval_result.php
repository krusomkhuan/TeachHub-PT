<?php
/**
 * ไฟล์: includes/partials/student_eval_result.php
 * แสดงสรุปผลการประเมินการสอนโดยนักเรียน: จำนวนผู้ตอบ ค่าเฉลี่ย รายด้าน รายข้อ (การกระจายคะแนน) และข้อเสนอแนะ
 * ต้องมีตัวแปร: $sum (จาก student_eval_summary)
 * ตัวแปรเสริม: $chartId (id ของ canvas กรณีแสดงหลายชุดในหน้าเดียว)
 * จะเพิ่มสคริปต์กราฟลงใน $pageScripts
 */
$chartId = $chartId ?? 'stuCatChart';
?>
<?php if ($sum['responses'] === 0): ?>
    <div class="card mb-4"><div class="empty-state"><i class="bi bi-people"></i>ยังไม่มีนักเรียนตอบแบบประเมิน</div></div>
<?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card h-100 text-center">
                <div class="card-body d-flex flex-column justify-content-center">
                    <div class="text-muted">คะแนนเฉลี่ยจากนักเรียน</div>
                    <div class="display-4 fw-bold text-primary-th"><?= e(number_format((float)$sum['avg'], 2)) ?></div>
                    <div class="mb-2">จากคะแนนเต็ม 5.00</div>
                    <div class="fs-5"><?= quality_badge((float)$sum['avg']) ?></div>
                    <hr>
                    <div><i class="bi bi-people-fill text-accent me-1"></i>ผู้ตอบ <strong><?= number_format($sum['responses']) ?></strong> คน</div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-bar-chart me-2"></i>คะแนนเฉลี่ยรายด้าน</div>
                <div class="card-body"><div class="chart-box-sm"><canvas id="<?= e($chartId) ?>"></canvas></div></div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-table me-2"></i>ผลรายข้อ (จำนวนนักเรียนที่เลือกแต่ละระดับ)</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle text-center">
                <thead>
                <tr><th class="text-start">รายการประเมิน</th>
                    <?php foreach (student_score_labels() as $n => $lb): ?><th style="width:70px"><?= $n ?><div class="small fw-normal"><?= e($lb) ?></div></th><?php endforeach; ?>
                    <th style="width:80px">เฉลี่ย</th><th style="width:110px">ระดับ</th></tr>
                </thead>
                <tbody>
                <?php $lastCat = null; $no = 0; foreach ($sum['items'] as $it): $no++; ?>
                    <?php if ($it['category'] !== $lastCat): $lastCat = $it['category']; ?>
                        <tr class="table-light"><td colspan="8" class="text-start fw-semibold"><?= e($it['category']) ?>
                            <span class="float-end text-muted small">เฉลี่ย <?= number_format($sum['categories'][$it['category']] ?? 0, 2) ?></span></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-start"><?= $no ?>. <?= e($it['criteria_title']) ?></td>
                        <?php foreach ([5, 4, 3, 2, 1] as $n): ?><td><?= (int)$it['s' . $n] ?: '<span class="text-muted">-</span>' ?></td><?php endforeach; ?>
                        <td class="fw-semibold"><?= number_format((float)$it['avg_score'], 2) ?></td>
                        <td class="small"><?= e(quality_level((float)$it['avg_score'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-chat-quote me-2"></i>ข้อเสนอแนะจากนักเรียน (<?= count($sum['comments']) ?>)</div>
        <?php if ($sum['comments']): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($sum['comments'] as $c): ?>
                    <li class="list-group-item">
                        <div><?= nl2br(e($c['comment'])) ?></div>
                        <small class="text-muted"><?= e(thai_datetime($c['created_at'])) ?> · ไม่ระบุชื่อ</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty-state small">ไม่มีข้อเสนอแนะ</div>
        <?php endif; ?>
    </div>
    <?php
    $pageScripts = ($pageScripts ?? '') . 'document.addEventListener("DOMContentLoaded", function () {
        var d = ' . json_encode(['labels' => array_keys($sum['categories']), 'data' => array_values($sum['categories'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
        var el = document.getElementById(' . json_encode($chartId) . '); if (!el) return;
        new Chart(el, { type: "bar",
            data: { labels: d.labels, datasets: [{ label: "คะแนนเฉลี่ย", data: d.data, backgroundColor: TeachHub.cssVar("--th-accent", "#f47b20"), borderRadius: 4 }] },
            options: { indexAxis: "y", scales: { x: { beginAtZero: true, max: 5 } }, plugins: { legend: { display: false } } } });
    });';
    ?>
<?php endif; ?>
