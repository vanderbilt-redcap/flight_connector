<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

$pid = getPid();
$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");

echo "<title>Flight Connector</title>";
echo "<link rel='stylesheet' href='$cssUrl' />";
echo "<link rel='icon' href='$ftLogo' />";
echo makeJS($module);
echo "<h1>Flight Connector</h1>";
echo makeHeader($module);

$redcapData = getData($pid);
if (empty($redcapData)) {
    echo "<p class='centered max-width thickBorder padded'>No data have been entered.</p>";
    echo makePublicSurveyLink($module);
    exit;
}