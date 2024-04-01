<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

$pid = getPid();

if (!$module->isSetup($pid)) {
    header("Location: ".$module->getUrl("install.php"));
}
$uniqueHash = $module->getProjectSetting(FlightConnectorExternalModule::UNIQUE_HASH_SETTING, $pid) ?? "";
if (($uniqueHash === "") || ($uniqueHash !== $_GET['h'])) {
    die("No access.");
}

$json = \REDCap::getDataDictionary($pid, "json");
$metadata = json_decode($json, TRUE);
$redcapData = getData($pid);
$projectTitle = shortenTitle(\REDCap::getProjectTitle());

echo "<!doctype html><html lang='en-US'>";
echo "<head>";
$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");
$migrateLink = $module->getUrl("jquery-migrate.min.js");
$jqueryUICSS = $module->getUrl("jquery-ui.min.css");
$jqueryUIJS = $module->getUrl("jquery-ui.min.js");
$autocompleteJS = $module->getUrl("autocomplete.js");
$d3Link = $module->getUrl("d3.v3.min.js");
$saveDiv = makeSaveDiv($module, "svg");
echo makeJS($module);
echo "	<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />
	<meta http-equiv='X-UA-Compatible' content='IE=edge,chrome=1' />
	<meta name='viewport' content='width=device-width,initial-scale=1' />	 	
	<link rel='profile' href='http://gmpg.org/xfn/11' />
	<link rel='stylesheet' href='https://use.typekit.net/ght7wyw.css' />
	<link rel='stylesheet' href='$jqueryUICSS' />
	<script type='text/javascript' src='$migrateLink'></script>
	<script type='text/javascript' src='$jqueryUIJS'></script>
	<script type='text/javascript' src='$autocompleteJS'></script>
	<title>Flight Connector: $projectTitle</title>
	<link rel='stylesheet' href='$cssUrl' />
	<link rel='icon' href='$ftLogo' />";
echo "</head>";
echo "<body>";
echo makeHeader($module, "Flight Connector");

if (empty($redcapData)) {
    echo "<p class='centered max-width thickBorder padded'>No data have been entered.</p>";
    echo makeBothLinks($module);
    exit;
}

echo "
    <h2>$projectTitle</h2>
    <div id='container'>
		<div class='wrap'>
			<div id='main'>
				<div id='main-graph-info' class='graph-info'></div>
				<div id='graph'></div>
				$saveDiv
				<script type='text/javascript' src='$d3Link'></script>
			</div>			
		</div>
				
		</div>
	</div>";
