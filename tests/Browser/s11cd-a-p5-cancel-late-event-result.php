<?php

declare(strict_types=1);

$payload = file_get_contents('php://input');
error_log('S11CD_A_P5_CANCEL_RESULT '.$payload);

header('Content-Type: application/json');
echo '{"received":true}';
