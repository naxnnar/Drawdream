<?php
session_start();
header('Location: project.php?view=foundation&msg=' . rawurlencode('ปิดการใช้งานฟีเจอร์สมทบยอดโครงการแล้ว'));
exit();
