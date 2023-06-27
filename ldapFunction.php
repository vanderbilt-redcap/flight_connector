<?php

namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__."/LDAP.php");
require_once(__DIR__."/NameMatcher.php");

function getLDAP($pid) {
    $json = \REDCap::getDataDictionary($pid, "json");
    $metadata = json_decode($json, TRUE);
    $ldapField = "in_ldap";
    $deptField = "department";
    $hasLDAPField = FALSE;
    $hasDepartmentField = FALSE;
    foreach ($metadata as $row) {
        if ($row['field_name'] == $ldapField) {
            $hasLDAPField = TRUE;
        }
        if ($row['field_name'] == $deptField) {
            $hasDepartmentField = TRUE;
        }
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
        $key = LDAP::getNameAssociations($first, $last);
        $nameInfo = LDAP::getLDAPByMultiple(array_keys($key), array_values($key));
        $vuInfo = LDAP::getLDAP("mail", $vuEmail);
        $vumcInfo = LDAP::getLDAP("mail", $vumcEmail);


        $dept = "";
        if ($name && ($nameInfo['count'] > 0)) {
            $employeeType = LDAP::findField($nameInfo, "employeetype", 0);
            if ($employeeType == "Non-Employee") {
                $newInLDAP = 0;
            } else {
                $newInLDAP = 1;
                $dept = LDAP::findField($nameInfo, "department", 0);
            }
        } else if (
            $email
            && ($vumcInfo['count'] > 0)
        ) {
            $newInLDAP = 1;
            $dept = LDAP::findField($vumcInfo, "department", 0);
        } else if ($email && ($vuInfo['count'] > 0)) {
            $newInLDAP = 1;
            $dept = LDAP::findField($vuInfo, "department", 0);
        } else {
            $newInLDAP = 0;
        }
        if ($dept == "Terminated_Organization") {
            $dept = "";
            $newInLDAP = 0;
        }
        $uploadRow = [
            "record_id" => $recordId,
        ];
        if ($hasLDAPField) {
            $uploadRow[$ldapField] = $newInLDAP;
        }
        if ($hasDepartmentField) {
            $uploadRow[$deptField] = $dept;
        }
        if (count($uploadRow) > 1) {
            $upload[] = $uploadRow;
        }
    }

    if (!empty($upload)) {
        $json = json_encode($upload);
        $feedback = \REDCap::saveData($pid, "json", $json, 'overwrite');
        return $feedback;
    }
    return ["error" => "No data to upload"];
}
