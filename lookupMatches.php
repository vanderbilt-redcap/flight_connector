<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/NameMatcher.php");

$pid = htmlspecialchars((int) $_GET['pid']);
$dataByName = [];
$json = \REDCap::getData($pid, "json");
$redcapData = json_decode($json, TRUE);
if ($pid && isset($_POST['name'])) {
    $name = $_POST['name'];
    try {
        list($firstName, $lastName) = NameMatcher::splitName($name, 2);
        foreach ($redcapData as $row) {
            if ($row['name']) {
                list($first, $last) = NameMatcher::splitName($name, 2);
            } else {
                $first = $row['firstname'] ?? "";
                $last = $row['lastname'] ?? "";
            }
            if ($first && $last && NameMatcher::matchName($first, $last, $firstName, $lastName)) {
                addRow($dataByName, $row);
            }
        }
    } catch (\Exception $e) {
    }
} else if ($pid && isset($_POST['email'])) {
    $email = trim(strtolower($_POST['email']));
    foreach ($redcapData as $row) {
        if ($email && ($email === trim(strtolower($row['email'])))) {
            addRow($dataByName, $row);
        } else if ($email) {
            list($userid, $domain) = explode("@", trim(strtolower($row['email'])));
            list($postUserid, $postDomain) = explode("@", $email);
            if (
                in_array($domain, ["vanderbilt.edu", "vumc.org"])
                && in_array($postDomain, ["vanderbilt.edu", "vumc.org"])
                && ($userid == $postUserid)
            ) {
                addRow($dataByName, $row);
            }
        }
    }
}
$data = array_values($dataByName);
echo json_encode($data);

function getChecks($row, $checkField) {
    $regex = "/^$checkField"."___/";
    $checks = [];
    foreach ($row as $field => $value) {
        if (($value == '1') && preg_match($regex, $field)) {
            list($f, $index) = explode("___", $field);
            $checks[] = $index;
        }
    }
    return $checks;
}

function addRow(&$dataByName, $row) {
    $name = $row['name'] ?: $row['firstname']." ".$row['lastname'];
    $dataByName[$name."|".$row['email']] = [
        "record" => $row['record_id'],
        "name" => $name,
        "email" => $row['email'],
        "contents" => getChecks($row, "contents"),
        "methods" => getChecks($row, "methods"),
    ];
}