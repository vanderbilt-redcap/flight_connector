<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/base.php");

$identifyingFields = [
    15941 => ["record_id" => "participant_id", "first_name" => "name_first", "last_name" => "name_last", "email" => "email"],
    72575 => ["record_id" => "record_id", "first_name" => "first_name", "last_name" => "last_name", "email" => "email"],
    72516 => ["record_id" => "record_id", "first_name" => "first_name", "last_name" => "last_name", "email" => "email"],
];
$checkboxFields = ["c" => "contents", "m" => "methods"];
$destPid = $_GET['pid'];

$json = \REDCap::getDataDictionary($destPid, "json");
$metadata = json_decode($json, TRUE);
$choices = getChoices($metadata);
$destData = \REDCap::getData($destPid, "json-array");
$maxRecordId = 0;
foreach ($destData as $row) {
    if ($row['record_id'] > $maxRecordId) {
        $maxRecordId = $row['record_id'];
    }
}
$recordId = $maxRecordId + 1;

$uploadByName = [];
foreach ($identifyingFields as $pid => $fieldMappings) {
    $json = \REDCap::getDataDictionary($pid, "json");
    $pidMetadata = json_decode($json, TRUE);
    $pidChoices = getChoices($pidMetadata);
    $redcapData = \REDCap::getData($pid, "json-array");
    foreach ($redcapData as $row) {
        $translation = [];
        foreach ($fieldMappings as $to => $from) {
            $translation[$to] = $row[$from];
        }
        if ($translation["first_name"] && $translation['last_name']) {
            $name = $translation['first_name']." ".$translation['last_name'];
            $email = $translation['email'];
            $uploadRow = [
                "record_id" => $recordId,
                "name" => $name,
                "email" => $email,
                "flight_connector_survey_complete" => "2",
            ];
            $recordId++;
            foreach ($row as $fullField => $value) {
                if (($value === "1") && preg_match("/___/", $fullField)) {
                    list($field, $index) = explode("___", $fullField);
                    if (isset($checkboxFields[$field])) {
                        $destField = $checkboxFields[$field];
                        $label = $pidChoices[$field][$index];
                        $newIndex = FALSE;
                        foreach ($choices[$destField] as $destIndex => $destLabel) {
                            if ($label == $destLabel) {
                                $newIndex = $destIndex;
                                break;
                            }
                        }
                        if ($newIndex !== FALSE) {
                            $uploadRow[$destField."___".$newIndex] = "1";
                        } else {
                            echo "Skipping $pid:{$row['record_id']} $field: $index '$label'<br/>";
                        }
                    }
                }
            }
            $uploadByName[$name] = $uploadRow;
        }
    }
}

$json = json_encode(array_values($uploadByName));
$feedback = \REDCap::saveData($destPid, "json", $json, "overwrite");
echo "Uploaded ".count($uploadByName)." rows with last record = ".($recordId - 1)."<br/>";
echo json_encode($feedback)."<br/>";