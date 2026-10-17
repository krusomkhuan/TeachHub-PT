<?php
/**
 * ไฟล์: includes/footer.php
 * ส่วนท้ายของหน้าหลังบ้าน — โหลดสคริปต์และแสดงข้อความแจ้งเตือน (flash) ด้วย SweetAlert2
 *
 * ตัวแปรเสริม:
 *   $pageScripts = โค้ด JavaScript เพิ่มเติมของหน้า (string) จะถูกวางหลังโหลดไลบรารีทั้งหมด
 */
$pageScripts = $pageScripts ?? '';
$flashes = flash_get();
?>
        </main>
        <footer class="app-footer">
            &copy; <?= thai_year() ?> <?= e(college_name()) ?> · <?= e(system_name()) ?> —
            “<?= e(setting('slogan', 'ทุกงานวิชาการครบ จบในที่เดียว')) ?>”
            <span class="d-block d-sm-inline ms-sm-2 text-muted">พัฒนาระบบโดย <?= e(APP_DEVELOPER) ?></span>
        </footer>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<?php if (!empty($useQr)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if ($flashes): ?>
<script>
    // แสดงข้อความแจ้งเตือนจากเซิร์ฟเวอร์
    TeachHub.showFlashes(<?= json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
</script>
<?php endif; ?>
<?php if ($pageScripts !== ''): ?>
<script>
<?= $pageScripts /* สคริปต์ที่หน้าสร้างเอง ข้อมูลภายในผ่าน json_encode แล้ว */ ?>
</script>
<?php endif; ?>
</body>
</html>
