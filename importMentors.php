<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/FlightConnectorExternalModule.php");

$mentors = [
    "Ray Blind",
    "Fiona Harrison",
    "Alyssa Hasty",
    "Tim Hohman",
    "James West",
];

$destPid = 176037;
$identifyingFields = $module->getFieldsForMainProject();
$module->refreshFlightConnector($destPid, $identifyingFields, FALSE, $mentors);