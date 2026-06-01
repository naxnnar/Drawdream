# บทพูดตอบอาจารย์ — Server Requirement / Virtual Server และการติดตั้ง–Config

เอกสารนี้แปลงจาก [`server-requirement-and-installation.md`](server-requirement-and-installation.md) เป็น **ประโยคพูดปาก** พร้อม **ไฟล์ + บรรทัดโค้ด** ให้ชี้ตอบอาจารย์ได้ทันที

---

## เปิดเรื่อง (30 วินาที — ใช้ก่อนอาจารย์ถาม)

> ครับ/ค่ะ โปรเจกต์ DrawDream เป็น **เว็บแอป PHP + MySQL** ผู้ใช้เข้าผ่านเบราว์เซอร์ ไม่ใช่แอปมือถือแยก  
> ทีมกำหนด **Server Requirement** ก่อน แล้วเช่า **Virtual Server (VPS)** ตามสเปก  
> ส่วนการติดตั้งคือวางโค้ด ตั้ง config (`db.php`, `payment/config.php`, `.env`) เปิด HTTPS, Cron และ Omise Webhook  
> จุดเชื่อมฐานข้อมูลทุกหน้าคือไฟล์ `db.php` ที่รากโปรเจกต์

---

# ข้อ 1 — Server Requirement / Virtual Server

---

## คำถาม 1.1: ระบบของคุณคืออะไร ใช้เทคโนโลยีอะไรบ้าง?

**บทพูด:**

> DrawDream เป็น **Web Application** — ผู้ใช้เปิดเบราว์เซอร์เข้า URL ของเรา  
> ฝั่งหน้าเว็บเป็น HTML/CSS/JavaScript ฝังในหน้า PHP  
> ฝั่งเซิร์ฟเวอร์ใช้ **PHP 8.x** ประมวลผล login, บริจาค, อนุมัติ  
> ฐานข้อมูลเป็น **MySQL 8** ชำระเงินผ่าน **Omise** (คลาวด์) เช่น QR PromptPay บัตร อุปการะรายรอบ  
> รูปและหลักฐานเก็บในโฟลเดอร์ `uploads/` บนเซิร์ฟเวอร์  
> ทุกหน้าที่ใช้ DB มัก `include` ไฟล์ `db.php` เป็นกลาง

**ชี้โค้ดเมื่ออาจารย์ถาม “จุดเชื่อม DB อยู่ไหน”:**

```14:16:db.php
declare(strict_types=1);
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
```

คอมเมนต์อธิบายลำดับอ่าน config — `db.php` บรรทัด 7–10:

```7:10:db.php
 * ค่าการเชื่อมต่ออ่านจาก (ตามลำดับ):
 * 1) ไฟล์ config/db.local.php (แนะนำ — สำเนาจาก config/db.local.example.php)
 * 2) ตัวแปรสภาพแวดล้อม DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
 * 3) ค่าเริ่มต้นแบบ XAMPP เดิม (root ไร้รหัส + drawdream_db)
```

---

## คำถาม 1.2: Server Requirement คืออะไร ทำไมต้องกำหนดก่อนเช่า VPS?

**บทพูด:**

> **Server Requirement** คือรายการสเปกที่ทีม **กำหนดล่วงหน้า** ว่าเครื่องต้องมี CPU, RAM, ซอฟต์แวร์อะไร ถึงจะรัน DrawDream ได้ครบ  
> พอรู้แล้วค่อยไป **เช่า Virtual Server (VPS)** หรือ Web Hosting ที่สเปกตรง  
> ไม่ใช่เช่ามาก่อนแล้วค่อยมาดูว่ารันได้ไหม — จะเสียเวลาและอาจขาด Cron, HTTPS, extension PHP

**ตารางพูดสั้น (ถ้าอาจารย์ถามสเปก):**

