<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/NameMatcher.php");

$pids = $module->getProjectsWithModuleEnabled();
if (FlightConnectorExternalModule::isVanderbilt()) {
    $vfrsPid = FlightConnectorExternalModule::VFRS_SURVEY_OF_INTENT;
} else if (FlightConnectorExternalModule::isLocalhost()) {
    $vfrsPid = FlightConnectorExternalModule::getLocalhostProject("VFRS");
} else {
    $vfrsPid = FALSE;
}
if ($vfrsPid) {
    $pids[] = $vfrsPid;
}

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
                } else if ($row['name_first'] && $row['name_last']) {
                    $first = $row['name_first'];
                    $last = $row['name_last'];
                } else {
                    $first = $row['firstname'] ?? "";
                    $last = $row['lastname'] ?? "";
                }
                if ($first && $last && NameMatcher::matchName($first, $last, $firstName, $lastName)) {
                    if (!isset($currMetadata)) {
                        $metadataJSON = \REDCap::getDataDictionary($currPid, "json");
                        $currMetadata = json_decode($metadataJSON, TRUE);
                    }
                    addRow($dataByName, $currPid, $_GET['pid'], $row, $currMetadata, $module);
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
                addRow($dataByName, $currPid, $_GET['pid'], $row, $currMetadata, $module);
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
                    addRow($dataByName, $currPid, $_GET['pid'], $row, $currMetadata, $module);
                }
            }
        }
    }
}
$data = array_values($dataByName);
echo json_encode($data);

function getChecks(array $row, string $checkField, array $choices): array {
    $abbrevField = substr($checkField, 0, 1);
    $regex = "/^$checkField"."___/";
    $abbrevRegex = "/^$abbrevField"."___/";
    $checks = [];
    foreach ($row as $field => $value) {
        if (
            ($value == '1')
            && (
                preg_match($regex, $field)
                || preg_match($abbrevRegex, $field)
            )
        ) {
            list($f, $index) = explode("___", $field);
            $checks[$index] = $choices[$checkField][$index] ?? $choices[$abbrevField][$index] ?? FlightConnectorExternalModule::UNKNOWN_CHOICE;
        }
    }
    return $checks;
}

function addRow(array &$dataByName, string $currPid, string $targetPid, array $row, array $metadata, \ExternalModules\AbstractExternalModule $module): void {
    $choices = getChoices($metadata);
    if ($row['name']) {
        $name = $row['name'];
    } else if ($row['firstname'] || $row['lastname']) {
        $name = $row['firstname']." ".$row['lastname'];
    } else if ($row['name_first'] || $row['name_last']) {
        $name = $row['name_first']." ".$row['name_last'];
    } else {
        $name = "";
    }
    $projectName = getProjectName($module, $currPid);
    $projectName = preg_replace("/\s*Flight Connector\s*/", "", $projectName);
    $projectName = preg_replace("/^-\s*/", "", $projectName);
    $isContentsAndMethods = FlightConnectorExternalModule::isContentsAndMethods($targetPid) || FlightConnectorExternalModule::isVFRS($targetPid);
    $contentsField = $isContentsAndMethods ? "c" : "contents";
    $methodsField = $isContentsAndMethods ? "m" : "methods";
    $dataByName[$name."|".$row['email']] = [
        "record" => $row['record_id'] ?? $row['participant_id'],
        "pid" => $currPid,
        "projectName" => $projectName,
        "name" => $name,
        "email" => $row['email'] ?: FlightConnectorExternalModule::UNKNOWN_CHOICE,
        $contentsField => getChecks($row, "contents", $choices),
        $methodsField => getChecks($row, "methods", $choices),
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