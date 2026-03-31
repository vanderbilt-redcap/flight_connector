<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/FlightConnectorExternalModule.php");

$destPid = 176037;
$module->refreshEFSFlightConnector($destPid);
echo "Done.";
