# Deploy DrawDream บน VPS (Ubuntu 22.04)

IP ตัวอย่าง: `82.26.104.99`

## วิธี A — จาก Windows (แนะนำ)

1. เปิด PowerShell ที่ `C:\Project\drawdream`
2. รัน:

```powershell
.\scripts\deploy\push-from-windows.ps1
```

3. ใส่รหัสผ่าน `root` ของ VPS เมื่อถาม (2–3 ครั้ง)
4. เปิด `http://82.26.104.99/login.php`

ถ้ายังไม่มี CA Aiven บนเซิร์ฟเวอร์:

```bash
ssh root@82.26.104.99
nano /etc/ssl/aiven/ca.pem   # วางจาก Aiven Console
nano /var/www/drawdream/.env # ตรวจ DB_* และ DB_SSL_CA
cd /var/www/drawdream && php -r "require 'includes/env_loader.php'; drawdream_load_env_file(__DIR__.'/.env'); require 'db.php'; echo 'DB OK'.PHP_EOL;"
```

## วิธี B — รันบนเซิร์ฟเวอร์อย่างเดียว

```bash
ssh root@82.26.104.99
# อัปโหลด server-bootstrap.sh มาก่อน หรือ wget หลัง push GitHub
DRAWDREAM_SERVER_IP=82.26.104.99 bash server-bootstrap.sh
```

## หลังมีโดเมน

```bash
certbot --nginx -d drawdream.org -d www.drawdream.org
```