| รายการ | ที่เราใช้/แนะนำ | อ้างอิงโค้ด |
|--------|-----------------|-------------|
| PHP | 8.1+ แนะนำ 8.2 | `declare(strict_types=1)` ใน `db.php` บรรทัด 14 |
| MySQL | 8.0, charset utf8mb4 | `db.php` บรรทัด 89 |
| RAM VPS | 2 GB (4 GB ถ้า MySQL อยู่เครื่องเดียวกัน) | PHP-FPM + MySQL |
| HTTPS | บังคับ production | Omise, webhook, OAuth |
| Cron | วันละครั้งหรือทุก 1–6 ชม. | `payment/cron_child_subscription_charges.php` |
| Outbound HTTPS | เปิด | `OMISE_API_URL` ใน `payment/config.php` บรรทัด 34 |

---

## คำถาม 1.3: Virtual Server (VPS) ทำหน้าที่อะไรในโปรเจกต์?

**บทพูด:**

> **VPS** คือเครื่องเซิร์ฟเวอร์เสมือนที่เช่าจากผู้ให้บริการ ได้ CPU/RAM/Disk ตามแพ็กเกจ  
> บน VPS ของเราทำห้าอย่างหลัก:  
> (1) รับ HTTPS จากผู้ใช้ แล้วรัน PHP เช่น `login.php`, `children_donate.php`, โฟลเดอร์ `payment/`  
> (2) เชื่อม MySQL — อาจอยู่บน VPS เดียวกันหรือแยกคลาวด์ เช่น Aiven  
> (3) เรียก Omise API ออกไปที่ `https://api.omise.co`  
> (4) รับ Webhook จาก Omise ที่ `payment/omise_webhook.php`  
> (5) รัน Cron หักอุปการะรอบถัดไป  
> (6) เก็บไฟล์รูปใน `uploads/`

**ชี้โค้ดเรียก Omise:**

```32:34:payment/config.php
define('OMISE_PUBLIC_KEY', 'pkey_test_672j5iz6trht7azp83c');
define('OMISE_SECRET_KEY', 'skey_test_672j5jmwvta3f87nmpg');
define('OMISE_API_URL', 'https://api.omise.co');
```

**ชี้โค้ด Webhook (ตั้ง URL บน Omise Dashboard):**

```5:8:payment/omise_webhook.php
 * ตั้งค่า: Omise Dashboard (Test/Live) → Webhooks → ใส่ URL แบบ HTTPS ที่เข้าถึงได้จากอินเทอร์เน็ต
 * (บน localhost ใช้ ngrok ชี้มาที่ https://xxxx.ngrok.io/drawdream/payment/omise_webhook.php)
 * เลือก event ที่เกี่ยวกับ charge (อย่างน้อย charge.complete) เมื่อหักจาก Schedule สำเร็จ Omise ส่งมาที่นี่
 * ระบบจะ INSERT child_donations เมื่อ metadata.app = drawdream_child_subscription และ paid สำเร็จ
```

---

## คำถาม 1.4: บริการภายนอกมีอะไรบ้าง ติดตั้งบน VPS ไหม?

**บทพูด:**

> มีบริการภายนอกที่ **ไม่ได้ติดตั้งบน VPS** แต่ระบบต้องใช้:  
> **Omise** — ชำระเงิน QR บัตร schedule เรียกผ่าน HTTPS  
> **Aiven MySQL** (ทางเลือก) — DB บนคลาวด์ โค้ดมี default host ใน `db.php`  
> **Google OAuth** (ทางเลือก) — login ด้วย Google ที่ `includes/google_oauth.php`  
> **โดเมน + SSL** — Let's Encrypt บน VPS ให้ URL เป็น HTTPS

**ชี้โค้ด default Aiven (เมื่อไม่มี `config/db.local.php`):**

```32:38:db.php
    $dbConfig = [
        // ค่า default สำหรับ Aiven Cloud (override ได้ด้วย env vars)
        'host' => getenv('DB_HOST') ?: (getenv('AIVEN_HOST') ?: 'mysql-17ffeb44-drawdream.c.aivencloud.com'),
        'port' => (int)(getenv('DB_PORT') ?: (getenv('AIVEN_PORT') ?: 21503)),
        'user' => getenv('DB_USER') ?: (getenv('AIVEN_USER') ?: 'avnadmin'),
        'password' => $envPass !== false && $envPass !== '' ? (string)$envPass : '',
        'database' => getenv('DB_NAME') ?: (getenv('AIVEN_DB') ?: 'defaultdb'),
    ];
```

