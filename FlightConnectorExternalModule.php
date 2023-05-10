<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;
use \ExternalModules\AbstractExternalModule;

class FlightConnectorExternalModule extends AbstractExternalModule
{

    function hook_every_page_top($project_id)
    {
        $sql = "SELECT field_name FROM redcap_metadata WHERE project_id = ? AND form_name = ? AND field_name IN (?, ?)";
        $q = $this->query($sql, [$project_id, "flight_connector_survey", "subjects", "skills"]);

        if (($q->num_rows() === 0) && !self::isExternalModulePage()) {
            header("Location: ".$this->getUrl("install.php"));
        }
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

    function hook_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $applicableInstruments = "flight_connector_survey";
        $fields = ["contents", "methods"];
        # two column checkboxes
    }
}
