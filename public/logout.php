<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';

$_SESSION = [];
session_destroy();
header('Location: ' . BASE . '/login.php');
exit;
