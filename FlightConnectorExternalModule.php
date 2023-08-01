<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/base.php");
require_once(__DIR__."/ldapFunction.php");

use \ExternalModules\ExternalModules;
use \ExternalModules\AbstractExternalModule;
use http\Params;

class FlightConnectorExternalModule extends AbstractExternalModule
{
    const INSTRUMENT = "flight_connector_survey";
    const UNIQUE_HASH_SETTING = "unique_hash";
    const VANDERBILT_MAIN_PROJECT = 172928;
    const EFS_RETREAT_2023_PROJECT = 172325;

    public function getFields($pid) {
        $option1 = ["contents" => "Concepts or Content Areas", "methods" => "Methods or Tools"];
        $option2 = ["c" => "Content Areas", "m" => "Methods"];

        foreach([$option1, $option2] as $option) {
            $fields = array_keys($option);
            $sql = "SELECT field_name FROM redcap_metadata WHERE project_id = ? AND field_name IN (?, ?)";
            $q = $this->query($sql, [$pid, $fields[0], $fields[1]]);
            if ($q->fetch_assoc()) {
                return $option;
            }
        }
        return $option1;
    }

    static function isEFSRetreat($pid) {
        return ($pid == self::EFS_RETREAT_2023_PROJECT) && self::isVanderbilt();
    }

    function isSetup($project_id) {
        if (self::isEFSRetreat($project_id)) {
            return TRUE;
        }
        $fields = array_keys(self::getFields($project_id));
        $sql = "SELECT field_name FROM redcap_metadata WHERE project_id = ? AND form_name = ? AND field_name IN (?, ?)";
        $q = $this->query($sql, [$project_id, "flight_connector_survey", $fields[0], $fields[1]]);
        return $q->fetch_assoc();
    }

    function redcap_every_page_before_render($project_id)
    {
        if (!$this->isSetup($project_id) && !self::isExternalModulePage() && self::isREDCap() && ($_GET['page'] != "setupUniqueHash")) {
            header("Location: " . $this->getUrl("install.php"));
        }
    }
    function redcap_every_page_top($project_id) {
        if (self::isREDCap()) {
            $mapUrl = $this->getMapURL($this);
            echo "<script>
$(document).ready(() => {
    setTimeout(() => {
        $('a[data-link-key=flight_connector-link_project_1]').attr('href', '$mapUrl');    
    }, 30);
});
</script>";
        }
    }

    private static function isVanderbilt() {
        return in_array(SERVER_NAME, ["redcap.vanderbilt.edu", "redcaptest.vanderbilt.edu"]);
    }

    public function cron() {
        if (self::isVanderbilt()) {
            $allPids = $this->framework->getProjectsWithModuleEnabled();
            foreach ($allPids as $pid) {
                if ($this->isActiveProject($pid)) {
                    getLDAP($pid);
                }
            }
            $feedback = $this->refreshMainFlightConnector(self::VANDERBILT_MAIN_PROJECT);
            error_log(json_encode($feedback));
        }
    }

