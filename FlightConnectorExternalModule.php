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
    const VANDERBILT_LOCALHOST_PROJECT = 87;
    const EFS_RETREAT_2023_PROJECT = 172325;
    const REQUIRE_LOGIN_SETTING = "requireLoginForSurvey";
    const UNKNOWN_CHOICE = "Unknown";
    const CONTENT_AND_METHODS_LOCALHOST = 95;
    const CONTENT_AND_METHODS_VANDERBILT = 72516;

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
        if (self::isLocalhost() && ($project_id == self::CONTENT_AND_METHODS_LOCALHOST)) {
            return;
        }

        $vanderbiltSkipPids = [self::CONTENT_AND_METHODS_VANDERBILT];
        if (
            !$this->isSetup($project_id)
            && !self::isExternalModulePage()
            && self::isREDCap()
            && ($_GET['page'] != "setupUniqueHash")
            && (
                !in_array($project_id, $vanderbiltSkipPids)
                || !self::isVanderbilt()
            )
        ) {
            header("Location: " . $this->getUrl("install.php"));
        }
    }

    public static function isContentsAndMethods($project_id) {
        return (
            (
                self::isLocalhost()
                && ($project_id == FlightConnectorExternalModule::CONTENT_AND_METHODS_LOCALHOST)
            )
            || (
                self::isVanderbilt()
                && ($project_id == FlightConnectorExternalModule::CONTENT_AND_METHODS_VANDERBILT)
            )
        );
    }

    function redcap_every_page_top($project_id) {
        if (self::isREDCap() && !self::isContentsAndMethods($project_id)) {
            $mapUrl = $this->getMapURL();
            $nodeGraphUrl = $this->getNodeGraphURL();
            echo "<script>
$(document).ready(() => {
    setTimeout(() => {
        $('a[data-link-key=flight_connector-link_project_1]').attr('href', '$mapUrl');    
        $('a[data-link-key=flight_connector-link_project_2]').attr('href', '$nodeGraphUrl');    
    }, 30);
});
</script>";
        } else if (self::isREDCap() && self::isContentsAndMethods($project_id)) {
            echo "<script>
$(document).ready(() => {
    setTimeout(() => {
        $('a[data-link-key=flight_connector-link_project_1]').parent().hide();    
        $('a[data-link-key=flight_connector-link_project_2]').parent().hide();    
    }, 30);
});
</script>";
        }
    }

    public static function isVanderbilt() {
        return in_array(SERVER_NAME, ["redcap.vanderbilt.edu", "redcaptest.vanderbilt.edu"]);
    }

    public static function isLocalhost() {
        global $homepage_contact_email;
        return (SERVER_NAME == "localhost") && ($homepage_contact_email == "scott.j.pearson@vumc.org");
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
            echo json_encode($feedback);
        }
    }

    public function refreshMainFlightConnector($destPid) {
        $identifyingFields = $this->getFieldsForMainProject();
        return $this->refreshFlightConnector($destPid, $identifyingFields);
    }

    public function refreshEFSFlightConnector($destPid) {
        $identifyingFields = $this->getFieldsForEFSProject();
        return $this->refreshFlightConnector($destPid, $identifyingFields, FALSE);
    }

    public function refreshFlightConnector($destPid, $identifyingFields, $skipEFSMentors = TRUE, $onlyIncludeNames = []) {
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
                if ($skipEFSMentors && ($pid == self::EFS_RETREAT_2023_PROJECT) && in_array($row['newman_or_not'], [2, 3])) {
                    # skip mentors (2) and other (3)
                } else if (($pid == self::CONTENT_AND_METHODS_VANDERBILT) && empty($onlyIncludeNames)) {
                } else if ($row['is_archived'] == '1') {
                } else {
                    $translation = [];
                    foreach ($fieldMappings as $to => $from) {
                        $translation[$to] = $row[$from];
                    }
                    if ($translation["first_name"] && $translation['last_name']) {
                        $name = trim($translation['first_name'])." ".trim($translation['last_name']);
                        if (empty($onlyIncludeNames) || in_array($name, $onlyIncludeNames)) {
                            list($fn, $mn, $ln) = NameMatcher::splitName($name, 3);
                            $name = NameMatcher::formatName($fn, $mn, $ln);
                            $email = $translation['email'];
                            $uploadRow = [
                                "record_id" => $recordId,
                                "name" => $name,
                                "email" => trim($email),
                                "flight_connector_survey_complete" => "2",
                            ];
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
                            if (isset($destDataByName[$name])) {
                                if (in_array($name, $onlyIncludeNames)) {
                                    $destRecordId = $destDataByName[$name]["record_id"];
                                    $this->copyCheckboxes($destPid, $destRecordId, $uploadRow);
                                }
                                continue;
                            }
                            if (!in_array($name, $excludeList)) {
                                $uploadByName[$name] = $uploadRow;
                                $recordId++;
                            }
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

    private function copyCheckboxes($destPid, $destRecordId, $checkboxRow) {
        $uploadRow = [
            "record_id" => $destRecordId,
        ];
        $hasCheckboxes = FALSE;
        foreach ($checkboxRow as $field => $value) {
            if (preg_match("/___/", $field)) {
                $uploadRow[$field] = $value;
                $hasCheckboxes = TRUE;
            }
        }
        if ($hasCheckboxes) {
            $json = json_encode([$uploadRow]);
            $feedback = \REDCap::saveData($destPid, "json", $json, "overwrite");
        }
    }

    public function getFieldsForEFSProject() {
        return [
            self::EFS_RETREAT_2023_PROJECT => ["record_id" => "record_id", "first_name" => "firstname", "last_name" => "lastname", "email" => "email"],
        ];
    }

    public function getFieldsForMainProject() {
        return [
            15941 => ["record_id" => "participant_id", "first_name" => "name_first", "last_name" => "name_last", "email" => "email"],
            72575 => ["record_id" => "record_id", "first_name" => "first_name", "last_name" => "last_name", "email" => "email"],
            self::CONTENT_AND_METHODS_VANDERBILT => ["record_id" => "record_id", "first_name" => "first_name", "last_name" => "last_name", "email" => "email"],
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

    private function getNOAUTHPageURL($page) {
        $url= $this->getUrl($page);
        $pid = getPid();
        $uniqueHash = $this->getProjectSetting(self::UNIQUE_HASH_SETTING, $pid);
        if ($uniqueHash) {
            $url .= "&h=$uniqueHash";
        }
        if (!preg_match("/NOAUTH/", $url)) {
            $url .= "&NOAUTH";
        }
        return $url;
    }

    public function getMapURL() {
        return $this->getNOAUTHPageURL("map.php");
    }

    public function getNodeGraphURL() {
        return $this->getNOAUTHPageURL("nodeGraph.php");
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

    function redcap_survey_page($project_id, $record,$instrument,$event_id, $group_id, $survey_hash,$response_id, $repeat_instance) {
        if (self::isEFSRetreat($project_id)) {
            return;
        }
        if (version_compare(REDCAP_VERSION, '12.0.0', '<')) {
            $line = "$(ob).parent().find('label.fl').after(\"<div id='\"+row+\"-left' class='leftCol'></div><div id='\"+row+\"-right' class='rightCol'></div>\");";
        } else {
            $line = "$('#'+row).find('div[data-kind=field-value]').append(\"<div id='\"+row+\"-left' class='leftCol'></div><div id='\"+row+\"-right' class='rightCol'></div>\");";
        }
        $header = makeHeader($this, "Flight Connector", 650);
        if (self::isLocalhost() && ($project_id == self::CONTENT_AND_METHODS_LOCALHOST)) {
            $header = "";
        }
        $url = $this->getUrl("lookupMatches.php");
        if (!preg_match("/NOAUTH/", $url)) {
            $url .= "&NOAUTH";
        }
        $jqueryUI = $this->getUrl("jquery-ui.min.js");

        $fieldsJSON = json_encode(array_keys($this->getFields($project_id)));
        $metadataJSON = \REDCap::getDataDictionary($project_id, "json");
        $metadata = json_decode($metadataJSON, TRUE);
        $choicesJSON = json_encode(getChoices($metadata));
        $surveyTitleJS = "$('#surveytitle').replaceWith(\"<div id='surveytitle'>$header</div>\");";
        if (($project_id == self::CONTENT_AND_METHODS_VANDERBILT) && (self::isVanderbilt())) {
            $surveyTitleJS = "";
        }

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

        function searchForMatches(field, field2) {
            const item1 = $('[name='+field+']').val().trim();
            const item2 = (typeof field2 !== 'undefined') ? $('[name='+field2+']').val().trim() : '';
            const item = item1 + ' '+item2;
            let currName = '';
            if ($('[name=name]').length > 0) {
                currName = $('[name=name]').val();
            } else if (($('[name=first_name]').length > 0) && ($('[name=last_name]').length > 0)) {
                currName = $('[name=first_name]').val()+' '+$('[name=last_name]').val();
            }
            if (
                item.trim()
                && (
                    (prevName !== currName)
                    || (prevEmail !== $('[name=email]').val())
                )
            ) {
                prevName = currName;
                prevEmail = $('[name=email]').val();
                const url = '$url';
                const postData = {};
                if (field === 'first_name') {
                    postData['name'] = item.trim();
                } else {
                    postData[field] = item.trim();
                }
                postData['redcap_csrf_token'] = '$csrfToken';
                console.log(JSON.stringify(postData));
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
                const projectName = (match['pid'] === $project_id) ? 'Prior Entry in This Project' : match['projectName'];
                html += makeChoiceText(i, 'Project: '+projectName+'<br/>Name: '+match['name']+'<br/>Email: '+match['email']);
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
                const checkboxFields = $fieldsJSON;
                const surveyChoices = $choicesJSON;
                
                for (let i=0; i < checkboxFields.length; i++) {
                    const field = checkboxFields[i];
                    const regex = new RegExp('__chk__'+field);
                    $('input[type=hidden]').each((idx, ob) => {
                        const name = $(ob).attr('name');
                        if (name.match(regex) && $(ob).val()) {
                            $('#id-'+name).click();
                        }
                    });
                }
                
                for (let i=0; i < checkboxFields.length; i++) {
                    let field = checkboxFields[i];
                    let fieldDest = field;
                    if ((field === 'm') || (field === 'c')) {
                         // older projects at Vanderbilt just use the first lett
                        if (field === 'm') {
                            field = 'methods';
                        } else if (field === 'c') {
                            field = 'contents';
                        }
                        fieldDest = field.substring(0, 1);
                     }
                    const checks = match[field] ?? {};
                    for (const index in checks) {
                        if (match['pid'] === $project_id) {
                            const id = '__chk__'+fieldDest+'_RC_'+index;
                            $('#id-'+id).click();
                        } else {
                            // external project: check by choice label, not by index
                            const label = checks[index];
                            for (const surveyIndex in surveyChoices[fieldDest] ?? {}) {
                                const surveyLabel = surveyChoices[fieldDest][surveyIndex];
                                if (surveyLabel === label) {
                                    const id = '__chk__'+fieldDest+'_RC_'+surveyIndex;
                                    $('#id-'+id).click();
                                }
                            }
                        }
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
            $surveyTitleJS
            $('[name=name]').on('blur', () => { searchForMatches('name'); });
            $('[name=first_name]').on('blur', () => { searchForMatches('first_name', 'last_name'); });
            $('[name=last_name]').on('blur', () => { searchForMatches('first_name', 'last_name'); });
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

    public static function getMeharryList() {
        if (self::isVanderbilt()) {
            return [
                "Jamaine Davis",
                "Jennifer Erves",
                "Leslie \"Lauren\" Brown",
                "Maureen Sanderson",
                "Muthukumar Balasubramaniam",
                "Pandu Gangula",
                "Stephania Miller-Hughes",
            ];
        } else {
            return [];
        }
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
                "Aaron Kipp",
                "Adrienne Babcock",
                "Adrienne Roman",
                "Akiko Shimamoto",
                "Akshitkumar Mistry",
                "Alexandra Sundermann",
                "Alice Hoyt",
                "Alicia Beeghly-fadiel",
                "Alicia Beeghly-Fadiel",
                "Alicia Morgans",
                "Amalia  Peterson",
                "Amanda S Mixon",
                "Amanda Salanitro",
                "Ana Marin Dias Carneiro",
                "andre lagrange",
                "Andrea Page-mccaw",
                "Andrew Bryant",
                "Andrew Wiese",
                "Aniruddha Gokhale",
                "Anna  Patrick",
                "anna pfalzer",
                "Anna Vilgelm",
                "Annette Kim",
                "Aron Parekh",
                "Bernard Rousseau",
                "Bindong Liu",
                "Bing Zhang",
                "Brian Engelhardt",
                "Brittney   Snyder",
                "C M Stein",
                "C. Wester",
                "Cabrini Smith",
                "Candace Mcnaughton",
                "Carla Ramos",
                "Carlos  Grijalva",
                "Cassandra Oliver",
                "Catheryne Clouse",
                "Cecilia  Chung",
                "Chandra Osborn",
                "Chandra Thota",
                "Chandrasekhar Thota",
                "Charles R Flynn",
                "Chevis Shannon",
                "Christian Rosas Salazar",
                "Christian Rosassalazar",
                "Christopher Johnson",
                "Christopher Wells",
                "Colin George Walsh",
                "Cyndi Clark",
                "D. Troy Moon",
                "Daniel Moore",
                "Daniel Perrien",
                "Danielle Dean",
                "David Tabb",
                "David Vaught",
                "Dawood Darbar",
                "Derek J. Williams",
                "Devika  Nair",
                "Dineo Khabele",
                "Dolly Padovani-claudio",
                "Dorin-Bogdan Borza",
                "Dwayne Dove",
                "Eduard E Vasilevskis",
                "Edward Welch",
                "Eleonora Savio Galimberti",
                "Elizabeth Gulleen",
                "Elizabeth Hibler",
                "Emily Castellanos",
                "Emma Larkin",
                "Eric Liu",
                "Erica  Carrier",
                "Erick Spears",
                "Erika Dean",
                "Erika Leslie",
                "Eva Miller",
                "Florent Elefteriou",
                "GUANHUA CHEN",
                "Guanhua Chen",
                "Hana Itani",
                "Helen Bird",
                "Hilary Nickols",
                "Hind Lal",
                "Igor Feoktistov",
                "Ingrid Anderson",
                "Irene Lazarus",
                "Isaac P Thomsen",
                "J Mocco",
                "Jack Virostko",
                "Jackie Hellwege",
                "jacob houghton",
                "Jacob Houghton",
                "Jaime Libes",
                "James Davenport",
                "James E Cassat",
                "Jamie Felton",
                "Jamie Weyandt",
                "Jason A Macgurn",
                "Jason Becker",
                "Jason Macgurn",
                "Jason Winnick",
                "Jennifer Blackford",
                "Jennifer Cunningham",
                "Jennifer Cunningham-Erves",
                "Jennifer Giltnane",
                "Jeremy Lyle Warner",
                "Jeremy Warner",
                "Jessica Devin",
                "Jie Deng",
                "Jie Xu",
                "Joann Romano-Keeler",
                "Joann Romano-keeler",
                "John Alexander Oates",
                "John McLean",
                "John Virostko",
                "Jordan Everson",
                "Joseph Breeyear",
                "Joseph Conrad",
                "Joseph Luckett",
                "Joseph Schlesinger",
                "Joshua Charles Denny",
                "Joshua Fessel",
                "Joshua Gamse",
                "Josiah Ochieng",
                "Jozee Schnitker",
                "Juan Qin",
                "Juan Zhao",
                "Julia K. Bohannon",
                "Julia Sheffield",
                "Julie Pendergast",
                "Julie Taylor",
                "Julio E Ayala",
                "Jun Dai",
                "Katelyn Neely Rossow",
                "Katelyn Rossow",
                "Katherine Gotham",
                "Katherine Watson",
                "Katie Osborn",
                "Kedir  Turi",
                "Kedir Turi",
                "Keisuke Fukuda",
                "Kevin Motz",
                "Khaled A Abdel-Kader",
                "Klarissa Jackson",
                "Kristin Jernigan",
                "Kristina Martin",
                "Krystle Kuhs",
                "Kyndra Cleveland",
                "Kyra Richter",
                "Lang Wu",
                "Laura Mcgirt",
                "Laura Zahn",
                "Lauren  Peetluk",
                "Lauren Beach",
                "Lauren Palmer",
                "leigh Howard",
                "LEIGH Howard",
                "Leo Cheng",
                "Leonard A Bradshaw",
                "Leonard Bickman",
                "Leslie Gewin",
                "Lindsay Oneal",
                "Lindsay S. Mayberry",
                "Lindsey Mckernan",
                "Lori  Jordan",
                "Maame Yiadom",
                "Magdalena Grabowska",
                "Manish Tripathi",
                "Mark Clay",
                "Mark Decaestecker",
                "Mark Pilkinton",
                "Martin J Gallagher",
                "marvin kronenberg",
                "Mary Lauren Neel",
                "Matthew Blum",
                "Matthew Rioth",
                "Matthew Vogt",
                "Meher Juttukonda",
                "Meira Epplein",
                "Mellissa Nixon",
                "Michael Finn",
                "michael golinko",
                "Michael Richards",
                "Mingfeng Bai",
                "Minu Chaudhuri",
                "Nathalie Maitre",
                "Nathan Bingham",
                "Nathan Brummel",
                "Nechuta Sarah",
                "Nicholas Ferrell",
                "Nicholas Reiter",
                "Pamela Hull",
                "Pampee Young",
                "Patrick Grohar",
                "Patrick J Hu",
                "Paul Cobb",
                "Peggi Angel",
                "Peggy Kendall",
                "Pelayo Correa",
                "Peter Cummings",
                "Philip Budge",
                "Philip Owens",
                "Pietro Valdastri",
                "POPPY SCHOENBERG",
                "Pranoti Pradhan",
                "Qiping Feng",
                "Quinn  Wells",
                "Quinn Stanton Wells",
                "R Nathan Daniels",
                "Rachael Wolters",
                "Rachael Wolters",
                "Raymond Pasek",
                "Reyna Leigh Gordon",
                "Reza Dastvan",
                "Richard Darrell Dortch",
                "Richard G Abramson",
                "Richard Gumina",
                "Robert Beauchamp",
                "Robert Cronin",
                "Ross Davis",
                "Ryan Allen",
                "Ryan Doster",
                "Ryan Stevenson",
                "Samuel Gannon",
                "Sarah Nechuta",
                "Sarah Tahiri",
                "Saudat Fadeyi",
                "sayeh nikpay",
                "Scott A Smith",
                "Scott Pearson",
                "Sergey Ryzhov",
                "Serk Park",
                "Shajila  Siricilla",
                "Sheryl Rimrodt",
                "Sinju Sundaresan",
                "Sophia Yu",
                "Stephanie Devane",
                "Stephanie Sohl",
                "Stephen Clark",
                "Stephen Yeargin",
                "Steven Brunwasser",
                "Steven Goudy",
                "Sudipa Sarkar",
                "Sunil Halder",
                "Susan Spear Dyer",
                "Swati Rane",
                "Tara Helmer",
                "Test Test",
                "The-Quyen Nguyen",
                "Theodore Towse",
                "Thomas Austin",
                "Thomas Wang",
                "Tilicia Mayo-Gamble",
                "Timothy Girard",
                "Tultul Nayyar",
                "Valerie Jansen",
                "Vance Albaugh",
                "Wendy Bottinor",
                "wenliang song",
                "William Heerman",
                "William Reynolds",
                "Won Jae Huh",
                "Yi Jiang",
                "Young-Jae Nam",
                "Youngmin  Lee",
                "Yu-Ping Yang",
                "Zhengzheng Tang",
            ];
        } else {
            return [];
        }
    }
}
