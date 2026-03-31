<?php

use \Vanderbilt\FlightConnectorExternalModule\NameMatcher;
use \Vanderbilt\FlightConnectorExternalModule\FlightConnectorExternalModule;

# This short script compares the Vanderbilt Flight Connector data against
# a local copy of BRET's Open Lab List and identifies those not currently present
# in Flight Connector. It matches by email user-id or name. Those identified
# might need to be contacted in order to participate.

require_once(__DIR__."/NameMatcher.php");
require_once(__DIR__."/FlightConnectorExternalModule.php");

# destPid is the destination project for the final Open Lab List Flight Connector
if (FlightConnectorExternalModule::isLocalhost()) {
    $pid = FlightConnectorExternalModule::getLocalhostProject("Contents and Methods");
    $destPid = 135;
} elseif (FlightConnectorExternalModule::isVanderbilt()) {
    $pid = FlightConnectorExternalModule::VANDERBILT_MAIN_PROJECT;
    $destPid = 188759;
} else {
    throw new \Exception("Invalid environment!");
}

$redcapData = \REDCap::getData($pid, "json-array");

$fp = fopen(__DIR__."/open_lab_list_03_2024.csv", "r");
$headers = fgetcsv($fp, null, ',', "");
$notFound = [];
$upload = [];
$currRecordId = 1;
while ($line = fgetcsv($fp, null, ',', "")) {
    $piName = $line[0];
    if ($piName) {
        $vuEmail = strtolower($line[1]);
        $vumcEmail = strtolower($line[2]);
        list($piFirst, $piLast) = NameMatcher::splitName($piName);
        $emailUsers = [];
        if ($vuEmail) {
            $emailUsers[] = explode("@", $vuEmail)[0];
        }
        if ($vumcEmail) {
            $emailUsers[] = explode("@", $vumcEmail)[0];
        }
        $found = false;
        foreach ($redcapData as $row) {
            $connectorEmailUser = strtolower($row['email'] ? explode("@", $row['email'])[0] : "");
            list($connectorFirst, $connectorLast) = NameMatcher::splitName($row['name']);
            if (in_array($connectorEmailUser, $emailUsers) || NameMatcher::matchName($connectorFirst, $connectorLast, $piFirst, $piLast)) {
                echo "Matched $piName<br/>";
                $found = true;
                $row['record_id'] = $currRecordId;
                unset($row['in_ldap']);
                unset($row['department']);
                $upload[] = $row;
                $currRecordId++;
                break;
            }
        }
        if (!$found) {
            echo "NOT FOUND $piName: $vuEmail $vumcEmail<br/>";
            $notFound[$piName] = $vuEmail ?: $vumcEmail;
        }
    }
}
fclose($fp);

if (isset($_GET['upload']) && !empty($upload)) {
    $feedback = \REDCap::saveData($destPid, "json", json_encode($upload));
    echo "<p>".json_encode($feedback)."</p>";
} else {
    echo "<p>Not uploading</p>";
}

echo "<h2>".count($notFound)." not found in Vanderbilt Flight Connector</h2>";
foreach ($notFound as $name => $email) {
    echo "$name &lt;$email&gt;<br/>";
}
