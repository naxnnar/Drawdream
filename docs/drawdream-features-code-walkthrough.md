# คู่มืออ่านโค้ด DrawDream — แบบอ่านโค้ดต่อเนื่องแล้วอธิบาย

เอกสารสำหรับเตรียมตอบอาจารย์: แต่ละข้อมี **โค้ดจริง (เลขบรรทัด + ชื่อไฟล์)** แล้วตามด้วย **บทอธิบายยาว** อ่านจากบนลงล่างเหมือนอ่านไฟล์ใน IDE — ไม่แยกตารางรายบรรทัด

รายละเอียดสิ่งของเพิ่มเติม (8 ฟีเจอร์ย่อย): [`needlist-features-code-walkthrough.md`](needlist-features-code-walkthrough.md)

---

## สารบัญ

| ข้อ | หัวข้อ |
|-----|--------|
| 1.1 | กรอกเด็ก — อายุจากวันเกิด + ระดับชั้น |
| 1.2 | อัปเดตผลลัพธ์เด็กหลังมีผู้อุปการะ |
| 1.3 | ยกเลิกอุปการะ + สถานะ «รออุปการะ» |
| 1.4 | อุปการะรายเดือน / รายปี + Omise |
| 2.1 | กรอกโครงการ — ที่อยู่เชื่อม dropdown |
| 2.2 | ชำระค่าบริการ → แอดมินยืนยันโอน → โพสต์ผลโครงการ |
| 2.3 | คำนวณค่าบริการ 5% โครงการ |
| 3.1 | ค่าบริการสิ่งของ 5% |
| 3.2 | กรอกราคา × จำนวน → ยอดเป้าหมาย |
| 3.3 | แอดมิน escrow สิ่งของ (จัดซื้อ / จัดส่ง) |

**ตารางหลักใน DBeaver:** `foundation_children`, `donation`, `child_subscription_history`, `donor`, `foundation_project`, `foundation_needlist`

---

# ส่วนที่ 1 — ฟีเจอร์เด็ก

---

## ข้อ 1.1 กรอกเด็ก — อายุจากวันเกิด + ระดับชั้น

**ไฟล์:** `foundation_add_children.php`

### โค้ด (ฝั่งเบราว์เซอร์ — คำนวณอายุและชั้นเรียนทันที)

```640:680:foundation_add_children.php
function getSuggestedEducation(age) {
    if (age <= 5)  return 'อนุบาล';
    if (age === 6) return 'ป.1';
    if (age === 7) return 'ป.2';
    if (age === 8) return 'ป.3';
    if (age === 9) return 'ป.4';
    if (age === 10) return 'ป.5';
    if (age === 11) return 'ป.6';
    if (age === 12) return 'ม.1';
    if (age === 13) return 'ม.2';
    if (age === 14) return 'ม.3';
    if (age === 15) return 'ม.4';
    if (age === 16) return 'ม.5';
    if (age === 17) return 'ม.6';
    return 'อุดมศึกษา/อาชีวะ';
}

function syncAgeAndEducation() {
    const bd    = document.getElementById('birth_date');
    const ageEl = document.getElementById('age');
    const edu   = document.getElementById('education');
    const disp  = document.getElementById('birth_date_display');
    if (!bd.value) { ageEl.value = ''; return; }
    const today = new Date(), dob = new Date(bd.value + 'T00:00:00');
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    if (age < 0) { ageEl.value = ''; return; }
    if (age < 6 || age > 18) {
        showTopAlert(`อายุ ${age} ปี ไม่อยู่ในเกณฑ์ที่รับได้ (6–18 ปี) กรุณาตรวจสอบวันเกิด`);
        bd.value = '';
        document.getElementById('birth_date_picker').value = '';
        disp.value = '';
        disp.closest('.field-group').classList.add('has-error');
        ageEl.value = '';
        return;
    }
    disp.closest('.field-group').classList.remove('has-error');
    ageEl.value = age;
    if (!educationManuallyEdited || !edu.value.trim()) edu.value = getSuggestedEducation(age);
}
```

### อธิบาย

