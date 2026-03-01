<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "PHP ทำงานปกติ<br>";

// ทดสอบเชื่อมต่อฐานข้อมูล
include 'db.php';
echo "เชื่อมต่อฐานข้อมูลสำเร็จ<br>";

// ทดสอบ query
$result = $conn->query("SELECT 1");
if ($result) {
    echo "Query ทำงานได้<br>";
} else {
    echo "Query ผิดพลาด: " . $conn->error;
}
?>