---

## คำถาม 1.5: พัฒนาบนเครื่องตัวเอง กับ Production ต่างกันอย่างไร?

**บทพูด:**

> เรามีสองสภาพแวดล้อม:  
> **Local** — เครื่องนศ. ใช้ XAMPP หรือ PHP รันด้วย `run_dev_server.ps1` / `run_dev_server.bat`  
> **Production** — อัปโหลดโค้ดขึ้น VPS ตั้ง config, SSL, Cron  
> สำคัญ: PHP built-in server (`php -S`) ใน `run_dev_server.ps1` **ใช้แค่พัฒนา** ไม่ใช่ production

**ชี้โค้ด dev server:**

```60:74:run_dev_server.ps1
$url = "http://127.0.0.1:$Port/login.php"
// ...
& $phpExe @extArgs -S "127.0.0.1:$Port" -t $root
```

Extension ที่ต้องมี — `run_dev_server.ps1` บรรทัด 47–56 (mysqli, curl, openssl ฯลฯ)

---

## คำถาม 1.6: ทำไมไม่ใช้โฮสต์ฟรีเป็นหลัก?

**บทพูด:**

> DrawDream ต้องการ **Cron อุปการะ**, **Webhook Omise เสถียร**, **PHP 8.2 + curl/openssl**, และบางครั้ง **MySQL ภายนอก (Aiven)**  
> โฮสต์ฟรีมักจำกัด Cron, sleep เซิร์ฟเวอร์, บล็อก remote DB หรือเวอร์ชัน PHP  
> เลยเลือก **VPS ประมาณ 2 GB RAM, PHP 8.2, MySQL 8, HTTPS, Cron** ตาม Requirement ที่กำหนด

---

## ประโยคปิดข้อ 1 (พูดจบหัวข้อ)

> **สรุปข้อ 1:** เรากำหนด Server Requirement เป็น VPS ~2 GB RAM, PHP 8.2, MySQL 8, HTTPS และ Cron แล้วเช่า Virtual Server ตามนั้น  
> ระบบเป็นเว็บ PHP ไม่ใช่แอปมือถือแยก backend ส่วน Omise เป็นบริการชำระเงินภายนอกที่ VPS เรียกผ่าน API

---

# ข้อ 2 — การติดตั้งและ Config

---

## คำถาม 2.1: ติดตั้งระบบทำตามลำดับอะไร?

**บทพูด:**

> ลำดับที่เราทำคือ:  
> 1) ติดตั้ง PHP + MySQL (หรือใช้ Aiven)  
> 2) วางโค้ด DrawDream บนเซิร์ฟเวอร์  
> 3) สร้างไฟล์ config — `config/db.local.php`, `.env`, ตรวจ `payment/config.php`  
> 4) ตั้งสิทธิ์โฟลเดอร์ `uploads/`, `config/`, `data/` ให้เขียนได้  
> 5) ตั้ง Omise — คีย์ API + Webhook บน Dashboard  
> 6) ตั้ง Cron อุปการะ (ถ้าใช้โหมด server cron)  
> 7) เปิด HTTPS แล้วทดสอบ login และบริจาค  
> คู่มือสั้นอยู่ใน `README.md` บรรทัด 5–12 ด้วย

---

## คำถาม 2.2: ไฟล์ Config มีอะไรบ้าง โหลดตามลำดับไหน?

**บทพูด:**

> ลำดับโหลดคร่าวๆ คือ:  
> 1) `.env` — รหัสลับ, DB, OAuth, webhook secret (**ไม่ commit** — `.gitignore` บรรทัด 15)  
> 2) `includes/env_loader.php` — อ่าน `.env` เข้า `getenv()`  
> 3) `config/db.local.php` — ค่า MySQL แบบ array (**ไม่ commit** — `.gitignore` บรรทัด 5)  
> 4) `db.php` — เชื่อม DB, migration, timezone  
> 5) `payment/config.php` — Omise keys, cron secret  
> 6) `config/google_oauth.local.php` + `includes/google_oauth.php` — Google Login (ถ้าเปิด)

