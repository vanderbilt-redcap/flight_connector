<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/base.php");
require_once(__DIR__."/ldapFunction.php");

use \ExternalModules\ExternalModules;
use \ExternalModules\AbstractExternalModule;

class FlightConnectorExternalModule extends AbstractExternalModule
{
    const INSTRUMENT = "flight_connector_survey";
    const UNIQUE_HASH_SETTING = "unique_hash";

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
        return ($pid == 172325) && self::isVanderbilt();
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
        }
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

    function hook_survey_page ($project_id, $record,$instrument,$event_id, $group_id, $survey_hash,$response_id, $repeat_instance) {
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
}
