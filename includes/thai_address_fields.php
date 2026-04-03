<?php
/**
 * ฟิลด์เลือกที่อยู่ไทย — ตั้งค่าก่อน include: $thai_address_options = ['require' => true|false, 'initial' => [...]]
 */
$__ta = $thai_address_options ?? [];
$__req = ($__ta['require'] ?? true) === true;
$__reqAttr = $__req ? ' required' : '';
$__lblReq = $__req ? ' required' : '';
?>
<div class="thai-address-block">
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