**ชี้ `.gitignore`:**

```4:6:.gitignore
config/db.local.php
config/google_oauth.local.php
```

```15:15:.gitignore
.env
```

```27:27:.gitignore
config/migration_done.txt
```

---

## คำถาม 2.3: ตั้งค่าฐานข้อมูลอย่างไร?

**บทพูด:**

> ทุก request ที่ include `db.php` จะโหลด `.env` ก่อน แล้วเลือกแหล่ง config MySQL  
> ลำดับคือ: ถ้ามี `config/db.local.php` ใช้ไฟล์นั้นก่อน  
> ถ้าไม่มี อ่านจาก environment (`DB_*` หรือ `AIVEN_*`)  
> ถ้ายังไม่มี ใช้ default ในโค้ด (รองรับ Aiven)  
> เชื่อมด้วย `mysqli` รองรับ SSL ถ้า host เป็น `aivencloud.com`  
> ตั้ง charset `utf8mb4` และ timezone **Asia/Bangkok**  
> ส่วน schema หลายอย่าง **migrate อัตโนมัติ** ตอน boot ไม่ต้องรัน SQL มือทุกครั้ง

**ชี้โหลด .env:**

```15:16:db.php
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
```

ฟังก์ชันโหลด — `includes/env_loader.php` บรรทัด 12–22:

```12:22:includes/env_loader.php
    function drawdream_load_env_file(?string $envPath = null): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $path = $envPath ?: (__DIR__ . '/../.env');
        if (!is_file($path)) {
            return;
        }
```

**ชี้เลือก config MySQL:**

```23:46:db.php
$dbLocalFile = __DIR__ . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $dbConfig */
    $dbConfig = require $dbLocalFile;
} else {
    $envPass = getenv('DB_PASSWORD');
    // ...
}
$host = (string)($dbConfig['host'] ?? 'localhost');
// ...
```

**ชี้ SSL + charset + เวลาไทย:**

```54:56:db.php
    $sslCa = trim((string)(getenv('DB_SSL_CA') !== false ? getenv('DB_SSL_CA') : ''));
    $sslMode = strtolower(trim((string)(getenv('DB_SSL_MODE') !== false ? getenv('DB_SSL_MODE') : 'require')));
    $useSsl = ($sslMode !== 'disable') || str_contains($host, 'aivencloud.com');
```

```89:97:db.php
mysqli_set_charset($conn, 'utf8mb4');
// ...
date_default_timezone_set('Asia/Bangkok');
@mysqli_query($conn, "SET time_zone = '+07:00'");
```

**ชี้ migration อัตโนมัติ:**

```106:122:db.php
// Migration cache — รันแค่ครั้งแรกหรือทุก 1 ชั่วโมง เพื่อไม่ให้ยิง SHOW COLUMNS ทุก request ไปยัง cloud DB
$_ddMigrationCache = __DIR__ . '/config/migration_done.txt';
$_ddMigrationTtl   = 3600; // วินาที
// ...
if ($_ddNeedMigration) {
    drawdream_normalize_foundation_project_statuses($conn);
    // ...
    @file_put_contents($_ddMigrationCache, date('Y-m-d H:i:s'));
}
```

> พูดเพิ่ม: โฟลเดอร์ `config/` ต้อง **เขียนได้** เพื่อสร้าง `migration_done.txt`

---

## คำถาม 2.4: ตั้ง Omise อย่างไร?

**บทพูด:**

> คีย์ Omise อยู่ที่ `payment/config.php`  
> ตอนทดสอบใช้คีย์ `pkey_test_` / `skey_test_` — ไม่ตัดเงินจริง (อธิบายในคอมเมนต์บรรทัด 7–8)  
> Production ต้องเปลี่ยนเป็น **Live key** และเก็บนอก Git (บรรทัด 17–19)  
> ถ้า PHP ต่อ HTTPS Omise ไม่ได้บนเครื่อง dev มี flag mock แต่ production ต้องปิด  
> Cron อุปการะใช้ secret ในไฟล์เดียวกัน — เรียกผ่าน CLI หรือ HTTP `?secret=...`

