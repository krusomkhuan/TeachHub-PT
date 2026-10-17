/**
 * ไฟล์: assets/js/app.js
 * สคริปต์กลางของระบบ
 *  - ยืนยันก่อนส่งฟอร์ม/ลบ ด้วย SweetAlert2
 *  - แสดง/ซ่อนรหัสผ่าน + วัดความแข็งแรงรหัสผ่าน
 *  - แจ้งเตือน flash เป็น Toast
 *  - นับถอยหลังวันปิดรับงาน (อิงเวลาเซิร์ฟเวอร์)
 *  - เปิดใช้ DataTables ภาษาไทยอัตโนมัติกับ <table class="datatable">
 *  - ค่าเริ่มต้นของ Chart.js (ฟอนต์ Sarabun)
 */
window.TeachHub = (function () {
    'use strict';

    var cssVar = function (name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    };

    // ชุดสีสำหรับกราฟ (น้ำเงินกรมท่า ส้ม และสีเสริม)
    var palette = ['#1b2a4e', '#f47b20', '#2a9d8f', '#e9c46a', '#8d5fd3', '#e76f51', '#4c8bf5', '#6c757d', '#43aa8b', '#b5838d'];
    var statusColors = { pending: '#ffc107', approved: '#198754', revision: '#dc3545', late: '#fd7e14', none: '#adb5bd' };

    // ------------------------------------------------------------
    // ภาษาไทยสำหรับ DataTables
    // ------------------------------------------------------------
    var dtThai = {
        emptyTable: 'ไม่มีข้อมูล',
        info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
        infoEmpty: 'แสดง 0 ถึง 0 จาก 0 รายการ',
        infoFiltered: '(กรองจากทั้งหมด _MAX_ รายการ)',
        lengthMenu: 'แสดง _MENU_ รายการ',
        loadingRecords: 'กำลังโหลด...',
        processing: 'กำลังประมวลผล...',
        search: 'ค้นหา:',
        zeroRecords: 'ไม่พบข้อมูลที่ค้นหา',
        paginate: { first: 'หน้าแรก', last: 'หน้าสุดท้าย', next: 'ถัดไป', previous: 'ก่อนหน้า' }
    };

    function initDataTables(root) {
        if (typeof DataTable === 'undefined') return;
        (root || document).querySelectorAll('table.datatable').forEach(function (tbl) {
            if (tbl.dataset.dtInit) return;
            tbl.dataset.dtInit = '1';
            var order = tbl.dataset.order ? JSON.parse(tbl.dataset.order) : [];
            new DataTable(tbl, {
                language: dtThai,
                order: order,
                pageLength: parseInt(tbl.dataset.pageLength || '25', 10),
                responsive: false,
                columnDefs: [{ targets: 'no-sort', orderable: false }]
            });
        });
    }

    // ------------------------------------------------------------
    // Toast แจ้งเตือน
    // ------------------------------------------------------------
    function toast(type, message) {
        if (typeof Swal === 'undefined') { alert(message); return; }
        Swal.fire({
            toast: true, position: 'top-end', timer: 3500, timerProgressBar: true,
            showConfirmButton: false, icon: type === 'error' ? 'error' : type, title: message
        });
    }

    function showFlashes(items) {
        if (!items || !items.length) return;
        // ข้อความสำคัญ (เช่น รหัสผ่านชั่วคราว) ใช้กล่องโต้ตอบที่ต้องกดปิดเอง
        var important = items.filter(function (f) { return f.type === 'important'; });
        var normal = items.filter(function (f) { return f.type !== 'important'; });
        if (important.length && typeof Swal !== 'undefined') {
            var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
            Swal.fire({
                icon: 'info', title: 'ข้อมูลสำคัญ',
                html: important.map(function (f) { return esc(f.message).replace(/\n/g, '<br>'); }).join('<hr>'),
                confirmButtonText: 'รับทราบ', confirmButtonColor: cssVar('--th-accent', '#f47b20'), allowOutsideClick: false
            });
        }
        normal.forEach(function (f, i) { setTimeout(function () { toast(f.type, f.message); }, i * 400); });
    }

    // ------------------------------------------------------------
    // นับถอยหลัง: <span data-countdown="เวลาปิดเป็น ms"></span>
    // ใช้ความต่างเวลาเครื่องผู้ใช้กับเซิร์ฟเวอร์ เพื่อให้แม่นยำแม้นาฬิกาเครื่องไม่ตรง
    // ------------------------------------------------------------
    var serverOffset = 0;
    (function () {
        var sn = document.body ? parseInt(document.body.dataset.serverNow || '0', 10) : 0;
        if (sn) serverOffset = sn - Date.now();
    })();

    function formatRemain(ms) {
        if (ms <= 0) return 'หมดเวลาแล้ว';
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600),
            m = Math.floor((s % 3600) / 60), sec = s % 60;
        if (d > 0) return d + ' วัน ' + h + ' ชม. ' + m + ' นาที';
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    }

    function tickCountdowns() {
        var now = Date.now() + serverOffset;
        document.querySelectorAll('[data-countdown]').forEach(function (el) {
            var target = parseInt(el.dataset.countdown, 10);
            var remain = target - now;
            el.textContent = formatRemain(remain);
            // เหลือน้อยกว่า 3 วัน แสดงสีแดง
            el.classList.toggle('text-danger', remain > 0 && remain < 3 * 86400000);
            el.classList.toggle('fw-bold', remain > 0 && remain < 3 * 86400000);
        });
    }

    // ------------------------------------------------------------
    // ค่าเริ่มต้นของ Chart.js
    // ------------------------------------------------------------
    function initChartDefaults() {
        if (typeof Chart === 'undefined') return;
        Chart.defaults.font.family = "'Sarabun', sans-serif";
        Chart.defaults.font.size = 13;
        Chart.defaults.color = '#4b5563';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.maintainAspectRatio = false;
    }

    // ------------------------------------------------------------
    // ย่อรูปภาพด้วย canvas (ด้านยาวไม่เกิน max px, JPEG คุณภาพ 0.85)
    // รูปเล็กอยู่แล้วหรือย่อไม่ได้ จะคืนไฟล์เดิม
    // ------------------------------------------------------------
    function resizeImage(file, max) {
        return new Promise(function (resolve) {
            if (!file.type || file.type.indexOf('image/') !== 0 || file.type === 'image/gif') { resolve(file); return; }
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                var w = img.naturalWidth, h = img.naturalHeight;
                if (Math.max(w, h) <= max && file.size < 1.5 * 1024 * 1024) { resolve(file); return; }
                var scale = Math.min(1, max / Math.max(w, h));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(w * scale);
                canvas.height = Math.round(h * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(function (blob) {
                    if (!blob) { resolve(file); return; }
                    var name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
                    resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }

    // แสดงตัวอย่างรูปจากทุกช่องอัปโหลดที่ใช้กล่องเดียวกัน
    function renderPreview(selector) {
        var box = selector ? document.querySelector(selector) : null;
        if (!box) return;
        box.innerHTML = '';
        document.querySelectorAll('input[type="file"][data-preview="' + selector + '"]').forEach(function (inp) {
            Array.prototype.forEach.call(inp.files, function (f) {
                if (f.type.indexOf('image/') !== 0) return;
                var col = document.createElement('div');
                col.className = 'col-4 col-md-2 text-center small';
                var img = document.createElement('img');
                img.className = 'img-fluid rounded border';
                img.style.cssText = 'aspect-ratio:4/3;object-fit:cover;width:100%';
                img.src = URL.createObjectURL(f);
                var cap = document.createElement('div');
                cap.className = 'text-muted';
                cap.textContent = Math.round(f.size / 1024) + ' KB';
                col.appendChild(img);
                col.appendChild(cap);
                box.appendChild(col);
            });
        });
    }

    // ------------------------------------------------------------
    // ผูกเหตุการณ์ทั่วไปเมื่อโหลดหน้า
    // ------------------------------------------------------------
    function bindCommon() {
        // แสดง/ซ่อนรหัสผ่าน
        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.dataset.target);
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('bi-eye', !show);
                    icon.classList.toggle('bi-eye-slash', show);
                }
            });
        });

        // มาตรวัดความแข็งแรงรหัสผ่าน
        document.querySelectorAll('input[data-strength]').forEach(function (input) {
            var meter = document.querySelector(input.dataset.strength);
            if (!meter) return;
            var bar = meter.querySelector('div');
            input.addEventListener('input', function () {
                var v = input.value, score = 0;
                if (v.length >= 8) score++;
                if (/[A-Za-z]/.test(v) && /[0-9]/.test(v)) score++;
                if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) score++;
                var colors = ['#dc3545', '#fd7e14', '#ffc107', '#20c997', '#198754'];
                bar.style.width = (v ? (score + 1) * 20 : 0) + '%';
                bar.style.background = colors[score];
            });
        });

        // ยืนยันก่อนส่งฟอร์ม: <form data-confirm="ข้อความ">
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
            if (form.dataset.confirmed === '1' || typeof Swal === 'undefined') return;
            ev.preventDefault();
            ev.stopImmediatePropagation();
            var submitter = ev.submitter || null;
            Swal.fire({
                title: form.dataset.confirmTitle || 'ยืนยันการทำรายการ',
                text: form.dataset.confirm,
                icon: form.dataset.confirmIcon || 'question',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: form.dataset.confirmIcon === 'warning' ? '#dc3545' : cssVar('--th-accent', '#f47b20'),
                reverseButtons: true
            }).then(function (r) {
                if (!r.isConfirmed) return;
                form.dataset.confirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                } else {
                    form.submit();
                }
                setTimeout(function () { form.dataset.confirmed = ''; }, 1000);
            });
        }, true);

        // ยืนยันก่อนเปิดลิงก์: <a data-confirm-link="ข้อความ">
        document.addEventListener('click', function (ev) {
            var a = ev.target.closest('a[data-confirm-link]');
            if (!a || typeof Swal === 'undefined') return;
            ev.preventDefault();
            Swal.fire({
                title: a.dataset.confirmTitle || 'ยืนยันการทำรายการ',
                text: a.dataset.confirmLink,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc3545',
                reverseButtons: true
            }).then(function (r) { if (r.isConfirmed) window.location.href = a.href; });
        });

        // ป้องกันกดส่งซ้ำ: <button type="submit" data-loading-text="...">
        document.addEventListener('submit', function (ev) {
            if (ev.defaultPrevented) return;
            ev.target.querySelectorAll('button[type="submit"][data-loading-text]').forEach(function (b) {
                setTimeout(function () {
                    b.disabled = true;
                    b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + b.dataset.loadingText;
                }, 0);
            });
        });

        // เปิด tooltip ของ Bootstrap
        if (typeof bootstrap !== 'undefined') {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
        }

        // QR Code: <div data-qr="ข้อความ/ลิงก์" data-qr-size="200"> (ต้องโหลด qrcodejs)
        if (typeof QRCode !== 'undefined') {
            document.querySelectorAll('[data-qr]').forEach(function (el) {
                var size = parseInt(el.dataset.qrSize || '200', 10);
                new QRCode(el, { text: el.dataset.qr, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
            });
        }

        // ย่อรูปก่อนอัปโหลด + แสดงตัวอย่าง: <input type="file" data-resize-images="1600" data-preview="#box">
        document.querySelectorAll('input[type="file"][data-resize-images]').forEach(function (input) {
            input.addEventListener('change', function () {
                var max = parseInt(input.dataset.resizeImages || '1600', 10);
                var form = input.form;
                var btns = form ? form.querySelectorAll('button[type="submit"], button:not([type])') : [];
                btns.forEach(function (b) { b.disabled = true; });
                var files = Array.prototype.slice.call(input.files);
                Promise.all(files.map(function (f) { return resizeImage(f, max); })).then(function (out) {
                    if (window.DataTransfer) {
                        try {
                            var dt = new DataTransfer();
                            out.forEach(function (f) { dt.items.add(f); });
                            input.files = dt.files;
                        } catch (e) { /* เบราว์เซอร์เก่า: ส่งไฟล์เดิม */ }
                    }
                }).finally(function () {
                    btns.forEach(function (b) { b.disabled = false; });
                    renderPreview(input.dataset.preview);
                });
            });
        });

        // คัดลอกข้อความ: <button data-copy="#inputId">
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-copy]');
            if (!btn) return;
            var input = document.querySelector(btn.dataset.copy);
            if (!input) return;
            var done = function () { toast('success', 'คัดลอกลิงก์แล้ว'); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(done);
            } else {
                input.select();
                document.execCommand('copy');
                done();
            }
        });

        initDataTables();
        initChartDefaults();
        tickCountdowns();
        setInterval(tickCountdowns, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCommon);
    } else {
        bindCommon();
    }

    return {
        toast: toast,
        showFlashes: showFlashes,
        initDataTables: initDataTables,
        palette: palette,
        statusColors: statusColors,
        cssVar: cssVar
    };
})();
