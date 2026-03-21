<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) exit();
$uid = (int)$_SESSION['user_id'];

if (isset($_GET['all'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $uid");
    // redirect กลับหน้าเดิม
    $ref = $_SERVER['HTTP_REFERER'] ?? 'profile.php';
    header("Location: $ref");
    exit();
} elseif (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    echo "ok";
}