<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__ . "/FlightConnectorExternalModule.php");
require_once(__DIR__ . "/base.php");

$pid = htmlentities($_GET['project_id']);
$publicSurveyLink = $module->getPublicSurveyUrl($pid) ?? "";
if ($publicSurveyLink) {
    # surveys cannot be added using require() => this is as secure as we're going to get
    header("Location: ".$publicSurveyLink);
} else {
    echo "Invalid Survey.";
}
