<?php

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/DatabaseBackupScheduler.php';

$db = new Database();
$scheduler = new DatabaseBackupScheduler($db);
$scheduler->run();
