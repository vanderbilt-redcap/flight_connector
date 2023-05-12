<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

$pid = getPid();
$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");

if (!$module->isSetup($pid)) {
    header("Location: ".$module->getUrl("install.php"));
}
$uniqueHash = $module->getProjectSetting(FlightConnectorExternalModule::UNIQUE_HASH_SETTING, $pid) ?? "";
if (($uniqueHash === "") || ($uniqueHash !== $_GET['h'])) {
    die("No access.");
}

echo "<title>Flight Connector</title>";
echo "<link rel='stylesheet' href='$cssUrl' />";
echo "<link rel='icon' href='$ftLogo' />";
echo makeJS($module);
echo makeHeader($module, "Flight Connector");

$redcapData = getData($pid);
if (empty($redcapData)) {
    echo "<p class='centered max-width thickBorder padded'>No data have been entered.</p>";
    echo makePublicSurveyLink($module);
    exit;
}

echo makePublicSurveyLink($module);
