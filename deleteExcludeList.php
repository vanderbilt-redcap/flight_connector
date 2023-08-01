<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/FlightConnectorExternalModule.php");

$data = \REDCap::getData(FlightConnectorExternalModule::VANDERBILT_MAIN_PROJECT, "json-array");
$excludeList = FlightConnectorExternalModule::getExcludeList();
$recordsToDelete = [];
foreach ($data as $row) {
    if (in_array($row['name'], $excludeList)) {
        $recordsToDelete[] = $row['record_id'];
    }
}

foreach ($recordsToDelete as $recordId) {
    \REDCap::deleteRecord(FlightConnectorExternalModule::VANDERBILT_MAIN_PROJECT, $recordId);
}
echo count($recordsToDelete)." records deleted.";
