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

function makeHeader($module, $title, $width = 800) {
    $efsLogo = $module->getUrl("efs_small_logoonly.png");
    $ftLogo = $module->getUrl("flight_tracker_icon.png");
    // TODO check with team which link to go to - remember that outsiders might use the Vanderbilt server
    $efsLink = isVanderbilt() ? "https://edgeforscholars.vumc.org" : "https://edgeforscholars.org";

    $html = "<style>.ftRainbow { padding-bottom: 4px; padding-top: 4px; background: linear-gradient(0deg, white 0%, transparent 50%, white 100%), linear-gradient(90deg, white 0%, #d4d4eb 20%, #f69ca0 40%, #b0d87a 60%, #f6ba70 80%, white 100%); }</style>";

    $linkStyle = "color: inherit; font-size: inherit;";
    $headerInfo = "<p style='text-align: right; font-size: 15px; line-height: 1.0em;'>A Professional Networking Tool<br/>from <a style='$linkStyle' href='$efsLink' target='_blank'>Edge for Scholars</a> <img src='$efsLogo' style='width: 27px; height: 20px;' alt='Edge for Scholars' /><br/>and <a style='$linkStyle' href='https://redcap.link/flight_tracker' target='_blank'>Flight Tracker</a> <img src='$ftLogo' style='width: 20px; height: 20px;' alt='Flight Tracker for Scholars' /><br/><span style='font-size: 11px; line-height: 1.3em;'>Copyright &#9400 ".date("Y")."<br/><a style='$linkStyle' href='https://vumc.org' target='_blank'>Vanderbilt University Medical Center</a></span></p>";;
    $html .= "<table class='ftRainbow' style='margin-right: auto; margin-left: auto; width: $width"."px;'><tbody>";
    $html .= "<tr>";
    $html .= "<td style='width: 62%; vertical-align: middle;'><h1 style='text-align: left;'>$title</h1></td>";
    $html .= "<td style='width: 38%; vertical-align: middle;'>$headerInfo</td>";
    $html .= "</tr>";
    $html .= "</tbody></table>";

    return $html;
}

function isVanderbilt() {
    return preg_match("/vanderbilt.edu/", SERVER_NAME) || preg_match("/localhost/", SERVER_NAME);
}

function getData($pid) {
    if (version_compare(REDCAP_VERSION, "12.5.2", ">=")) {
        $data = \REDCap::getData($pid, "json-array");
    } else {
        $json = \REDCap::getData($pid, "json");
        $data = json_decode($json, TRUE);
    }

    $data = filterOutDuplicateNames($data);
    $data = filterOutViaLDAP($data);

    if (isset($_GET['rows']) && is_numeric($_GET['rows'])) {
        $newData = [];
        for ($i = 0; $i < $_GET['rows']; $i++) {
            $newData[] = $data[$i];
        }
        return $newData;
    }
    return $data;
}

function filterOutViaLDAP($data) {
    $newData = [];
    foreach ($data as $row) {
        if (!isset($row['in_ldap']) || ($row['in_ldap'] !== "0")) {
            $newData[] = $row;
        }
    }
    return $newData;
}

function filterOutDuplicateNames($data) {
    $newDataByName = [];
    foreach ($data as $row) {
        if ($row['name']) {
            $newDataByName[$row['name']] = $row;
        }
    }
    return array_values($newDataByName);
}

function getPid() {
    $pid = (int) htmlspecialchars($_GET['pid']);
    if (!$pid) {
        die("Error");
    }
    return $pid;
}

function makeJS($module) {
    $jsUrl = $module->getUrl("jquery.min.js");
    $baseLink = $module->getUrl("base.js");
    $html = "<script type='text/javascript' src='$jsUrl'></script><script type='text/javascript' src='$baseLink'></script>";
    return $html;
}

function makePublicSurveyLink($module, $publicSurveyLink = "") {
    $pid = getPid();
    if (!$publicSurveyLink && $pid) {
        $publicSurveyLink = $module->getPublicSurveyUrl($pid) ?? "";
    }
    if ($publicSurveyLink) {
        return "<p class='centered max-width' style='margin-top: 0; margin-bottom: 0;'>Share this public link with others to populate your data.<br/><input id='surveyUrl' value='$publicSurveyLink' onclick='this.select();' readonly='readonly' style='width: 90%; max-width: 450px; margin-right: 5px; margin-left: 5px;' /><span class='smaller'><a href='javascript:;' onclick='FlightConnector.copyToClipboard($(\"#surveyUrl\"));'>Copy</a></span></p>";
    } else {
        return "";
    }
}

function makeMapLink($module) {
    $mapUrl = $module->getMapURL();
    return "<p class='centered max-width'>Share this public link to see the Flight Tracker Map real-time as the data grows. <br/><input id='mapUrl' value='$mapUrl' onclick='this.select();' readonly='readonly' style='width: 90%; max-width: 450px; margin-right: 5px; margin-left: 5px;' /><span class='smaller'><a href='javascript:;' onclick='FlightConnector.copyToClipboard($(\"#mapUrl\"));'>Copy</a></span></p>";
}

function getChoices($metadata) {
    $choicesStrs = array();
    $multis = array("checkbox", "dropdown", "radio");
    foreach ($metadata as $row) {
        if (in_array($row['field_type'], $multis) && $row['select_choices_or_calculations']) {
            $choicesStrs[$row['field_name']] = $row['select_choices_or_calculations'];
        } else if ($row['field_type'] == "yesno") {
            $choicesStrs[$row['field_name']] = "0,No|1,Yes";
        } else if ($row['field_type'] == "truefalse") {
            $choicesStrs[$row['field_name']] = "0,False|1,True";
        }
    }
    $choices = array();
    foreach ($choicesStrs as $fieldName => $choicesStr) {
        $choices[$fieldName] = getRowChoices($choicesStr);
    }
    return $choices;
}

