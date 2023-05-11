<?php

namespace Vanderbilt\FlightConnectorExternalModule;

function getEventIdForClassical($module, $pid) {
    $sql = "SELECT DISTINCT(m.event_id) AS event_id FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) WHERE a.project_id = ?";
    $q = $module->query($sql, [$pid]);
    if ($row = $q->fetch_assoc()) {
        return $row['event_id'];
    }
    throw new \Exception("The event_id is not defined. (This should never happen.)");
}

function makeHeader($module) {
    $efsLogo = $module->getUrl("efs_small_logoonly.png");
    $ftLogo = $module->getUrl("flight_tracker_icon.png");
    // TODO check with team which link to go to - remember that outsiders might use the Vanderbilt server
    $efsLink = isVanderbilt() ? "https://edgeforscholars.vumc.org" : "https://edgeforscholars.org";

    return "<p class='centered' style='margin-top: 2px;'>A Networking Tool from <a href='$efsLink' target='_blank'>Edge for Scholars</a> <img src='$efsLogo' style='width: 27px; height: 20px;' alt='Edge for Scholars' /> &amp; <a href='https://redcap.link/flight_tracker' target='_blank'>Flight Tracker</a> <img src='$ftLogo' style='width: 22px; height: 22px;' alt='Flight Tracker for Scholars' /><br/><span class='smallest'>Copyright &#9400 ".date("Y")." <a href='https://vumc.org' target='_blank'>Vanderbilt University Medical Center</a></span></p>";
}

function isVanderbilt() {
    return preg_match("/vanderbilt.edu/", SERVER_NAME) || preg_match("/localhost/", SERVER_NAME);
}

function getData($pid) {
    if (version_compare(REDCAP_VERSION, "12.5.2", ">=")) {
        return \REDCap::getData($pid, "json-array");
    } else {
        $json = \REDCap::getData($pid, "json");
        return json_decode($json, TRUE);
    }
}

function getPid() {
    $pid = (int) htmlentities($_GET['pid']);
    if (!$pid) {
        die("Error");
    }
    return $pid;
}

function makeJS($module) {
    $jsUrl = $module->getUrl("jquery.min.js");
    $html = "<script src='$jsUrl'></script>";
    $html .= "<script>
        function copyToClipboard(element) {
            const text = $(element).text() ? $(element).text() : $(element).val();
            navigator.clipboard.writeText(text);
        }
        
        function isInvalid(requiredFields) {
            for (let i=0; i < requiredFields.length; i++) {
                const field = requiredFields[i];
                if ($('#'+field).val() === '') {
                    return true;
                }
            }
            return false;
        }
        </script>";
    return $html;
}

function makePublicSurveyLink($module, $publicSurveyLink = "") {
    $pid = getPid();
    if (!$publicSurveyLink && $pid) {
        $publicSurveyLink = $module->getPublicSurveyUrl($pid) ?? "";
    }
    if ($publicSurveyLink) {
        return "<p class='centered max-width'>Share this public link with others to populate your data.<br/><input id='surveyUrl' value='$publicSurveyLink' onclick='this.select();' readonly='readonly' style='width: 90%; max-width: 450px; margin-right: 5px; margin-left: 5px;' /><span class='smaller'><a href='javascript:;' onclick='copyToClipboard($(\"#surveyUrl\"));'>Copy</a></span></p>";
    } else {
        return "";
    }
}