เมื่อมูลนิธิเลือกวันเกิด ระบบเก็บค่าจริงใน hidden input ชื่อ `birth_date` รูปแบบ `Y-m-d` ส่วนช่องที่เห็นเป็นปฏิทิน/ข้อความแยกต่างหาก ฟังก์ชัน `syncAgeAndEducation()` ถูกเรียกทุกครั้งที่วันเกิดเปลี่ยน มันอ่าน `birth_date` แล้วสร้าง object วันที่ (`dob`) เทียบกับวันนี้ (`today`) คำนวณอายุแบบปีเต็ม โดยลบหนึ่งปีถ้ายังไม่ถึงวันเกิดในปีนี้ (เช็คเดือนและวัน) ถ้าอายุน้อยกว่า 6 หรือมากกว่า 18 ระบบไม่ให้ผ่าน — แจ้งเตือน ล้างวันเกิด และใส่ class error ที่ฟิลด์ นี่คือกฎธุรกิจฝั่งหน้าเว็บเพื่อให้ผู้ใช้เห็นทันทีก่อนกดบันทึก ถ้าอายุอยู่ในเกณฑ์ จะเติมช่อง `age` อัตโนมัติ และถ้ามูลนิธิยังไม่เคยแก้ชั้นเรียนเอง (`educationManuallyEdited`) จะเรียก `getSuggestedEducation(age)` เพื่อแมปอายุเป็นข้อความเช่น อายุ 12 → `ม.1` อายุ 17 → `ม.6` ชั้นเรียนนี้เป็นเพียงค่าแนะนำ — มูลนิธิแก้ทับได้ และค่าที่ส่งไปเซิร์ฟเวอร์คือสิ่งที่อยู่ในฟอร์มตอน submit

### โค้ด (ฝั่งเซิร์ฟเวอร์ — ตอนกดบันทึก)

```113:169:foundation_add_children.php
if (isset($_POST['submit'])) {
    $child_name    = trim($_POST['child_name'] ?? '');
    $birth_date_raw = trim($_POST['birth_date'] ?? '');
    $age           = 0;
    $education     = trim($_POST['education'] ?? '');
    // ... ฟิลด์อื่น: dream, likes, wish, บัญชีธนาคาร ...
    $status        = "รออุปการะ";
    $approve_status = "รอดำเนินการ";
    $policy_consent = isset($_POST['policy_consent']) && $_POST['policy_consent'] === '1';

    if (!$policy_consent) {
        echo "<script>alert('กรุณายินยอมนโยบายก่อนบันทึกข้อมูล'); history.back();</script>";
        exit();
    }
    // ... ตรวจ wish, เลขบัญชี 9-12 หลัก ...

    $dob = DateTime::createFromFormat('Y-m-d', $birth_date_raw);
    $today = new DateTime('today');
    if (!$dob || $dob->format('Y-m-d') !== $birth_date_raw) {
        echo "<script>alert('กรุณาเลือกวันเกิดให้ถูกต้อง'); history.back();</script>";
        exit();
    }
    if ($dob > $today) {
        echo "<script>alert('วันเกิดต้องไม่เป็นวันที่ในอนาคต'); history.back();</script>";
        exit();
    }
    $age = (int)$today->diff($dob)->y;

    if ($age < 6 || $age > 18) {
        echo "<script>alert('อายุ {$age} ปี ไม่อยู่ในเกณฑ์ที่รับได้ (6-18 ปี) กรุณาตรวจสอบวันเกิด'); history.back();</script>";
        exit();
    }
```

```195:224:foundation_add_children.php
    if ($isEditForm && $editChildId > 0 && $editChild) {
        $stEd = $conn->prepare(
            "UPDATE foundation_children
             SET child_name=?, birth_date=?, age=?, education=?, dream=?, likes=?, wish=?, wish_cat=?, bank_name=?, child_bank=?, photo_child=?
             WHERE child_id=? AND foundation_id=?"
        );
        $stEd->bind_param('ssissssssssii', ...);
        $stEd->execute();
        header('Location: children_.php?msg=...');
        exit();
    } else {
        $sql = "INSERT INTO foundation_children (foundation_id, foundation_name, child_name, birth_date, age, education, ..., status, photo_child, approve_profile)
                VALUES (?, ?, ?, ?, ?, ?, ...)";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
```

### อธิบาย

เมื่อกด submit โค้ด PHP ไม่เชื่ออายุจากช่อง `age` อย่างเดียว — มันอ่าน `birth_date` จาก POST แล้วคำนวณอายุใหม่ด้วย `DateTime::diff()` เหมือนกฎ 6–18 ปีบน JavaScript เพื่อกันการแก้ค่าผ่าน DevTools หลังผ่าน validation แล้วจะแยกสองทาง: ถ้าเป็นโหมดแก้ไข (`$isEditForm`) จะ **UPDATE** แถวใน `foundation_children` ที่ตรง `child_id` และ `foundation_id` ของมูลนิธิที่ล็อกอิน ถ้าเป็นรายการใหม่จะ **INSERT** แถวใหม่ โดยตั้ง `status` เป็น `"รออุปการะ"` และ `approve_profile` เป็น `"รอดำเนินการ"` ตั้งแต่ต้น

**Storage ใน DBeaver:** คอลัมน์ `birth_date` เก็บวันที่, `age` เก็บตัวเลขที่คำนวณแล้ว, `education` เก็บข้อความชั้นเรียน, `status` เริ่มที่ `รออุปการะ` — จะถูก sync อีกครั้งเมื่อมีแผนอุปการะ (ข้อ 1.3)

---

## ข้อ 1.2 อัปเดตผลลัพธ์เด็กหลังมีผู้อุปการะ

