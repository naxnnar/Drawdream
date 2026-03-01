<?php
session_start();

if (!isset($_SESSION['email'])) {
  header("Location: login.php?as=donor");
  exit();
}

$role = $_SESSION['role'] ?? 'donor';

if ($role === 'foundation') {
  header("Location: foundation_profile.php");
  exit();
}

if ($role === 'admin') {
  header("Location: admin_projects.php");
  exit();
}

// donor (ค่า default)
header("Location: p1_home.php");
exit();