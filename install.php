<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

$pid = getPid();
$baseUrl = ExternalModules::$BASE_URL ?? APP_URL_EXTMOD_RELATIVE;
$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");
$thisUrl = $module->getUrl("install.php");

$fields = FlightConnectorExternalModule::FIELDS;
$applicableInstrument = FlightConnectorExternalModule::INSTRUMENT;
$instrumentLabel = "Flight Connector";
$publicSurveyLink = "";
$csvData = \REDCap::getDataDictionary($pid, "csv", FALSE);
$csv = [];
foreach (preg_split("/[\n\r]+/", $csvData) as $row) {
    if ($row) {
        $csv[] = str_getcsv($row);
    }
}
if (!empty($_POST)) {
    $headers = $csv[0];
    $fieldIndex = -1;
    $formIndex = -1;
    $selectIndex = -1;
    $labelIndex = -1;
    foreach ($headers as $i => $header) {
        if ($header == "field_name") {
            $fieldIndex = $i;
        } else if ($header == "form_name") {
            $formIndex = $i;
        } else if ($header == "select_choices_or_calculations") {
            $selectIndex = $i;
        } else if ($header == "field_label") {
            $labelIndex = $i;
        }
    }
    if (($fieldIndex < 0) || ($formIndex < 0) || ($selectIndex < 0) || ($labelIndex < 0)) {
        throw new \Exception("Could not find one or more indices!");
    }

    $choiceStr = [];
    $labels = [];
    foreach ($fields as $field => $title) {
        $labels[$field] = htmlentities($_POST[$field."_label"]) ?: $title;
        $options = preg_split("/[\n\r]+/", htmlentities($_POST[$field]));
        $i = 1;
        $pairs = [];
        foreach ($options as $option) {
            $option = trim($option);
            if ($option) {
                $pairs[] = "$i, $option";
                $i++;
            }
        }
        if (empty($pairs)) {
            throw new \Exception("$field has its options empty!");
        }
        $choiceStr[$field] = implode(" | ", $pairs);
    }

    $rowIndexes = [];
    $insertAtRow = count($csv);
    foreach ($csv as $i => $row) {
        if ($i > 0) {
            if (in_array($row[$fieldIndex], array_keys($fields))) {
                $rowIndexes[$row[$fieldIndex]] = $i;
            }
            if ($row[$formIndex] == $applicableInstrument) {
                $insertAtRow = $i + 1;
            }
        }
    }

    $rowsToInsert = [
        "name" => ["name", $applicableInstrument, "", "text", "Name", "", "", "", "", "", "y", "", "y", "", "", "", "", ""],
        "email" => ["email", $applicableInstrument, "", "text", "Email", "", "", "email", "", "", "y", "", "y", "", "", "", "", ""],
    ];
    foreach (array_keys($fields) as $field) {
        $rowsToInsert[$field] = [$field, $applicableInstrument, "", "checkbox", $labels[$field], $choiceStr[$field], "", "", "", "", "", "", "", "LV", "", "", "", ""];
    }
    if (count($rowIndexes) == count($fields)) {
        foreach (array_keys($fields) as $field) {
            $index = $rowIndexes[$field];
            $csv[$index][$selectIndex] = $choiceStr[$field];
        }
    } else if (empty($rowIndexes)) {
        if ($insertAtRow == count($csv)) {
            if (count($csv) == 2) {
                $csv[1][$formIndex] = $applicableInstrument;
            }
            $csv = array_merge($csv, $rowsToInsert);
        } else {
            $old = $csv;
            $csv = [];
            foreach ($old as $i => $row) {
                if ($i == $insertAtRow) {
                    foreach (array_values($rowsToInsert) as $insertRow) {
                        $csv[] = $insertRow;
                    }
                }
                $csv[] = $row;
            }
            unset($old);
        }
    } else {
        # some but not all
        $old = $csv;
        $csv = [];
        foreach ($old as $i => $row) {
            if ($i == $insertAtRow) {
                foreach ($rowsToInsert as $field => $insertRow) {
                    $inOldCSV = FALSE;
                    foreach ($old as $row2) {
                        if ($row2["field_name"] == $field) {
                            $inOldCSV = TRUE;
                        }
                    }
                    if (!$inOldCSV) {
                        $csv[] = $insertRow;
                    }
                }
            }
            if (isset($choiceStr[$row['field_name']])) {
                $row[$selectIndex] = $choiceStr[$row['field_name']];
            }
            $csv[] = $row;
        }
        unset($old);
    }

    $filename = APP_PATH_TEMP.bin2hex(random_bytes(8))."_".$applicableInstrument.".csv";
    $fp = fopen($filename, "w");
    foreach ($csv as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    $module->importDataDictionary($pid, $filename);
    unlink($filename);

    if (empty($rowIndexes)) {
        $sql = "UPDATE redcap_projects SET surveys_enabled='1' WHERE project_id = ?";
        $module->query($sql, [$pid]);

        $surveyIntroText = '<p><strong>Please complete the survey below with your expertise.</strong></p><p>Thank you!</p>';
        $surveyCompletionText = '<p><strong>Thank you for taking the survey.</strong></p><p>Have a nice day!</p>';
        $sql = "INSERT INTO redcap_surveys (project_id, font_family, form_name, title, instructions, acknowledgement, question_by_section, question_auto_numbering, survey_enabled, save_and_return, logo, hide_title, view_results, min_responses_view_results, check_diversity_view_results, end_survey_redirect_url, survey_expiration) VALUES (?, '16', ?, ?, ?, ?, 0, 1, 1, 1, NULL, 0, 0, 10, 0, NULL, NULL)";
        $module->query($sql, [$pid, $applicableInstrument, $instrumentLabel, $surveyIntroText, $surveyCompletionText]);

        $sql = "SELECT survey_id FROM redcap_surveys WHERE project_id = ? AND form_name = ?";
        $result = $module->query($sql, [$pid, $applicableInstrument]);
        if ($row = $result->fetch_assoc()) {
            $surveyId = $row['survey_id'];
            $hash = \Survey::getUniqueHash(16, TRUE);
            $eventId = getEventIdForClassical($module, $pid);
            $sql = "INSERT INTO redcap_surveys_participants (survey_id, event_id, hash) VALUES (?, ?, ?)";
            $module->query($sql, [$surveyId, $eventId, $hash]);
            $publicSurveyLink = APP_PATH_SURVEY_FULL."index.php?s=$hash";
        } else {
            throw new \Exception("Did not set up survey!");
        }
    }
} else if (count($csv) > 2) {
    $hasSurvey = FALSE;
    foreach ($csv as $row) {
        if ($row['form_name'] == $applicableInstrument) {
            $hasSurvey = TRUE;
            break;
        }
    }
    if ($hasSurvey) {
        $publicSurveyLink = $module->getPublicSurveyUrl($pid) ?? "";
    }
}

echo "<title>Flight Connector Installation</title>";
echo "<link rel='stylesheet' href='$cssUrl' />";
echo "<link rel='icon' href='$ftLogo' />";
echo makeJS($module);

if ($publicSurveyLink) {
    $mapUrl = $module->getUrl("map.php");
    $projectHomeUrl = APP_PATH_WEBROOT."index.php?pid=$pid";
    echo "<h1>Flight Connector</h1>";
    echo makeHeader($module);
    echo "<h2>Setup Complete!</h2>";
    echo makePublicSurveyLink($module, $publicSurveyLink);
    echo "<p class='centered max-width'>Share this public link to see the Flight Tracker Map real-time as the data grows. <br/><input id='mapUrl' value='$mapUrl' onclick='this.select();' readonly='readonly' style='width: 90%; max-width: 450px; margin-right: 5px; margin-left: 5px;' /><span class='smaller'><a href='javascript:;' onclick='copyToClipboard($(\"#mapUrl\"));'>Copy</a></span></p>";
    echo "<p class='centered max-width'>Both of these links are available in REDCap's left-hand toolbar. The Public Survey Link is available under Survey Distribution Tools, and the Flight Connector Map is under Bookmarks.</p>";
    echo "<p class='centered max-width'><a href='$projectHomeUrl'>To the project's home page</a></p>";
} else {
    echo "<h1>Flight Connector Installation</h1>";
    echo makeHeader($module);
    echo "<p class='centered smaller'><a href='$baseUrl"."manager/project.php?pid=$pid'>Wrong Page? Disable this Module</a></p>";

    echo "<p class='centered max-width'>Flight Connector is a networking tool to help groups of knowledge workers identify colleagues with areas of expertise, whether intellectual content or methodologies. This page will generate a survey for participants to fill out. The survey will contain checkboxes for each item for participants to identify their strengths. These strengths will, in turn, produce an interactive social network map for broader viewing.</p>";
    echo "<form action='$thisUrl' method='POST'>";
    $csrfToken = $module->getCSRFToken();
    echo "<input type='hidden' id='redcap_csrf_token' name='redcap_csrf_token' value='$csrfToken' />";
    $requiredFields = [];
    foreach ($fields as $field => $label) {
        $labelField = $field."_label";
        echo "<h2>Options for $label</h2>";
        $defaultLabel = isset($_POST[$labelField]) ? htmlentities($_POST[$labelField]) : "";
        $defaultEntry = isset($_POST[$field]) ? htmlentities($_POST[$field]) : "";
        echo "<p class='centered max-width'><label for='$labelField'>Label:</label> <input type='text' id='$labelField' name='$labelField' placeholder='$label' value='$defaultLabel' style='width: 200px;' /><br/><label for='$field'>Checkbox Options (one per line):</label><br/><textarea name='$field' id='$field' style='width: 400px; height: 200px;'>$defaultEntry</textarea></p>";
        $requiredFields[] = $labelField;
        $requiredFields[] = $field;
    }
    $requiredFieldsJSON = json_encode($requiredFields);
    echo "<p class='centered'><button onclick='if (isInvalid($requiredFieldsJSON)) { alert(\"You must fill in a value for all labels and options.\"); return false; } else { return true; } '>Set Up Project</button></p>";
    echo "</form>";
}


