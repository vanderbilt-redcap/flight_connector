<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/NameMatcher.php");
require_once(__DIR__."/base.php");

# does not consider dates and so may upload duplicates

# Edge for Scholars and Forum Flight Trackers
$sourcePids = [176037, 182331];
$destPid = 72516;    // Content and Methods survey

$sourceData = [];
$sourceMetadata = [];
$sourceChoices = [];
foreach ($sourcePids as $sourcePid) {
    $sourceData[$sourcePid] = \REDCap::getData($sourcePid, "json-array");
    $json = \REDCap::getDataDictionary($sourcePid, "json");
    $sourceMetadata[$sourcePid] = json_decode($json, TRUE);
    $sourceChoices[$sourcePid] = getChoices($sourceMetadata[$sourcePid]);
}
$destData = \REDCap::getData($destPid, "json-array", NULL, ["record_id", "first_name", "last_name"]);
$json = \REDCap::getDataDictionary($destPid, "json");
$destMetadata = json_decode($json, TRUE);
$destChoices = getChoices($destMetadata);

$matchesToDest = [];
foreach ($sourceData as $sourcePid => $redcapData) {
    foreach ($redcapData as $sourceRow) {
        if ($sourceRow['name']) {
            list($fn, $ln) = NameMatcher::splitName($sourceRow['name']);
            foreach ($destData as $destRow) {
                if (NameMatcher::matchName($fn, $ln, $destRow['first_name'], $destRow['last_name'])) {
                    $matchesToDest[$destRow['record_id']] = $sourcePid.":".$sourceRow['record_id'];
                }
            }
        }
    }
}

$upload = [];
foreach ($matchesToDest as $destRecordId => $sourceLicensePlate) {
    $recordDestData = getDataRows($destData, $destRecordId);
    $maxInstance = 0;
    foreach ($recordDestData as $row) {
        if ($row['redcap_repeat_instance'] > $maxInstance) {
            $maxInstance = $row['redcap_repeat_instance'];
        }
    }
    list($sourcePid, $sourceRecordId) = explode(":", $sourceLicensePlate);
    $recordSourceData = getDataRows($sourceData[$sourcePid], $sourceRecordId);
    $sourceRow = $recordSourceData[0];
    $sourceName = $sourceRow['name'];
    list($sourceFN, $sourceLN) = NameMatcher::splitName($sourceName);
    $uploadRow = [
        "record_id" => $destRecordId,
        "redcap_repeat_instrument" => "contents_and_methods_inventory",
        "redcap_repeat_instance" => $maxInstance + 1,
        "submitted_date" => date("Y-m-d"),
        "first_name" => $sourceFN,
        "last_name" => $sourceLN,
        "email" => $sourceRow['email'],
        "contents_and_methods_inventory_complete" => "2",
    ];

    $mappings = [
        "contents" => "c",
        "methods" => "m",
    ];
    foreach ($sourceRow as $field => $value) {
        if ($value && preg_match("/___/",$field)) {
            list($sourceField, $sourceIndex) = explode("___", $field);
            $destField = $mappings[$sourceField] ?? "";
            if ($destField) {
                $sourceLabel = $sourceChoices[$sourcePid][$sourceField][$sourceIndex] = "";
                foreach ($destChoices[$destField] as $destIndex => $destLabel) {
                    if ($sourceLabel && ($destLabel == $sourceLabel)) {
                        $uploadRow[$destField."___".$destIndex] = "1";
                    }
                }
            }
        }
    }

    if (count($uploadRow) > 7) {
        # checkbox data added to 7 default fields
        $upload[] = $uploadRow;
    }
}

if (!empty($upload)) {
    $feedback = \REDCap::saveData($destPid, "json-array", $upload);
    echo json_encode($feedback);
} else {
    echo "Nothing to upload.";
}

function getDataRows($redcapData, $recordId) {
    $rows = [];
    foreach ($redcapData as $row) {
        if ($row['record_id'] == $recordId) {
            $rows[] = $row;
        }
    }
    return $rows;
}