**ไฟล์:** `foundation_child_outcome.php`

### โค้ด (ตรวจสิทธิก่อนเข้าหน้า)

```46:62:foundation_child_outcome.php
$sqlGet = 'SELECT * FROM foundation_children WHERE child_id = ? AND foundation_id = ? AND deleted_at IS NULL LIMIT 1';
$stmtGet = $conn->prepare($sqlGet);
$stmtGet->bind_param('ii', $childId, $foundationId);
$stmtGet->execute();
$child = $stmtGet->get_result()->fetch_assoc();

$mayEditOutcome = drawdream_child_is_monthly_fully_sponsored($conn, $childId, $child)
    || drawdream_child_has_any_active_subscription($conn, $childId);
if (!$mayEditOutcome) {
    header('Location: children_donate.php?id=' . $childId . '&msg=' . rawurlencode('อัปเดตผลลัพธ์ได้เฉพาะเด็กที่อุปการะครบยอดในเดือนนี้ หรือมีผู้อุปการะแบบรายรอบแล้วเท่านั้น'));
    exit;
}
```

### โค้ด (บันทึกเมื่อ POST)

```130:218:foundation_child_outcome.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $text = trim((string)($_POST['outcome_text'] ?? ''));
    $text = (string)preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    $currentList = drawdream_child_outcome_images_parse($child['update_images'] ?? null);

    // ลบรูปที่ผู้ใช้ติ๊ก remove_outcome_images
    // อัปโหลด outcome_images[] สูงสุด 8 ไฟล์, ไฟล์ละ 4MB → uploads/evidence/

    $upd = $conn->prepare(
        'UPDATE foundation_children SET update_text = ?, update_images = ?, update_at = NOW() WHERE child_id = ? AND foundation_id = ?'
    );
    $upd->bind_param('ssii', $text, $json, $childId, $foundationId);
    $upd->execute();

    $notifyUserIds = drawdream_child_current_sponsor_user_ids($conn, $childId);
    foreach (array_keys($notifyUserIds) as $uid) {
        // INSERT notifications ให้ผู้อุปการะปัจจุบัน
    }
    header('Location: foundation_child_outcome.php?id=' . $childId . '&saved=1');
}
```

### อธิบาย

หน้านี้เปิดให้เฉพาะมูลนิธิที่ล็อกอินและเป็นเจ้าของเด็กคนนั้น ก่อนแสดงฟอร์ม ระบบ **READ** แถวเด็กจาก `foundation_children` แล้วถาม helper สองตัว: (1) ยอดบริจาคในรอบเดือนปฏิทินครบเป้าหรือไม่ (`drawdream_child_is_monthly_fully_sponsored`) (2) มีแผน Omise ที่สถานะ `active` ใน `child_subscription_history` หรือไม่ ถ้าไม่เข้าเงื่อนไขใดเลย จะ redirect กลับหน้าบริจาคเด็ก — นี่คือคำตอบอาจารย์ว่า «ทำไมโพสต์ผลไม่ได้» เพราะยังไม่มีผู้อุปการะตามนิยามระบบ

เมื่อ POST ระบบรับข้อความ `outcome_text` ตัด emoji ที่อาจทำให้ MySQL error รวมรายชื่อรูปเดิมจาก JSON ใน `update_images` ลบรูปที่มูลนิธิเลือก แล้วอัปโหลดรูปใหม่ไป `uploads/evidence/` ชื่อไฟล์แบบ `children_{childId}_{time}_{random}.jpg` สุดท้าย **UPDATE** สามคอลัมน์ `update_text`, `update_images`, `update_at` บนแถวเด็ก และแจ้งเตือนเฉพาะ `drawdream_child_current_sponsor_user_ids` — ผู้บริจาคที่ถือว่ายังเป็นผู้อุปการะอยู่ (รวมกรณียกเลิกแล้วแต่ coverage ยังไม่หมด)

---

## ข้อ 1.3 ยกเลิกอุปการะ + สถานะ «รออุปการะ»

**ไฟล์:** `includes/child_sponsorship.php`, `payment/child_subscription_cancel.php`

### โค้ด (sync สถานะเด็กในตาราง)

```740:767:includes/child_sponsorship.php
function drawdream_child_sync_sponsorship_status(mysqli $conn, int $childId): void
{
    drawdream_child_sponsorship_ensure_columns($conn);
    $st = $conn->prepare('SELECT * FROM foundation_children WHERE child_id = ? LIMIT 1');
    $st->bind_param('i', $childId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row || !empty($row['deleted_at'])) {
        return;
    }
    $hasActiveSub = drawdream_child_has_any_active_subscription($conn, $childId);
    $hasCoverage = drawdream_child_has_plan_coverage_now($conn, $childId);
    $status = ($hasActiveSub || $hasCoverage) ? 'อุปการะแล้ว' : 'รออุปการะ';
    $stmt = $conn->prepare('UPDATE foundation_children SET status = ? WHERE child_id = ?');
    $stmt->bind_param('si', $status, $childId);
    $stmt->execute();
}
```

