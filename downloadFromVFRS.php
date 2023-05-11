<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

$credentialsFile = "/Users/pearsosj/credentials/career_dev/vfrs.php";
include($credentialsFile);
$pid = getPid();

$dataRequest = array(
    'token' => $vfrsToken,
    'content' => 'record',
    'action' => 'export',
    'format' => 'json',
    'type' => 'flat',
    'csvDelimiter' => '',
    'fields' => ['participant_id','email','name_last','name_first','c','m'],
    'rawOrLabel' => 'raw',
    'rawOrLabelHeaders' => 'raw',
    'exportCheckboxLabel' => 'false',
    'exportSurveyFields' => 'false',
    'exportDataAccessGroups' => 'false',
    'returnFormat' => 'json'
);
$json = fetchFromServer($dataRequest, $vfrsServer);
$vfrsData = json_decode($json, TRUE);

$metadataRequest = array(
    'token' => $vfrsToken,
    'content' => 'metadata',
    'format' => 'json',
    'returnFormat' => 'json'
);
$json = fetchFromServer($metadataRequest, $vfrsServer);
$vfrsMetadata = json_decode($json, TRUE);
$vfrsChoices = getChoices($vfrsMetadata);

$json = \REDCap::getDataDictionary($pid, "json");
$metadata = json_decode($json, TRUE);
$choices = getChoices($metadata);

$upload = [];
$recordId = 1;
$fields = array_keys(FlightConnectorExternalModule::FIELDS);
foreach ($vfrsData as $row) {
    $uploadRow = [
        "record_id" => $recordId,
        "name" => "{$row['name_first']} {$row['name_last']}",
        "email" => $row['email'],
        FlightConnectorExternalModule::INSTRUMENT."_complete" => "2",
    ];

    foreach (["c" => $fields[0], "m" => $fields[1]] as $vfrsVariable => $destVar) {
        foreach ($row as $field => $value) {
            if ($value && preg_match("/^$vfrsVariable"."___/", $field)) {
                $index = preg_replace("/^$vfrsVariable"."___/", "", $field);
                $label = $vfrsChoices[$vfrsVariable][$index] ?? "";
                foreach ($choices[$destVar] as $idx => $lab) {
                    if (strtolower($lab) == strtolower($label)) {
                        $uploadRow[$destVar."___".$idx] = "1";
                        break;
                    }
                }
            }
        }
    }
    $recordId++;
    $upload[] = $uploadRow;
}

if (!empty($upload)) {
    $feedback = \REDCap::saveData($pid, "json-array", $upload, "overwrite");
    echo json_encode($feedback);
} else {
    echo "No data to upload.";
}
