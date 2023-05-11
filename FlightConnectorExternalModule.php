<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;
use \ExternalModules\AbstractExternalModule;

class FlightConnectorExternalModule extends AbstractExternalModule
{
    const INSTRUMENT = "flight_connector_survey";
    const FIELDS = ["contents" => "Concepts or Content Areas", "methods" => "Methods or Tools"];

    function isSetup($project_id) {
        $fields = array_keys(self::FIELDS);
        $sql = "SELECT field_name FROM redcap_metadata WHERE project_id = ? AND form_name = ? AND field_name IN (?, ?)";
        $q = $this->query($sql, [$project_id, "flight_connector_survey", $fields[0], $fields[1]]);
        return $q->fetch_assoc();
    }

    function redcap_every_page_before_render($project_id)
    {
        if (!$this->isSetup($project_id) && !self::isExternalModulePage() && self::isREDCap()) {
            header("Location: ".$this->getUrl("install.php"));
        }
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
        if (version_compare(REDCAP_VERSION, '12.0.0', '<')) {
            $line = "$(ob).parent().find('label.fl').after(\"<div id='\"+row+\"-left' class='leftCol'></div><div id='\"+row+\"-right' class='rightCol'></div>\");";
        } else {
            $line = "$('#'+row).find('div[data-kind=field-value]').append(\"<div id='\"+row+\"-left' class='leftCol'></div><div id='\"+row+\"-right' class='rightCol'></div>\");";
        }

        echo "
<script>
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
        $(document).ready(function() {
            twoColumnCheckboxes();
        });
</script>
<style>
    div.leftCol { width: 50%; float: left; }
    div.rightCol { width: 50%; float: right; }
</style>";
    }
}