### โค้ด (ผู้บริจาคกดยกเลิก)

```44:112:payment/child_subscription_cancel.php
$st = $conn->prepare(
    "SELECT history_id, donate_id, recurring_schedule_id, recurring_plan_code
     FROM child_subscription_history
     WHERE child_id = ? AND donor_user_id = ? AND current_status = 'active'
     ORDER BY history_id DESC LIMIT 1"
);
// ...
$scheduleId = trim((string)($sub['recurring_schedule_id'] ?? ''));
if ($scheduleId !== '' && str_starts_with($scheduleId, 'schd_')) {
    drawdream_omise_post_form('/schedules/' . rawurlencode($scheduleId) . '/revoke', []);
}
$up = $conn->prepare(
    "UPDATE donation SET donate_type = 'child_subscription_charge'
     WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription'"
);
$upHist = $conn->prepare(
    "UPDATE child_subscription_history
     SET event_type = 'subscription_cancelled', current_status = 'cancelled', created_at = NOW()
     WHERE child_id = ? AND donor_user_id = ? AND current_status = 'active'"
);
// จากนั้น drawdream_child_sync_sponsorship_status($conn, $childId);
```

### อธิบาย

คอลัมน์ `foundation_children.status` ไม่ได้อัปเดตตรงๆ จากยอด PromptPay รายวัน — ฟังก์ชัน `drawdream_child_sync_sponsorship_status` ตั้งใจสะท้อนแค่ **แพ็กเกจรายรอบ**: ถ้ามี subscription `active` หรือผู้บริจาคยังอยู่ในช่วง **coverage** (จ่ายราย 6 เดือน/รายปีไปแล้ว แม้ยกเลิกต่ออายุ) จะเป็น `อุปการะแล้ว` มิฉะนั้น `รออุปการะ`

เมื่อผู้บริจาคยกเลิก ระบบ **READ** แถวประวัติ `active` ล่าสุดของคู่เด็ก–ผู้บริจาค ถ้า `recurring_schedule_id` ขึ้นต้น `schd_` จะเรียก Omise API revoke เพื่อหยุดหักรอบในอนาคต ถ้าเป็น `local_cron_` (โหมดสำรอง) ไม่มี schedule บน Omise ให้ revoke — แต่ cron จะไม่หักต่อเพราะสถานะไม่ใช่ `active` แล้ว จากนั้น **UPDATE** `donation` และ `child_subscription_history` เป็น `cancelled` แล้วเรียก sync สถานะเด็ก ถ้าไม่มีผู้อุปการะคนอื่นและ coverage หมดแล้ว เด็กกลับ `รออุปการะ` และ `drawdream_child_can_receive_donation` จะเปิดรับบริจาคใหม่ได้ (ถ้าโปรไฟล์อนุมัติแล้ว)

---

## ข้อ 1.4 อุปการะรายเดือน / รายปี + Omise

**ไฟล์:** `includes/child_sponsorship.php`, `payment/child_subscription_create.php`, `payment/cron_child_subscription_charges.php`

### โค้ด (แพ็กเกจ)

```17:60:includes/child_sponsorship.php
function drawdream_child_plan_amount_by_code(string $planCode): ?float
{
    if ($plan === 'monthly')    return 700.0;
    if ($plan === 'semiannual') return 4200.0;
    if ($plan === 'yearly')     return 8400.0;
    return null;
}
```

### โค้ด (สมัครอุปการะ — สร้าง Omise + บันทึก DB)

```118:152:payment/child_subscription_create.php
$childId = (int)($_POST['child_id'] ?? 0);
$planRaw = (string)($_POST['plan'] ?? '');
$token = trim((string)($_POST['omiseToken'] ?? ''));
$planSpec = drawdream_child_subscription_plan($planRaw);
// READ เด็ก + เช็ค drawdream_child_can_start_omise_subscription(...)
// สร้าง/ผูก Omise Customer + Card → บันทึก donor.omise_customer_id
```

```262:388:payment/child_subscription_create.php
$sres = drawdream_omise_post_schedule($planSpec['every'], $planSpec['period'], $startDate, $endDate, $billDay, $chargePayload);
if (($sres['object'] ?? '') === 'error') {
    // Fallback: หัก Charge รอบแรกทันที
    $fcharge = drawdream_omise_create_card_charge($custId, $cardId, $amount_satang, $desc, $metaCharge);
    $localSchId = 'local_cron_' . bin2hex(random_bytes(12));
    INSERT INTO donation (...) VALUES (..., 'child_subscription_charge', ...);
    drawdream_child_subscription_history_log(..., $localSchId, ..., 'active', ...);
    drawdream_child_sync_sponsorship_status($conn, $childId);
}
// ถ้า schedule สำเร็จ: $schId = $sres['id']; log history ด้วย schd_...
```

