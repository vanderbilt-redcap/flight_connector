<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/LDAP.php");
require_once(__DIR__."/NameMatcher.php");

$pid = htmlentities($_GET['pid'] ?? "");
if (!$pid) {
    die("No project");
}

$json = \REDCap::getDataDictionary($pid, "json");
$metadata = json_decode($json, TRUE);
$ldapField = "in_ldap";
$hasLDAPField = FALSE;
foreach ($metadata as $row) {
    if ($row['field_name'] == $ldapField) {
        $hasLDAPField = TRUE;
        break;
    }
}

if (!$hasLDAPField) {
    echo "Does not have LDAP field!";
    exit;
}

$redcapData = \REDCap::getData($pid, "json-array");
$upload = [];
foreach ($redcapData as $row) {
    $recordId = $row['record_id'];
    $name = $row['name'];
    $email = $row['email'];
    $vuEmail = preg_replace("/@vumc\.org$/i", "@vanderbilt.edu", $email);
    $vumcEmail = preg_replace("/@vanderbilt\.edu$/i", "@vumc.org", $email);
    list($first, $last) = NameMatcher::splitName($name, 2);
    $first = preg_replace("/\s+\(\w+\)/", "", $first);    // parentheses throw off LDAP
    $priorInLDAP = $row[$ldapField];
    if ($name && LDAP::getEmailFromName($first, $last)) {
        $newInLDAP = 1;
    } else if (
        $email
        && (
            LDAP::getNameFromEmail($vuEmail)
            || LDAP::getNameFromEmail($vumcEmail)
        )
    ) {
        $newInLDAP = 1;
    } else {
        $newInLDAP = 0;
    }
    if ($priorInLDAP !== $newInLDAP) {
        $upload[] = [
            "record_id" => $recordId,
            $ldapField => "$newInLDAP",
        ];
    }
}

echo count($upload)." rows to upload!<br/>";
if (!empty($upload)) {
    $json = json_encode($upload);
    $feedback = \REDCap::saveData($pid, "json", $json, 'overwrite');
    echo json_encode($feedback);
}