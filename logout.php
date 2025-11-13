<?php
require_once 'includes/helpers/functions.php';

$auth = new Auth();
$auth->logout();

header('Location: ' . url('login'));
exit;
