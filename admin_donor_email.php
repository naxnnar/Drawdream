<?php
// admin_donor_email.php — หน้าเตรียมส่งอีเมลถึงผู้บริจาค (เปิดโปรแกรมอีเมลของเครื่อง)

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน donor email

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$uid = (int)($_GET['user_id'] ?? 0);
if ($uid <= 0) {
    header('Location: admin_donors.php');
    exit();
}

$stmt = $conn->prepare('SELECT d.first_name, d.last_name, u.email FROM donor d JOIN `user` u ON u.user_id = d.user_id WHERE d.user_id = ? LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    header('Location: admin_donors.php');
    exit();
}

$email = trim((string)($row['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: admin_donors.php');
    exit();
}

$name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
if ($name === '') {
    $name = 'ผู้บริจาค';
}

$defaultSubject = 'DrawDream — ข้อความจากผู้ดูแลระบบ';
$defaultBody = "เรียน {$name}\r\n\r\n";
$mailtoQuery = http_build_query(
    [
        'subject' => $defaultSubject,
        'body' => $defaultBody,
    ],
    '',
    '&',
    PHP_QUERY_RFC3986
);
$mailtoHref = 'mailto:' . $email . '?' . $mailtoQuery;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ส่งอีเมลถึงผู้บริจาค | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <style>
        .admin-mail-wrap { max-width: 560px; margin: 28px auto; padding: 0 20px 40px; }
        .admin-mail-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.08);
            padding: 24px 22px;
            border: 1px solid #e2e8f0;
        }
        .admin-mail-card h1 {
            margin: 0 0 8px;
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
            font-family: 'Prompt', sans-serif;
        }
        .admin-mail-meta { color: #64748b; font-size: 0.92rem; margin-bottom: 20px; line-height: 1.5; }
        .admin-mail-meta strong { color: #334155; }
        .admin-mail-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .admin-mail-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 22px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 0.95rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }
        .admin-mail-btn--primary {
            background: #4e3b84;
            color: #fff;
        }
        .admin-mail-btn--primary:hover { filter: brightness(1.06); color: #fff; }
        .admin-mail-btn--ghost {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }
        .admin-mail-note {
            margin-top: 16px;
            font-size: 0.86rem;
            color: #64748b;
            line-height: 1.55;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-mail-wrap">
    <a class="admin-directory-back" href="admin_donors.php">← กลับไปผู้บริจาคทั้งหมด</a>
    <div class="admin-mail-card">
        <h1>ส่งอีเมลถึงผู้บริจาค</h1>
        <p class="admin-mail-meta">
            <strong>ถึง:</strong> <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?><br>
            <strong>อีเมล:</strong> <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="admin-mail-meta" style="margin-bottom:0;">
            กดปุ่มด้านล่างเพื่อเปิดโปรแกรมอีเมลบนเครื่องของคุณ (เช่น Outlook, Gmail App, Apple Mail)
            พร้อมหัวข้อและเนื้อหาเริ่มต้น — คุณสามารถแก้ไขก่อนส่งได้ในโปรแกรมอีเมล
        </p>
        <div class="admin-mail-actions">
            <a class="admin-mail-btn admin-mail-btn--primary" href="<?= htmlspecialchars($mailtoHref, ENT_QUOTES, 'UTF-8') ?>">เปิดโปรแกรมอีเมล</a>
            <a class="admin-mail-btn admin-mail-btn--ghost" href="admin_donors.php">ยกเลิก</a>
        </div>
        <p class="admin-mail-note">
            หมายเหตุ: ระบบจะไม่ส่งอีเมลจากเซิร์ฟเวอร์โดยตรง การส่งผ่าน <code>mailto:</code> ขึ้นกับโปรแกรมอีเมลที่ติดตั้งบนอุปกรณ์ของคุณ
        </p>
    </div>
</div>
</body>
</html>
