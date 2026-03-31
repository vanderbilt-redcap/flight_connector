<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/ldapFunction.php");

$pid = htmlentities($_GET['pid'] ?? "");
if (!$pid) {
    die("No project");
}

$result = getLDAP($pid);
if (isset($result["error"])) {
    echo $result["error"];
} else {
    echo json_encode($result);
}