### โค้ด (cron หักรอบถัดไป — หลายรอบ ไม่ส่งครั้งเดียว)

```31:97:payment/cron_child_subscription_charges.php
$st = $conn->prepare(
    "SELECT h.history_id, h.child_id, h.donor_user_id, h.recurring_next_charge_at, dn.omise_customer_id, dn.omise_card_id
     FROM child_subscription_history h
     INNER JOIN donor dn ON dn.user_id = h.donor_user_id
     WHERE h.current_status = 'active' AND h.recurring_schedule_id LIKE 'local_cron_%'"
);
// foreach แผน: ถ้า recurring_next_charge_at <= วันนี้
//   drawdream_omise_create_card_charge(...)
//   INSERT donation แถวใหม่ + UPDATE recurring_next_charge_at ไปรอบถัดไป
```

### อธิบาย

ผู้บริจาคเลือกแผน `monthly` (700 บาท/เดือน), `semiannual` (4,200 / 6 เดือน), หรือ `yearly` (8,400 / ปี) แล้วส่ง Omise Token จากฟอร์มบัตร ระบบตรวจว่าเด็กรับอุปการะซ้ำได้หรือไม่ จากนั้นสร้างหรือใช้ `omise_customer_id` ในตาราง `donor` แล้วพยายามสร้าง **Charge Schedule** บน Omise (`POST /schedules`) ให้หักตาม `every` และ `period` อัตโนมัติ

ใน environment ทดสอบ Omise มักสร้าง schedule ไม่ได้ — โค้ดจึงมี **fallback**: หัก **Charge รอบแรก** ทันที, **INSERT** แถวใน `donation` ประเภท `child_subscription_charge`, บันทึก `child_subscription_history` ด้วย `recurring_schedule_id` แบบ `local_cron_xxx` และตั้ง `recurring_next_charge_at` สำหรับรอบถัดไป สคริปต์ `cron_child_subscription_charges.php` จะรันเป็นระยะ (CLI หรือ HTTP+secret) อ่านแผน `active` ที่เป็น `local_cron_%` เมื่อถึงวันหักจึงเรียก Omise สร้าง Charge อีกครั้งและ **INSERT** `donation` รอบใหม่ — นี่คือคำตอบว่า «ไม่ได้ส่งคำสั่ง Omise รวมทุกเดือนในครั้งเดียว» แต่หักทีละรอบตามวันที่เก็บใน DB ส่วนกรณี `schd_` สำเร็จ Omise หักเองตาม schedule และระบบ log ประวัติด้วย id จริงจาก Omise

ทุกครั้งหลังสมัครสำเร็จจะเรียก `drawdream_child_sync_sponsorship_status` ทำให้ `status` เป็น `อุปการะแล้ว`

---

# ส่วนที่ 2 — ฟีเจอร์โครงการ

---

## ข้อ 2.1 กรอกโครงการ — ที่อยู่เชื่อม dropdown

**ไฟล์:** `includes/address_helpers.php`, `foundation_add_project.php`

### โค้ด

```94:121:includes/address_helpers.php
function drawdream_merge_foundation_address_from_post(array $post): string
{
    $p = trim((string)($post['addr_province'] ?? ''));
    $a = trim((string)($post['addr_amphoe'] ?? ''));
    $tRaw = trim((string)($post['addr_tambon'] ?? ''));
    $z = trim((string)($post['addr_zip'] ?? ''));

    if ($tRaw !== '' && strpos($tRaw, "\x1E") !== false) {
        $parts = explode("\x1E", $tRaw, 2);
        if ($z === '') { $z = $parts[0]; }
        $t = $parts[1] ?? '';
    } else {
        $t = $tRaw;
    }

    $geo = 'ต.' . $t . ' อ.' . $a . ' จ.' . $p . ' ' . $z;
    $line = drawdream_build_address_line_from_post($post);

    return $line !== '' ? $line . ' ' . $geo : $geo;
}
```

```162:162:foundation_add_project.php
$projectLocation = drawdream_merge_foundation_address_from_post($_POST);
```

### อธิบาย

ฟอร์มโครงการไม่ได้ให้พิมพ์ที่อยู่ยาวๆ ช่องเดียว แต่แยกเลขที่ ซอย ถนน และ dropdown จังหวัด–อำเภอ–ตำบล–รหัสไปรษณีย์ (ข้อมูลตำบลบางครั้งส่งมาพร้อมรหัสใน string เดียวคั่นด้วยอักขระ RS `\x1E` โค้ดจึงแยกก่อน) ฟังก์ชัน `drawdream_build_address_line_from_post` รวมส่วนหน้าเป็น `เลขที่ … ซ.… ถ.…` แล้ว `drawdream_merge_foundation_address_from_post` ต่อท้ายเป็น `ต.… อ.… จ.… รหัสไปรษณีย์` ได้ string เดียว เช่น `เลขที่ 12 ซ.สุขุม ถ.พหลโย ต.ห้วยโป่ง อ.เมืองแม่ฮ่องสอน จ.แม่ฮ่องสอน 58000` ค่านี้ถูกใส่ใน **INSERT/UPDATE** คอลัมน์ `foundation_project.location` รูปแบบคงที่ทำให้หน้าค้นหาโครงการกรองตามจังหวัด/อำเภอได้โดยไม่ต้องมีตารางที่อยู่แยก