**ชี้คีย์และ URL:**

```32:34:payment/config.php
define('OMISE_PUBLIC_KEY', 'pkey_test_672j5iz6trht7azp83c');
define('OMISE_SECRET_KEY', 'skey_test_672j5jmwvta3f87nmpg');
define('OMISE_API_URL', 'https://api.omise.co');
```

**ชี้ CA certificate สำหรับ curl:**

```37:43:payment/config.php
if (!defined('OMISE_CURL_CAINFO')) {
    foreach ([dirname(__DIR__) . '/payment/cacert.pem', dirname(__DIR__) . '/config/cacert.pem'] as $caPath) {
        if (is_file($caPath)) {
            define('OMISE_CURL_CAINFO', $caPath);
            break;
        }
    }
}
```

**ชี้ mock (dev เท่านั้น):**

```46:55:payment/config.php
if (!defined('OMISE_ALLOW_LOCAL_MOCK')) {
    define('OMISE_ALLOW_LOCAL_MOCK', false);
}
// ...
if (!defined('OMISE_TEST_MOCK_WHEN_HTTPS_FAILS')) {
    define('OMISE_TEST_MOCK_WHEN_HTTPS_FAILS', false);
}
```

**ชี้ cron secret:**

```58:63:payment/config.php
if (!defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET')) {
    define(
        'DRAWDREAM_SUBSCRIPTION_CRON_SECRET',
        '081a9e4b9232645eea0dbdaaa1d1cd1c6db6139a71aaa7ab93f6244a620d5aef'
    );
}
```

คำแนะนำ cron ในคอมเมนต์ — `payment/config.php` บรรทัด 21–27

---

## คำถาม 2.5: Webhook Omise ทำงานยังไง ตั้งที่ไหน?

**บทพูด:**

> หลังผู้ใช้จ่ายสำเร็จ Omise ส่ง event มาที่ URL บนเซิร์ฟเวอร์เรา — ไฟล์ `payment/omise_webhook.php`  
> ตั้ง URL บน **Omise Dashboard** เป็น HTTPS (localhost ใช้ ngrok)  
> เลือก event อย่างน้อย `charge.complete`  
> Secret ใส่ใน `.env` เป็น `OMISE_WEBHOOK_SECRET` แล้วโค้ดตรวจลายเซ็น `X-Omise-Signature`  
> มีไฟล์ `data/omise_webhook_seen.json` กันส่ง event ซ้ำ — โฟลเดอร์ `data/` ต้องเขียนได้

**ชี้ตรวจลายเซ็น:**

```77:91:payment/omise_webhook.php
function drawdream_webhook_verify_signature_and_time(string $rawBody): bool
{
    $secret = trim((string)(getenv('OMISE_WEBHOOK_SECRET') ?: ''));
    if ($secret === '') {
        error_log('[drawdream_webhook] OMISE_WEBHOOK_SECRET is not configured — rejecting webhook');
        return false;
    }
    $sig = drawdream_webhook_get_header('X-Omise-Signature');
    // ...
    $expected = hash_hmac('sha256', $rawBody, $secret);
    if (!hash_equals($expected, strtolower($sig))) {
        error_log('[drawdream_webhook] signature mismatch');
        return false;
    }
```

**ชี้ path กัน duplicate:**

```27:29:payment/omise_webhook.php
function drawdream_webhook_event_seen_path(): string
{
    return dirname(__DIR__) . '/data/omise_webhook_seen.json';
}
```

---

## คำถาม 2.6: Google Login ตั้งอย่างไร?

**บทพูด:**

