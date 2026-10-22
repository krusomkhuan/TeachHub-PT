<?php
/**
 * ไฟล์: public/downloads.php
 * ดาวน์โหลดเอกสาร/แบบฟอร์มต้นฉบับ แยกแท็บตามหมวดหมู่ที่ admin กำหนด (ตาราง download_categories)
 */
require_once __DIR__ . '/../config/config.php';

$categories = download_categories(true);   // แท็บหมวด: เฉพาะหมวดที่เปิดใช้งาน
$catNames = download_categories();         // ชื่อหมวดของเอกสารทุกหมวด (รวมหมวดที่ปิดแล้ว)
$cat = get('cat');
if (!isset($categories[$cat])) {
    $cat = '';
}

$sql = 'SELECT d.id, d.title, d.description, d.category, d.original_name, d.file_size, d.download_count, d.created_at
        FROM downloads d LEFT JOIN download_categories c ON c.code = d.category
        WHERE d.is_active = 1 AND d.stored_name IS NOT NULL';
$types = '';
$params = [];
if ($cat !== '') {
    $sql .= ' AND d.category = ?';
    $types .= 's';
    $params[] = $cat;
}
// ค้นหาจากชื่อเอกสาร คำอธิบาย และชื่อไฟล์ (ใช้ร่วมกับแท็บหมวดได้)
$q = mb_substr(trim(get('q')), 0, 100);
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' AND (d.title LIKE ? OR d.description LIKE ? OR d.original_name LIKE ?)';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
$qs = $q !== '' ? '&q=' . rawurlencode($q) : '';
$sql .= ' ORDER BY c.sort_order, d.title';
$docs = db_all($sql, $types, $params);

// จำนวนเอกสารในแต่ละหมวด (ตามคำค้น ถ้ามี)
$counts = [];
$countSql = 'SELECT category, COUNT(*) AS c FROM downloads WHERE is_active = 1 AND stored_name IS NOT NULL'
    . ($q !== '' ? ' AND (title LIKE ? OR description LIKE ? OR original_name LIKE ?)' : '') . ' GROUP BY category';
foreach (db_all($countSql, $q !== '' ? 'sss' : '', $q !== '' ? [$like, $like, $like] : []) as $r) {
    $counts[$r['category']] = (int)$r['c'];
}

$pageTitle = 'ดาวน์โหลดเอกสาร';
$activeNav = 'downloads';
require APP_ROOT . '/includes/public_header.php';
?>
<div class="container py-4">
    <h3 class="section-title">ดาวน์โหลดเอกสาร / แบบฟอร์มต้นฉบับ</h3>

    <!-- ค้นหาเอกสาร -->
    <form method="get" class="mb-3" role="search">
        <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
        <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเอกสาร เช่น หลักสูตร ช่างยนต์ แบบฟอร์ม" aria-label="ค้นหาเอกสาร">
            <?php if ($q !== ''): ?><a href="<?= e(url('public/downloads.php' . ($cat !== '' ? '?cat=' . rawurlencode($cat) : ''))) ?>" class="btn btn-outline-secondary" title="ล้างคำค้น"><i class="bi bi-x-lg"></i></a><?php endif; ?>
            <button class="btn btn-primary px-4">ค้นหา</button>
        </div>
    </form>

    <!-- แท็บหมวดหมู่ -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="<?= e(url('public/downloads.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''))) ?>" class="btn btn-sm <?= $cat === '' ? 'btn-primary' : 'btn-outline-primary' ?>">
            ทั้งหมด <span class="badge bg-light text-dark ms-1"><?= array_sum($counts) ?></span>
        </a>
        <?php foreach ($categories as $k => $label): ?>
            <a href="?cat=<?= e(rawurlencode($k) . $qs) ?>" class="btn btn-sm <?= $cat === $k ? 'btn-primary' : 'btn-outline-primary' ?>">
                <?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$k] ?? 0 ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($docs): ?>
        <div class="row g-3">
            <?php foreach ($docs as $d): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body d-flex gap-3">
                            <i class="bi <?= e(file_icon($d['original_name'])) ?>" style="font-size:2.6rem"></i>
                            <div class="flex-grow-1 min-w-0">
                                <span class="badge bg-light text-dark border mb-1"><?= e($catNames[$d['category']] ?? '') ?></span>
                                <h6 class="fw-bold mb-1"><?= e($d['title']) ?></h6>
                                <?php if ($d['description']): ?><p class="small text-muted mb-2"><?= e($d['description']) ?></p><?php endif; ?>
                                <div class="small text-muted">
                                    <?= e(strtoupper(pathinfo((string)$d['original_name'], PATHINFO_EXTENSION))) ?> ·
                                    <?= e(format_bytes((int)$d['file_size'])) ?> ·
                                    <i class="bi bi-download"></i> <?= number_format((int)$d['download_count']) ?> ครั้ง
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 pt-0 pb-3 px-3">
                            <a href="<?= e(url('public/download.php?id=' . (int)$d['id'])) ?>" class="btn btn-accent btn-sm w-100">
                                <i class="bi bi-download me-1"></i> ดาวน์โหลด
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card"><div class="empty-state"><i class="bi bi-folder2-open"></i><?= $q !== '' ? 'ไม่พบเอกสารที่ตรงกับ “' . e($q) . '”' : 'ยังไม่มีเอกสารในหมวดนี้' ?></div></div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/public_footer.php'; ?>
