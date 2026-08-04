# Deploy DrawDream บน VPS (Ubuntu 22.04)

IP ตัวอย่าง: `82.26.104.99`

## วิธี A — จาก Windows (แนะนำ)

### ตั้ง SSH key ครั้งเดียว (ไม่ถามรหัสทุกไฟล์)

```powershell
cd C:\Project\drawdream
.\scripts\deploy\setup-ssh-key.ps1
```

ใส่รหัส `root` **แค่ครั้งเดียว** หลังนั้น deploy ใช้ alias `drawdream-vps` ได้เลย

```powershell
.\scripts\deploy\sync-needlist-ux.ps1 -SshHost drawdream-vps
.\scripts\deploy\sync-code.ps1 -SshHost drawdream-vps
```

สคริปต์ sync รุ่นใหม่ **อัปโหลดทีเดียว** (tar.gz 1 ไฟล์) แทน scp ทีละไฟล์

### Deploy เต็ม (zip ทั้งโปรเจกต์)

1. เปิด PowerShell ที่ `C:\Project\drawdream`
2. รัน:

```powershell
.\scripts\deploy\push-from-windows.ps1
```

3. ถ้ายังไม่มี SSH key ใส่รหัสผ่าน `root` เมื่อถาม (2–3 ครั้ง)
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