> อ่าน config จาก `config/google_oauth.local.php` หรือจาก env `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`  
> ฟังก์ชัน `drawdream_google_oauth_is_ready()` เช็กว่าครบหรือยัง  
> ถ้ายังไม่ครบ `auth/google_start.php` จะ redirect กลับ login พร้อม error

**ชี้โค้ด:**

```26:44:includes/google_oauth.php
function drawdream_google_oauth_config(): array
{
    $configFile = __DIR__ . '/../config/google_oauth.local.php';
    if (is_file($configFile)) {
        $cfg = require $configFile;
    } else {
        $cfg = [
            'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
            'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
            'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
        ];
    }
    // ...
}
```

```50:54:includes/google_oauth.php
function drawdream_google_oauth_is_ready(): bool
{
    $cfg = drawdream_google_oauth_config();
    return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '' && $cfg['redirect_uri'] !== '';
}
```

```11:14:auth/google_start.php
if (!drawdream_google_oauth_is_ready()) {
    header('Location: ../login.php?page=login&error=' . urlencode('ยังไม่ได้ตั้งค่า Google Login ในระบบ'));
    exit();
}
```

---

## คำถาม 2.7: Cron อุปการะรายรอบคืออะไร ตั้งยังไง?

**บทพูด:**

> อุปการะเด็กแบบรายรอบ บางแผนใช้ Cron บนเซิร์ฟเวอร์หักรอบถัดไป แทน Omise Schedule  
> สคริปต์หลักคือ `payment/cron_child_subscription_charges.php`  
> ถ้าเรียกผ่าน HTTP ต้องส่ง `?secret=` ตรงกับ `DRAWDREAM_SUBSCRIPTION_CRON_SECRET` ใน `payment/config.php`  
> ถ้าเรียกผ่าน CLI (`php payment/cron_child_subscription_charges.php`) ไม่ต้องส่ง secret  
> บน Windows ใช้ `payment/run_subscription_cron.bat` กับ Task Scheduler

**ชี้ HTTP guard:**

```6:16:payment/cron_child_subscription_charges.php
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    $sec = defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET') ? (string)DRAWDREAM_SUBSCRIPTION_CRON_SECRET : '';
    if ($sec === '' || !isset($_GET['secret']) || !hash_equals($sec, (string)$_GET['secret'])) {
        http_response_code(403);
        // ...
        echo 'Forbidden';
        exit;
    }
}
```

**ชี้โหลด DB + Omise:**

```18:23:payment/cron_child_subscription_charges.php
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/omise_api_client.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/e_receipt.php';
```

---

## คำถาม 2.8: บน VPS Production ต้องทำ checklist อะไร?

**บทพูด (ไล่ทีละข้อ):**

