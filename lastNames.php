<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use ExternalModules\Framework;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/NameMatcher.php");

$pid = htmlentities($_GET['pid'] ?? "");
if (!$pid) {
	die("No project");
}

$FCEM = new FlightConnectorExternalModule();
$redcapData = $FCEM->framework->escape(\REDCap::getData($pid, "json-array"));

$names = [];
foreach ($redcapData as $row) {
	$names[$row['record_id']] = $row['name'];
}

echo "<table>";
echo "<thead><tr><th>Record ID</th><th>First Name</th><th>Last Name</th></tr>";
echo "<tbody>";
foreach ($names as $recordId => $name) {
	list($fn, $ln) = NameMatcher::splitName($name, 2);
	echo "<tr><td>$recordId</td><td>$fn</td><td>$ln</td></tr>";
}
echo "</tbody></table>";