---

## ข้อ 2.2 ชำระค่าบริการ → แอดมินยืนยันโอน → โพสต์ผลโครงการ

**ไฟล์:** `admin_escrow.php`, `foundation_post_update.php`

### โค้ด (แอดมินยืนยันโอน escrow — ต้นฉบับที่อาจารย์มักถาม)

```39:63:admin_escrow.php
    if ($action === 'confirm_transfer' && $project_id) {
        $ps = $conn->prepare(
            "SELECT p.project_name, fp.user_id, fp.foundation_name, p.service_charge_paid_at
             FROM foundation_project p
             JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
             WHERE p.project_id = ? AND p.deleted_at IS NULL"
        );
        $ps->bind_param('i', $project_id);
        $ps->execute();
        $proj = $ps->get_result()->fetch_assoc();
        if ($proj && empty($proj['service_charge_paid_at'])) {
            $error = 'มูลนิธิยังไม่ได้ชำระค่าบริการระบบ — รอมูลนิธิชำระก่อนยืนยันโอนเงิน';
        } elseif ($proj) {
            $upd = $conn->prepare("UPDATE foundation_project SET project_status = 'purchasing' WHERE project_id = ? AND deleted_at IS NULL");
            $upd->bind_param('i', $project_id);
            $upd->execute();
            drawdream_escrow_funds_release_holding_for_project($conn, $project_id);
            drawdream_send_notification($conn, (int)$proj['user_id'], '', $title, $message, $link);
            header("Location: admin_escrow.php?success=transferred");
            exit();
        }
    }
```

### โค้ด (มูลนิธิโพสต์ผลลัพธ์)

```47:50:foundation_post_update.php
function drawdream_project_allow_outcome_update(array $project): bool {
    $status = strtolower(trim((string)($project['project_status'] ?? '')));
    return in_array($status, ['purchasing', 'done'], true);
}
```

```110:179:foundation_post_update.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readonly) {
    $project_id  = (int)($_POST['project_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    // READ โครงการว่าเป็นของมูลนิธินี้ + drawdream_project_allow_outcome_update
    // อัปโหลด update_images[] → uploads/evidence/
    $stmt3 = $conn->prepare("
        UPDATE foundation_project
        SET update_text = ?, update_at = NOW(), update_images = ?
        WHERE project_id = ? AND foundation_name = ?
    ");
}
```

### อธิบาย

ลำดับธุรกิจโครงการคือ: ผู้บริจาคบริจาคจนครบเป้า → ระบบคำนวณค่าบริการ 5% (ข้อ 2.3) → มูลนิธิชำระผ่าน QR/Omise จนมีค่าใน `service_charge_paid_at` → แอดมินกดยืนยันโอนในหน้า `admin_escrow.php`

บล็อก `confirm_transfer` เริ่มจาก **Input** คือ `POST action` และ `project_id` จากฟอร์มแอดมิน แล้ว **READ** ชื่อโครงการ, `user_id` มูลนิธิ, และสำคัญที่สุดคือ `service_charge_paid_at` ถ้าคอลัมน์นี้ว่าง แปลว่ามูลนิธิยังไม่จ่ายค่าบริการ — ระบบไม่ให้โอน escrow และตั้ง `$error` เป็นข้อความภาษาไทยที่เห็นในภาพ ถ้าจ่ายแล้วจึง **UPDATE** `project_status` เป็น `'purchasing'` เรียก `drawdream_escrow_funds_release_holding_for_project` เพื่อปล่อยเงินค้ำ และส่ง **notification** ให้มูลนิธิไปหน้า `foundation_post_update.php?project_id=...`

หน้า `foundation_post_update.php` อนุญาตโพสต์ผลเฉพาะเมื่อสถานะเป็น `purchasing` หรือ `done` เท่านั้น — สถานะ `approved` ยังไม่พอ เพราะยังไม่ถึงขั้นมูลนิธิได้รับเงินและดำเนินโครงการจริง เมื่อ POST ระบบตรวจว่าโครงการเป็นของมูลนิธิที่ล็อกอิน อัปโหลดรูปหลักฐาน แล้ว **UPDATE** `update_text`, `update_images`, `update_at` บน `foundation_project`

---

