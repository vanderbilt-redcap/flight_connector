<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/FlightConnectorExternalModule.php");

$mentors = [
    "Raymond Blind",
    "Fiona Harrison",
    "Alyssa Hasty",
    "Timothy Hohman",
    "James West",
];

$destPid = 176037;
$identifyingFields = $module->getFieldsForMainProject();
$result = $module->refreshFlightConnector($destPid, $identifyingFields, FALSE, $mentors);
echo json_encode($result);