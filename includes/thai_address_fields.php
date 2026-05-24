<?php
// includes/thai_address_fields.php — Partial select จังหวัด→ตำบล
// สรุปสั้น: partial ฟอร์มที่อยู่ไทย (จังหวัด/อำเภอ/ตำบล/รหัสไปรษณีย์) สำหรับ include ซ้ำหลายหน้า
/**
 * Partial HTML: จังหวัด → อำเภอ → ตำบล → รหัสไปรษณีย์ (4 select)
 *
 * Logic เติม option อยู่ที่ js/thai_address_select.js (โหลด raw_database.json จาก CDN)
 * บันทึกรวมเป็นข้อความ: includes/address_helpers.php drawdream_merge_foundation_address_from_post()
 *
 * ก่อน include: $thai_address_options = ['require' => true|false, 'line' => ['house_no'=>..., 'soi'=>..., 'road'=>...]]
 */
$__ta = $thai_address_options ?? [];
$__req = ($__ta['require'] ?? true) === true;
$__reqAttr = $__req ? ' required' : '';
$__lblReq = $__req ? ' required' : '';
$__line = is_array($__ta['line'] ?? null) ? $__ta['line'] : [];
$__house = htmlspecialchars(trim((string)($__line['house_no'] ?? '')), ENT_QUOTES, 'UTF-8');
$__soi = htmlspecialchars(trim((string)($__line['soi'] ?? '')), ENT_QUOTES, 'UTF-8');
$__road = htmlspecialchars(trim((string)($__line['road'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<div class="thai-address-block">
    <div class="thai-address-line-grid">
        <div class="form-group">
            <label class="form-label">เลขที่</label>
            <input type="text" name="addr_house_no" class="form-input" placeholder="เช่น 123/4" value="<?= $__house ?>">
        </div>
        <div class="form-group">
            <label class="form-label">ซอย</label>
            <input type="text" name="addr_soi" class="form-input" placeholder="เช่น สุขุมวิท 24" value="<?= $__soi ?>">
        </div>
        <div class="form-group">
            <label class="form-label">ถนน</label>
            <input type="text" name="addr_road" class="form-input" placeholder="เช่น พหลโยธิน" value="<?= $__road ?>">
        </div>
    </div>
    <p class="thai-address-hint">เลือกจังหวัด อำเภอ/เขต ตำบล/แขวง และรหัสไปรษณีย์</p>
    <div class="thai-address-grid">
        <div class="form-group">
            <label class="form-label<?= $__lblReq ?>">จังหวัด</label>
            <select name="addr_province" id="addr_province" class="form-input thai-addr-select"<?= $__reqAttr ?> disabled>
                <option value="">กำลังโหลดข้อมูล...</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label<?= $__lblReq ?>">อำเภอ / เขต</label>
            <select name="addr_amphoe" id="addr_amphoe" class="form-input thai-addr-select"<?= $__reqAttr ?> disabled>
                <option value="">— เลือกจังหวัดก่อน —</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label<?= $__lblReq ?>">ตำบล / แขวง</label>
            <select name="addr_tambon" id="addr_tambon" class="form-input thai-addr-select"<?= $__reqAttr ?> disabled>
                <option value="">— เลือกอำเภอก่อน —</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label<?= $__lblReq ?>">รหัสไปรษณีย์</label>
            <select name="addr_zip" id="addr_zip" class="form-input thai-addr-select"<?= $__reqAttr ?> disabled>
                <option value="">— เลือกตำบลก่อน —</option>
            </select>
        </div>
    </div>
</div>
