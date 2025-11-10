<?php
require_once 'includes/functions.php';

$auth = new Auth();
$auth->logout();

header('Location: login.php');
exit;