| ลำพูด | อ้างอิง |
|--------|---------|
| ติด Nginx/Apache + PHP 8.2 + extension mysqli, curl, openssl | ข้อ 1.3 |
| อัปโหลดโค้ด document root = รากที่มี `login.php` | — |
| สร้าง `config/db.local.php` หรือ env บน VPS | `db.php` 23–46 |
| เปลี่ยน Omise เป็น Live key | `payment/config.php` 32–34 |
| สร้าง `.env` ใส่ `OMISE_WEBHOOK_SECRET` | `omise_webhook.php` 79 |
| ตั้ง Webhook URL บน Omise Dashboard | `omise_webhook.php` 5–8 |
| ตั้ง Cron / Task Scheduler | `payment/config.php` 21–27 |
| `chmod` ให้ `uploads/`, `config/`, `data/` เขียนได้ | `.gitignore` 2, 27 |
| ติด SSL (Let's Encrypt) | HTTPS requirement |
| ทดสอบ login → บริจาค → ใบเสร็จ | `includes/e_receipt.php` |

---

## คำถาม 2.9: อะไรต้องตั้งบนเซิร์ฟเวอร์แต่ไม่มีในไฟล์ config เดียว?

**บทพูด:**

> นอกจากไฟล์ config ยังต้อง:  
> โฟลเดอร์ `uploads/` เขียนได้ — อัปโหลดรูปเด็ก โครงการ หลักฐาน  
> ตั้ง PHP `upload_max_filesize` / `post_max_size` ให้รองรับรูป (~4MB ต่อไฟล์ในหลายหน้า)  
> บัญชี Omise ต้อง **ยืนยันอีเมล** ก่อนใช้ Charge Schedule — คอมเมนต์ `payment/config.php` บรรทัด 10–11  
> ถ้าใช้ PDF แอดมิน ติดตั้ง TCPDF ตาม `README.md` และ `tools/install_tcpdf.ps1`

---

## ประโยคปิดข้อ 2 (พูดจบหัวข้อ)

> **สรุปข้อ 2:** การติดตั้งเริ่มจากวางโค้ด แล้วตั้งค่าใน `db.php` — โหลด `.env` บรรทัด 15–16, เชื่อม DB จาก `config/db.local.php` หรือ env บรรทัด 23–46, migration อัตโนมัติบรรทัด 106–122  
> ชำระเงินที่ `payment/config.php` บรรทัด 32–63, webhook ที่ `payment/omise_webhook.php` secret จาก `.env` บรรทัด 79, cron อุปการะ, และสิทธิ์เขียน `uploads/` กับ `data/`

---

# สรุปสองข้อแบบตอบปากเต็ม (1–2 นาที)

**ข้อ 1 — Server Requirement / VPS:**

> โปรเจกต์ DrawDream เป็นเว็บ PHP + MySQL ผู้ใช้เข้าผ่านเบราว์เซอร์  
> เรากำหนด Server Requirement เป็น VPS ประมาณ 2 GB RAM, PHP 8.2, MySQL 8, HTTPS และ Cron  
> จากนั้นเช่า Virtual Server ตามสเปกนั้นเพื่อรันโค้ดให้คนใช้จริง  
> Omise เป็นบริการภายนอก เรียก API จาก `payment/config.php` บรรทัด 34  
> จุดเชื่อม DB กลางคือ `db.php` บรรทัด 14–16 และ 89–97

**ข้อ 2 — ติดตั้งและ Config:**

> วางโค้ดแล้วตั้ง `config/db.local.php` หรือ `.env` ให้ `db.php` เชื่อม MySQL  
> ตั้ง Omise ใน `payment/config.php`, Webhook secret ใน `.env`, URL webhook บน Omise Dashboard ชี้ `payment/omise_webhook.php`  
> ตั้ง Cron ที่ `payment/cron_child_subscription_charges.php`  
> ให้ `uploads/`, `config/`, `data/` เขียนได้ schema อัปเดตอัตโนมัติที่ `db.php` บรรทัด 106–122

---

## ตารางอ้างอิงด่วน (เปิดไฟล์ตอนถูกถาม)

| หัวข้อ | ไฟล์ | บรรทัด |
|--------|------|--------|
| โหลด .env | `db.php` | 15–16 |
| ฟังก์ชันโหลด .env | `includes/env_loader.php` | 12–55 |
| Config MySQL | `db.php` | 23–46, 54–64, 89–97 |
| Migration อัตโนมัติ | `db.php` | 106–122 |
| คีย์ Omise | `payment/config.php` | 32–34 |
| SSL Omise (cacert) | `payment/config.php` | 37–43 |
| Mock Omise (dev) | `payment/config.php` | 46–55 |
| Cron secret | `payment/config.php` | 58–63 |
| Webhook ตั้งค่า | `payment/omise_webhook.php` | 5–8 |
| Webhook verify | `payment/omise_webhook.php` | 77–91 |
| Google OAuth | `includes/google_oauth.php` | 26–54 |
| Google เริ่ม login | `auth/google_start.php` | 11–14 |
| Cron HTTP guard | `payment/cron_child_subscription_charges.php` | 6–16 |
| Dev server | `run_dev_server.ps1` | 28–39, 60, 74 |
| ไฟล์ไม่ commit | `.gitignore` | 2, 5–6, 15, 27 |

---

*คู่กับ [`server-requirement-and-installation.md`](server-requirement-and-installation.md) · [`drawdream-flow-walkthrough.md`](drawdream-flow-walkthrough.md)*