    public function refreshMainFlightConnector($destPid) {
        $identifyingFields = $this->getFieldsForMainProject();
        $checkboxFields = ["c" => "contents", "m" => "methods"];

        $json = \REDCap::getDataDictionary($destPid, "json");
        $metadata = json_decode($json, TRUE);
        $choices = getChoices($metadata);
        $destData = \REDCap::getData($destPid, "json-array");
        $maxRecordId = 0;
        $destDataByName = [];
        foreach ($destData as $row) {
            $name = $row['name'];
            if ($row['record_id'] > $maxRecordId) {
                $maxRecordId = $row['record_id'];
            }
            if (!isset($row['in_ldap']) || ($row['in_ldap'] == "1")) {
                $destDataByName[$name] = $row;
            }
        }
        $recordId = $maxRecordId + 1;
        $excludeList = self::getExcludeList();

        $uploadByName = [];
        foreach ($identifyingFields as $pid => $fieldMappings) {
            $json = \REDCap::getDataDictionary($pid, "json");
            $pidMetadata = json_decode($json, TRUE);
            $pidChoices = getChoices($pidMetadata);
            $redcapData = \REDCap::getData($pid, "json-array");
            foreach ($redcapData as $row) {
                if (($pid == self::EFS_RETREAT_2023_PROJECT) && in_array($row['newman_or_not'], [2, 3])) {
                    # skip mentors (2) and other (3)
                } else if ($pid == 72516) {
                } else if ($row['is_archived'] == '1') {
                } else {
                    $translation = [];
                    foreach ($fieldMappings as $to => $from) {
                        $translation[$to] = $row[$from];
                    }
                    if ($translation["first_name"] && $translation['last_name']) {
                        $name = $translation['first_name']." ".$translation['last_name'];
                        if (isset($destDataByName[$name])) {
                            continue;
                        }
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
                                    }
                                }
                            }
                        }
                        if (!in_array($name, $excludeList)) {
                            $uploadByName[$name] = $uploadRow;
                        }
                    }
                }
            }
        }

        if (!empty($uploadByName)) {
            $json = json_encode(array_values($uploadByName));
            return \REDCap::saveData($destPid, "json", $json, "overwrite");
        } else {
            return [];
        }
    }

    public function getFieldsForMainProject() {
        return [
            15941 => ["record_id" => "participant_id", "first_name" => "name_first", "last_name" => "name_last", "email" => "email"],
            72575 => ["record_id" => "record_id", "first_name" => "first_name", "last_name" => "last_name", "email" => "email"],
            72516 => ["record_id" => "record_id", "first_name" => "first_name", "last_name" => "last_name", "email" => "email"],
            self::EFS_RETREAT_2023_PROJECT => ["record_id" => "record_id", "first_name" => "firstname", "last_name" => "lastname", "email" => "email"],
        ];
    }

    private function isActiveProject($pid) {
        if (!$pid) {
            return FALSE;
        }
        $sql = "SELECT date_deleted, completed_time FROM redcap_projects WHERE project_id = ? LIMIT 1";
        $q = $this->query($sql, [$pid]);
        if ($row = $q->fetch_assoc()) {
            if (!$row['date_deleted'] && !$row['completed_time']) {
                return TRUE;
            }
        }
        return FALSE;
    }



    public function getMapURL() {
        $mapUrl = $this->getUrl("map.php");
        $pid = getPid();
        $uniqueHash = $this->getProjectSetting(self::UNIQUE_HASH_SETTING, $pid);
        if ($uniqueHash) {
            $mapUrl .= "&h=$uniqueHash";
        }
        if (!preg_match("/NOAUTH/", $mapUrl)) {
            $mapUrl .= "&NOAUTH";
        }
        return $mapUrl;
    }

    private static function isREDCap() {
        $rootPage = $_SERVER['PHP_SELF'] ?? "";
        if (strpos($rootPage, "ExternalModules") !== FALSE) {
            return FALSE;
        }
        if (strpos($rootPage, APP_PATH_WEBROOT) === FALSE) {
            return FALSE;
        }
        return TRUE;
    }

    private static function isExternalModulePage() {
        $page = $_SERVER['PHP_SELF'] ?? "";
        if (preg_match("/ExternalModules\/manager\/project.php/", $page)) {
            return TRUE;
        }
        if (preg_match("/ExternalModules\/manager\/ajax\//", $page)) {
            return TRUE;
        }
        if (preg_match("/external_modules\/manager\/project.php/", $page)) {
            return TRUE;
        }
        if (preg_match("/external_modules\/manager\/ajax\//", $page)) {
            return TRUE;
        }
        return FALSE;
    }

    function hook_survey_page($project_id, $record,$instrument,$event_id, $group_id, $survey_hash,$response_id, $repeat_instance) {
        if (self::isEFSRetreat($project_id)) {
            return;
        }
        if (version_compare(REDCAP_VERSION, '12.0.0', '<')) {
            $line = "$(ob).parent().find('label.fl').after(\"<div id='\"+row+\"-left' class='leftCol'></div><div id='\"+row+\"-right' class='rightCol'></div>\");";
        } else {
            $line = "$('#'+row).find('div[data-kind=field-value]').append(\"<div id='\"+row+\"-left' class='leftCol'></div><div id='\"+row+\"-right' class='rightCol'></div>\");";
        }
        $header = makeHeader($this, "Flight Connector", 650);
        $url = $this->getUrl("lookupMatches.php");
        if (!preg_match("/NOAUTH/", $url)) {
            $url .= "&NOAUTH";
        }
        $jqueryUI = $this->getUrl("jquery-ui.min.js");

        $fieldsJSON = json_encode(array_keys($this->getFields($project_id)));

        $csrfToken = $this->getCSRFToken();
        echo "
<script src='$jqueryUI'></script>
<div id='dialog' title='Matches from Prior Entries'>
    <div id='choices'></div>
</div>
<script>
        let dialog;
        let matches = [];
        let prevName = '';
        let prevEmail = '';

        function searchForMatches(field) {
            const item = $('[name='+field+']').val().trim();
            if (
                item.trim()
                && (
                    (prevName !== $('[name=name]').val())
                    || (prevEmail !== $('[name=email]').val())
                )
            ) {
                prevName = $('[name=name]').val();
                prevEmail = $('[name=email]').val();
                const url = '$url';
                const postData = {};
                postData[field] = item;
                postData['redcap_csrf_token'] = '$csrfToken';
                $.post(url, postData, (json) => {
                    console.log(json);
                    const m = JSON.parse(json);
                    if (m.length > 0) {
                        displayMatches(m);
                    }                    
                });
            }
        }
        
        function displayMatches(m) {
            matches = m;

            let html = '<p>Do you want to use any of these prior entries as a starting point?</p>';
            for (let i=0; i < matches.length; i++) {
                const match = matches[i];
                html += makeChoiceText(i, 'Name: '+match['name']+'<br/>Email: '+match['email']);
            }
            html += makeChoiceText(-1, 'I do not want to pre-fill any additional fields.');

            $('#choices').html(html);
            dialog.dialog('open');
        }
        
        function makeChoiceText(i, text) {
            return '<div style=\"text-align: center; margin: 8px 0; padding: 4px; border: 1px solid #888; background-color: #eeeeee; cursor: pointer;\" onclick=\"chooseName('+i+');\">'+text+'</div>';
        }

        function twoColumnCheckboxes() {
            const checkboxes = {};
            $('tr td div.choicevert').each(function(index, ob) {
        	    const row = $(ob).closest('tr').attr('id');
        	    if (typeof checkboxes[row] == 'undefined') {
        		    checkboxes[row] = 0;
        	    }
        	    checkboxes[row]++;
            });
            for (let row in checkboxes) {
        	    let done = false;
        	    if (checkboxes[row] > 10) {
                    const checksInCol = Math.ceil(checkboxes[row] / 2);
                    $('#'+row+' td div.choicevert').each(function(index, ob) {
                        if (!done) {
                            $line
                        }
                        done = true;
                    });
                    $('#'+row+' td div.choicevert').each(function(index, ob) {
                        if (index < checksInCol) {
                            $(ob).appendTo('#'+row+'-left');
                        } else {
                            $(ob).appendTo('#'+row+'-right');
                        }
                    });
                }
            }
        }
        
        function chooseName(i) {
            if ((i >= 0) && (i < matches.length)) {
                const match = matches[i];
                const fields = $fieldsJSON;
                
                for (let i=0; i < fields.length; i++) {
                    const field = fields[i];
                    const regex = new RegExp('__chk__'+field);
                    $('input[type=hidden]').each((idx, ob) => {
                        const name = $(ob).attr('name');
                        if (name.match(regex) && $(ob).val()) {
                            $('#id-'+name).click();
                        }
                    });
                }
                
                for (let i=0; i < fields.length; i++) {
                    const field = fields[i];
                    const checks = match[field] ?? [];
                    for (let j=0; j < checks.length; j++) {
                        const id = '__chk__'+field+'_RC_'+checks[j];
                        $('#id-'+id).click();
                    }
                }

                prevName = match['name'];
                prevEmail = match['email'];
                $('[name=name]').val(match['name']);
                $('[name=email]').val(match['email']);
            }
            dialog.dialog('close');
         }
        
        $(document).ready(function() {
            twoColumnCheckboxes();
            $('#surveytitle').replaceWith(\"<div id='surveytitle'>$header</div>\");
            $('[name=name]').on('blur', () => { searchForMatches('name'); });
            $('[name=email]').on('blur', () => { searchForMatches('email'); });
            
            dialog = $('#dialog').dialog({
                autoOpen: false,
                height: 400,
                width: 400,
                modal: true
            });
        });
</script>
<style>
    div.leftCol { width: 50%; float: left; }
    div.rightCol { width: 50%; float: right; }
</style>";
    }

    public static function getExcludeList() {
        if (self::isVanderbilt()) {
            return [
                "Amy Arnold",
                "David Aronoff",
                "Shari Barkin",
                "Martin Blakely",
                "Jordan Bley",
                "Nancy Brown",
                "Maciej Buchowski",
                "James Carlucci",
                "Roger Colbran",
                "Rebecca Cook",
                "Sierra Cullati",
                "Bruce Damon",
                "Wonder Drake",
                "Amy Engevik",
                "Michelle Failla",
                "Dan Foster",
                "Daniel Foster",
                "Leslie S Gewin",
                "Rocco Gogliotti",
                "Jose Gomez",
                "Marie Griffin",
                "Daniel Gustavson",
                "Kenneth Hande",
                "Pamela Carmen Hull",
                "Kevin Johnson",
                "Sahar Kohanim",
                "Christine Konradi",
                "Munir Gunes Kutlu",
                "Jonathan Lehman",
                "Christopher Lindsell",
                "Christine Lovly",
                "Susan Majka",
                "Liz Mallott",
                "Pierre Massion",
                "Eva Mistry",
                "Todd Monroe",
                "Sayeh Nikpay",
                "Sachin Patel",
                "Naeem Patil",
                "Lauren Peetluk",
                "Daniel S. Perrien",
                "Sabrina Poon",
                "Danielle Rankin",
                "Inga Saknite",
                "Rebecca M Sappington",
                "Sarika Saraswati",
                "Max Schiff",
                "Michael Schulte",
                "Shaun R Stauffer",
                "Maame Yaa Yiadom",
                "Maame Yaa \"Maya\" Yiadom",
                "Lisa Young",
                "Andries Zijlstra",
                "Terrah Foster Akard",
                "Matt Alexander",
                "Frederic Billings",
                "Alison (Ali) Carroll",
                "Cecilia Pilar Chung",
                "James Richard Goldenring",
                "Jake Hughey",
                "Michael Hutson",
                "Vern Kerchberger",
                "Meenakshi Swaminathan Madhur",
                "Danyvid Olivares-Villagómez",
                "Ray Peebles",
                "Rena Robinson",
                "Samuel Rosenbloom",
                "Jeffrey Schmeckpeper",
                "Carlos Serezani",
                "Cyndya Adriana Shibao",
                "William-May Stubblefield",
                "William Stubblefield",
                "Helen Talbot",
                "Juan Arroyo Ornelas",
                "Wendy Rathmell",
                "Donald Arnold",
            ];
        } else {
            return [];
        }
    }
}