function getRowChoices($choicesStr, $directFromDB = FALSE) {
    if ($directFromDB) {
        $regex = "/\s*\\\\n\s*/";
    } else {
        $regex = "/\s*\|\s*/";
    }
    $choicePairs = preg_split($regex, $choicesStr);
    $choices = [];
    foreach ($choicePairs as $pair) {
        $a = preg_split("/\s*,\s*/", $pair);
        if (count($a) == 2) {
            $choices[$a[0]] = $a[1];
        } else if (count($a) > 2) {
            $a = explode(",", $pair);
            $b = [];
            for ($i = 1; $i < count($a); $i++) {
                $b[] = $a[$i];
            }
            $choices[trim($a[0])] = trim(implode(",", $b));
        }
    }
    return $choices;
}

function fetchFromServer($request, $server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $server);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request, '', '&'));
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}

function downloadChoicesForField($module, $pid, $field) {
    $sql = "SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ?";
    $q = $module->query($sql, [$pid, $field]);
    if ($row = $q->fetch_assoc()) {
        return getRowChoices($row['element_enum'], TRUE);
    }
    return [];
}

function makeD3Data($data, $choices) {
    $outData = [];
    $outData['scholars'] = [];
    $outData['types'] = [];
    $outData['perspectives'] = [];

    $counts = [];
    foreach ($choices as $field => $indices) {
        $counts[$field] = [];
        foreach ($indices as $index => $label) {
            $counts[$field][$index] = 0;
        }
    }

    foreach ($data as $row) {
        $name = $row['name'];
        $email = $row['email'];
        $checks = [];
        foreach ($row as $field => $value) {
            if (($value == "1") && preg_match("/___/", $field)) {
                list($field, $index) = explode("___", $field);
                if (isset($choices[$field])) {
                    $counts[$field][$index]++;
                    if (isset($choices[$field][$index])) {
                        $label = clearHTML($choices[$field][$index]);
                        $checks[] = $label;
                    } else {
                        $checks[] = $index;
                    }
                }
            }
        }
        $resetLink = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        $reset = "<a href='$resetLink'>View All</a>";

        $pubMedLink = "https://pubmed.ncbi.nlm.nih.gov/?term=".urlencode(strtolower($name));
        if ($name && !empty($checks)) {
            $outData['scholars'][] = [
                "type" => "scholar",
                "name" => $name,
                "description" => "<a href='mailto:$email'>contact $name by email</a> - <a href='$pubMedLink' target='_blank'>search PubMed for $name</a> - $reset",
                "links" => $checks,
            ];
        }
    }

    foreach ($choices['contents'] as $index => $label) {
        $label = clearHTML($label);
        if ($counts['contents'][$index] > 0) {
            $slug = $index;
            $outData['contents'][] = [
                "type" => "content",
                "name" => $label,
                "description" => $label." - ".$counts['contents'][$index]." people - click to explore - $reset",
                "slug" => $slug,
            ];
        }
    }
    foreach ($choices['methods'] as $index => $label) {
        $label = clearHTML($label);
        if ($counts['methods'][$index] > 0) {
            $outData['methods'][] = [
                "type" => "method",
                "name" => $label,
                "description" => $label." - ".$counts['methods'][$index]." people - click to explore - $reset",
                "count" => $counts['methods'][$index],
            ];
        }
    }

    return $outData;
}

function clearHTML($str) {
    $str = str_replace("&#039;", "'", $str);
    $str = str_replace("&#39;", "'", $str);
    $str = str_replace("&quot;", "\"", $str);
    $str = str_replace("&#034;", "\"", $str);
    $str = str_replace("&#34;", "\"", $str);
    $str = str_replace("&amp;", "&", $str);
    return $str;
}

function makeSaveDiv($module, $type, $mimeType = "image/png") {
    if ($mimeType == "image/png") {
        $canvasFunc = "canvas2PNG";
        $suffix = "png";
    } else if ($mimeType == "image/jpeg") {
        $canvasFunc = "canvas2JPEG";
        $suffix = "jpg";
    } else {
        throw new \Exception("Mime Type unsupported: $mimeType");
    }
    if ($type == "svg") {
        $europaWOFFLocation = $module->getUrl("EuropaNuovaRegular.woff");
        return "<div style='text-align: right;'><button class='smallest' onclick='const svg = $(this).parent().parent().find(\"svg\").prop(\"outerHTML\"); FlightConnector.svg2Image(svg, 0, \"#ffffff\", FlightConnector.$canvasFunc, \"europa\", \"$europaWOFFLocation\"); return false;'>Save Image</button></div>";
    } else if ($type == "canvas") {
        return "<div style='text-align: right;'><button class='smallest' onclick='const c = $(this).parent().parent().find(\"canvas\").get(0); const dataurl = FlightConnector.$canvasFunc(c); FlightConnector.forceDownloadUrl(dataurl, \"chart.$suffix\"); return false;'>Save Image</button></div>";
    } else {
        throw new \Exception("Invalid type: $type");
    }
}

function makeConjunction($list, $conjugation = "and") {
    if (count($list) == 0) {
        return "";
    } else if (count($list) == 1) {
        return $list[0];
    } else if (count($list) == 2) {
        return $list[0]." $conjugation ".$list[1];
    } else {
        $lastElem = $list[count($list) - 2].", $conjugation ".$list[count($list) - 1];
        $elems = [];
        for ($i = 0; $i < count($list) - 2; $i++) {
            $elems[] = $list[$i];
        }
        $elems[] = $lastElem;
        return implode(", ", $elems);
    }
}