## ข้อ 2.3 คำนวณค่าบริการ 5% โครงการ

**ไฟล์:** `includes/drawdream_project_service_charge.php`, `payment/check_project_payment.php`

### โค้ด

```29:59:includes/drawdream_project_service_charge.php
function drawdream_project_sync_service_charge_for_project(mysqli $conn, int $projectId): void
{
    drawdream_ensure_foundation_project_service_charge_columns($conn);
    $zero = $conn->prepare(
        'UPDATE foundation_project SET service_charge = 0
         WHERE project_id = ? AND deleted_at IS NULL
           AND (COALESCE(goal_amount, 0) <= 0 OR COALESCE(current_donate, 0) < COALESCE(goal_amount, 0))'
    );
    $rate = drawdream_needlist_service_charge_rate(); // 0.05
    $set = $conn->prepare(
        'UPDATE foundation_project
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE project_id = ? AND deleted_at IS NULL
           AND COALESCE(current_donate, 0) >= COALESCE(goal_amount, 0)'
    );
}
```

### อธิบาย

อัตราค่าบริการใช้ helper เดียวกับสิ่งของ: `drawdream_needlist_service_charge_rate()` คืน `0.05` (5%) ฟังก์ชัน `drawdream_project_sync_service_charge_for_project` ทำงานสองขั้นใน SQL: ถ้ายังไม่ครบเป้า (`current_donate < goal_amount`) จะ **UPDATE** `service_charge = 0` ถ้าครบเป้าแล้วจะ **UPDATE** `service_charge = ROUND(current_donate * 0.05, 2)` ค่าบริการ **ไม่ถูกหัก**จาก `current_donate` — เป็นแค่ยอดที่มูลนิธิต้องชำระแยกผ่านหน้า `payment/project_service_charge.php` หลังสแกน QR สำเร็จจึงตั้ง `service_charge_paid_at` ซึ่งเป็นตัวปลดล็อกให้แอดมิน `confirm_transfer` ได้ (ข้อ 2.2) ฟังก์ชันนี้ถูกเรียกหลังบริจาคโครงการสำเร็จจาก `check_project_payment.php`

---

# ส่วนที่ 3 — ฟีเจอร์สิ่งของ

---

## ข้อ 3.1 ค่าบริการสิ่งของ 5%

**ไฟล์:** `includes/drawdream_needlist_schema.php`, `payment/needlist_service_charge.php`, `admin_escrow.php`

### โค้ด

```29:54:includes/drawdream_needlist_schema.php
function drawdream_needlist_sync_service_charge_for_item(mysqli $conn, int $itemId): void
{
    $zero = $conn->prepare(
        'UPDATE foundation_needlist SET service_charge = 0
         WHERE item_id = ? AND (COALESCE(total_price, 0) <= 0 OR COALESCE(current_donate, 0) < COALESCE(total_price, 0))'
    );
    $rate = drawdream_needlist_service_charge_rate();
    $set = $conn->prepare(
        'UPDATE foundation_needlist
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE item_id = ? AND COALESCE(current_donate, 0) >= COALESCE(total_price, 0)'
    );
}
```

### อธิบาย

 logic เหมือนโครงการทุกประการ แต่ใช้ `total_price` แทน `goal_amount` และตาราง `foundation_needlist` เมื่อผู้บริจาคบริจาคครบเป้ารายการ ระบบ sync ให้ `service_charge` เป็น 5% ของ `current_donate` มูลนิธิชำระผ่าน QR แล้วได้ `service_charge_paid_at` แอดมินถึงจะกด «เริ่มจัดซื้อ» หรือ «ยืนยันจัดส่ง» ใน escrow (ข้อ 3.3) — ถ้ายังไม่ชำระค่าบริการจะได้ error เดียวกับโครงการ

---

## ข้อ 3.2 กรอกราคา × จำนวน → ยอดเป้าหมาย

**ไฟล์:** `foundation_add_need.php`

### โค้ด (JavaScript — แสดงยอดก่อนส่ง)

```1048:1063:foundation_add_need.php
function updateTotal() {
    let g = 0;
    slotPriceEls.forEach((pEl, idx) => {
        const qEl = slotQtyEls[idx];
        const price = parseFloat((pEl && pEl.value) || '0');
        const qty = parseFloat((qEl && qEl.value) || '0');
        if (price > 0 && qty > 0) {
            g += (price * qty);
        }
    });
    goalAmount.value = String(g.toFixed(2));
    goalAmountDisplay.value = g.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    totalBox.textContent = "เป้าหมาย: " + g.toLocaleString('th-TH', { minimumFractionDigits: 0 }) + " บาท";
}
```

### โค้ด (PHP — คำนวณซ้ำตอน POST แล้วบันทึก)

