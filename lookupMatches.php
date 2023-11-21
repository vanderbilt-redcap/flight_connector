<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/NameMatcher.php");

$pids = $_POST['pids'];
$dataByName = [];
foreach ($pids as $currPid) {
    $currMetadata = NULL;
    if ($currPid && isset($_POST['name']) && $_POST['name']) {
        $currPid = htmlspecialchars((int) $currPid, ENT_QUOTES);
        $json = \REDCap::getData($currPid, "json");
        $redcapData = json_decode($json, TRUE);
        $name = $_POST['name'];
        try {
            list($firstName, $lastName) = NameMatcher::splitName($name, 2);
            foreach ($redcapData as $row) {
                if ($row['name']) {
                    list($first, $last) = NameMatcher::splitName($row['name'], 2);
                } else {
                    $first = $row['firstname'] ?? "";
                    $last = $row['lastname'] ?? "";
                }
                if ($first && $last && NameMatcher::matchName($first, $last, $firstName, $lastName)) {
                    if (!isset($currMetadata)) {
                        $metadataJSON = \REDCap::getDataDictionary($currPid, "json");
                        $currMetadata = json_decode($metadataJSON, TRUE);
                    }
                    addRow($dataByName, $currPid, $row, $currMetadata, $module);
                }
            }
        } catch (\Exception $e) {
        }
    } else if ($currPid && isset($_POST['email']) && $_POST['email']) {
        $email = trim(strtolower($_POST['email']));
        foreach ($redcapData as $row) {
            if ($email && ($email === trim(strtolower($row['email'])))) {
                if (!isset($currMetadata)) {
                    $metadataJSON = \REDCap::getDataDictionary($currPid, "json");
                    $currMetadata = json_decode($metadataJSON, TRUE);
                }
                addRow($dataByName, $currPid, $row, $currMetadata, $module);
            } else if ($email) {
                list($userid, $domain) = explode("@", trim(strtolower($row['email'])));
                list($postUserid, $postDomain) = explode("@", $email);
                if (
                    in_array($domain, ["vanderbilt.edu", "vumc.org"])
                    && in_array($postDomain, ["vanderbilt.edu", "vumc.org"])
                    && ($userid == $postUserid)
                ) {
                    if (!isset($currMetadata)) {
                        $metadataJSON = \REDCap::getDataDictionary($currPid, "json");
                        $currMetadata = json_decode($metadataJSON, TRUE);
                    }
                    addRow($dataByName, $currPid, $row, $currMetadata, $module);
                }
            }
        }
    }
}
$data = array_values($dataByName);
echo json_encode($data);

function getChecks($row, $checkField, $choices) {
    $regex = "/^$checkField"."___/";
    $checks = [];
    foreach ($row as $field => $value) {
        if (($value == '1') && preg_match($regex, $field)) {
            list($f, $index) = explode("___", $field);
            $checks[$index] = $choices[$checkField][$index] ?? FlightConnectorExternalModule::UNKNOWN_CHOICE;
        }
    }
    return $checks;
}

function addRow(&$dataByName, $currPid, $row, $metadata, $module) {
    $choices = getChoices($metadata);
    $name = $row['name'] ?: $row['firstname']." ".$row['lastname'];
    $projectName = getProjectName($module, $currPid);
    $projectName = preg_replace("/\s*Flight Connector\s*/", "", $projectName);
    $dataByName[$name."|".$row['email']] = [
        "record" => $row['record_id'],
        "pid" => $currPid,
        "projectName" => $projectName,
        "name" => $name,
        "email" => $row['email'],
        "contents" => getChecks($row, "contents", $choices),
        "methods" => getChecks($row, "methods", $choices),
    ];
}

function getProjectName($module, $pid) {
    $sql = "SELECT app_title FROM redcap_projects WHERE project_id = ? LIMIT 1";
    $result = $module->query($sql, [$pid]);
    if ($row = $result->fetch_assoc()) {
        return htmlspecialchars($row['app_title'], ENT_QUOTES);
    }
    return "";
}