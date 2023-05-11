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
