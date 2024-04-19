<?php

require_once APP_PATH_DOCROOT . "Config/init_global.php";
// Include the QR Code class
require_once APP_PATH_LIBRARIES . "phpqrcode/lib/full/qrlib.php";
// Check value that is expected
if (!isset($_GET['value'])) exit;
// Output QR code image
\QRcode::png(urldecode($_GET['value']), false, 'H', 4);
