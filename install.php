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

$fields = $module->getFields($pid);
$applicableInstrument = FlightConnectorExternalModule::INSTRUMENT;
$hashSetting = FlightConnectorExternalModule::UNIQUE_HASH_SETTING;
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
        $labels[$field] = htmlspecialchars($_POST[$field."_label"], ENT_QUOTES) ?: $title;
        $options = preg_split("/[\n\r]+/", htmlspecialchars($_POST[$field], ENT_QUOTES));
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

    $checkboxForRequireLogin = $_POST['requireLogin'] ?? "";
    $requireLogin = ($checkboxForRequireLogin == "on");
    $module->setProjectSetting(FlightConnectorExternalModule::REQUIRE_LOGIN_SETTING, $requireLogin, $pid);

    if (empty($rowIndexes)) {
        $sql = "UPDATE redcap_projects SET surveys_enabled='1' WHERE project_id = ?";
        $module->query($sql, [$pid]);

        $surveyIntroText = '<p><strong>Please complete the survey below with your areas of expertise.</strong></p><p>Thank you!</p>';
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

            $module->setProjectSetting($hashSetting, bin2hex(random_bytes(8)), $pid);
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
    $projectHomeUrl = APP_PATH_WEBROOT."index.php?pid=$pid";
    echo makeHeader($module, "Flight Connector");
    echo "<h2>Setup Complete!</h2>";
    echo makeBothLinks($module, "all");
    echo "<p class='centered max-width'>These links are available in REDCap's left-hand toolbar. The Public Survey Link is available under Survey Distribution Tools, and the Flight Connector Map is under Bookmarks.</p>";
    echo "<p class='bolded centered max-width'><a href='$projectHomeUrl'>REDCap Project's Home Page</a></p>";
} else {
    echo makeHeader($module, "Install Flight Connector");
    echo "<p class='centered smaller'><a href='$baseUrl"."manager/project.php?pid=$pid'>Wrong Page? Disable this Module</a></p>";

    $readmeLink = $module->getUrl("README.md");
    echo "<p class='centered max-width'>Flight Connector is a networking tool to help groups of knowledge workers identify colleagues with areas of expertise, whether intellectual content or methodologies. This page will generate a survey for participants to fill out. The survey will contain checkboxes for each item for participants to identify their strengths. These strengths will, in turn, produce an interactive social network map for broader viewing. (<a href='$readmeLink' target='_blank'>View full ReadMe</a>.)</p>";
    echo "<form action='$thisUrl' method='POST'>";
    $csrfToken = $module->getCSRFToken();
    echo "<input type='hidden' id='redcap_csrf_token' name='redcap_csrf_token' value='$csrfToken' />";
    $requiredFields = [];
    foreach ($fields as $field => $label) {
        $labelField = $field."_label";
        echo "<h2>Options for $label</h2>";
        $defaultLabel = isset($_POST[$labelField]) ? htmlspecialchars($_POST[$labelField], ENT_QUOTES) : "";
        $defaultEntry = isset($_POST[$field]) ? htmlspecialchars($_POST[$field], ENT_QUOTES) : "";
        echo "<p class='centered max-width'><label for='$labelField'>Label:</label> <input type='text' id='$labelField' name='$labelField' placeholder='$label' value='$defaultLabel' style='width: 200px;' /><br/><label for='$field'>Checkbox Options (one per line):</label><br/><textarea name='$field' id='$field' style='width: 400px; height: 200px;'>$defaultEntry</textarea></p>";
        $requiredFields[] = $labelField;
        $requiredFields[] = $field;
    }
    $requiredFieldsJSON = json_encode($requiredFields);
    echo "<p class='centered'><input type='checkbox' id='requireLogin' name='requireLogin' /> <label for='requireLogin'>Check here to require a REDCap login before entering the survey.</label></p>";
    echo "<p class='centered'><button onclick='if (FlightConnector.isInvalid($requiredFieldsJSON)) { alert(\"You must fill in a value for all labels and options.\"); return false; } else { return true; } '>Set Up Project</button></p>";
    echo "</form>";
}