```335:392:foundation_add_need.php
    $goal = 0.0;
    foreach ($slots as $slot) {
        // อ่านหมวด, ชื่อสิ่งของ, ราคา $priceSlot, จำนวน $qtySlot จาก POST
        if ($priceSlot <= 0) { $error = "กรุณากรอกราคาที่มากกว่า 0"; break; }
        if ($qtySlot <= 0)  { $error = "กรุณากรอกจำนวนชิ้นที่มากกว่า 0"; break; }
        $lineTotal = $priceSlot * $qtySlot;
        $goal += $lineTotal;
        $lineItems[] = [ 'category' => $cat, 'item_name' => $itemName, 'price' => $priceSlot, 'qty' => $qtySlot, 'line_total' => $lineTotal ];
    }
```

```552:554:foundation_add_need.php
    if ($error === "") {
        $total_price = $goal;
        // INSERT/UPDATE foundation_needlist ใส่ total_price, need_items_json, need_items_pricing_json
```

### อธิบาย

มูลนิธิกรอกได้สูงสุด 5 ช่อง แต่ละช่องมีหมวด รายการ ราคาต่อชิ้น และจำนวน ฝั่งเบราว์เซอร์ `updateTotal()` วนอ่านทุกช่อง คูณ `price * qty` แล้วบวกสะสมเป็น `$g` แสดงใน hidden `goal_amount` และกล่องข้อความ «เป้าหมาย: … บาท» เพื่อให้เห็นยอดก่อนกดบันทึก ฝั่งเซิร์ฟเวอร์ไม่เชื่อแค่ค่าจาก hidden — วน loop ช่องอีกครั้ง คำนวณ `$lineTotal` และ `$goal` เหมือนกัน เก็บรายละเอียดใน `$lineItems` แล้ว encode เป็น JSON สองชุด: `need_items_json` (ชื่อ/จำนวน ไม่มีราคา) และ `need_items_pricing_json` (ราคาและยอดต่อบรรทัด) สุดท้าย `$total_price = $goal` ลง **INSERT** หรือ **UPDATE** คอลัมน์ `total_price` และ `submitted_total_price` (snapshot ตอนเสนอ) ใน DBeaver เปิดดู JSON ได้ว่าแต่ละบรรทัดคูณกันอย่างไร

---

## ข้อ 3.3 แอดมิน escrow สิ่งของ (จัดซื้อ / จัดส่ง)

**ไฟล์:** `admin_escrow.php`

### โค้ด

```66:127:admin_escrow.php
    if ($action === 'start_purchase' && $item_id) {
        $chk = $conn->prepare('SELECT service_charge_paid_at FROM foundation_needlist WHERE item_id = ? LIMIT 1');
        // ...
        if (empty($paidAt)) {
            $error = 'มูลนิธิยังไม่ได้ชำระค่าบริการระบบ — รอมูลนิธิชำระก่อนเริ่มจัดซื้อ';
        } else {
            $stmt = $conn->prepare("UPDATE foundation_needlist SET approve_item = 'purchasing' WHERE item_id = ?");
            $stmt->execute();
        }
    }

    if ($action === 'upload_evidence' && $item_id) {
        // อัปโหลดรูปหลักฐาน → uploads/evidence/
        // เช็ค service_charge_paid_at อีกครั้ง
        $stmt2 = $conn->prepare("UPDATE foundation_needlist SET approve_item = 'done', admin_delivery_text = ?, admin_delivery_images = ?, admin_delivery_at = NOW() WHERE item_id = ?");
    }
```

### อธิบาย

หลังมูลนิธิชำระค่าบริการแล้ว แอดมินกด «เริ่มจัดซื้อ» (`start_purchase`) ระบบ **READ** `service_charge_paid_at` ก่อนทุกครั้ง — ถ้าว่างจะไม่เปลี่ยนสถานะ และแจ้ง error เหมือนโครงการ ถ้าผ่านจะ **UPDATE** `approve_item` เป็น `'purchasing'` เมื่อจัดส่งเสร็จแอดมินอัปโหลดรูปหลักฐาน (`upload_evidence`) ตรวจค่าบริการอีกครั้ง แล้ว **UPDATE** เป็น `'done'` พร้อมเก็บ `admin_delivery_text`, `admin_delivery_images` (JSON), `admin_delivery_at` มูลนิธิจึงไปโพสต์ผลให้ผู้บริจาคดูได้ในขั้นตอนถัดไป (ตาม flow ในเอกสาร needlist ฉบับเต็ม)

---

## อ่านต่อ (สิ่งของละเอียดทุกฟีเจอร์)

- [`needlist-features-code-walkthrough.md`](needlist-features-code-walkthrough.md) — อนุมัติรายการ, แอดมินปรับราคา, บริจาค foundation_donate, หน้าต่างบริจาค ฯลฯ

---

*อัปเดตตามโค้ดใน repo DrawDream — รูปแบบอ่านโค้ดต่อเนื่องแล้วอธิบาย (ไม่แยกตารางรายบรรทัด)*
