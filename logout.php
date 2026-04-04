<?php
// logout.php — ออกจากระบบและล้างเซสชัน
session_start();
session_unset();
session_destroy();
header("Location: login.php");
exit();
?>
