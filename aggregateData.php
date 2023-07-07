<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/base.php");
require_once(__DIR__."/ldapFunction.php");

$feedback = $module->refreshMainFlightConnector(FlightConnectorExternalModule::VANDERBILT_MAIN_PROJECT);

echo json_encode($feedback)."<br/>";

getLDAP($destPid);