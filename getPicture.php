<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");
$pid = getPid();
$fileId = $_GET['fileId'] ?? "";
if (!$module->isSetup($pid) || !$fileId) {
    die("");
}

$redcapData = \REDCap::getData($pid, "json-array", [], ["picture"]);
foreach ($redcapData as $row) {
    if ($row['picture'] == $fileId) {
        try {
            displayEdoc($module, $fileId);
        } catch(\Exception $e) {
        }
        exit;
    }
}