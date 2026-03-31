<?php


namespace Vanderbilt\FlightConnectorExternalModule;

require_once(__DIR__ . "/LDAP.php");
require_once(__DIR__ . "/base.php");
require_once(__DIR__ . "/NameMatcher.php");

$pid = getPid();
getAdjuncts($pid);

function getAdjuncts($pid)
{
    $FCEM = new FlightConnectorExternalModule();
    $redcapData = $FCEM->framework->escape(\REDCap::getData($pid, "json-array"));
    foreach ($redcapData as $row) {
        $recordId = $row['record_id'];
        $name = $row['name'] ?: $row['firstname'] . " " . $row['lastname'];
        list($first, $last) = NameMatcher::splitName($name);
        $first = preg_replace("/\s+\(\w+\)/", "", $first);    // parentheses throw off LDAP
        $key = LDAP::getNameAssociations($first, $last);
        $nameInfo = LDAP::getLDAPByMultiple(array_keys($key), array_values($key));
        if ($name && ($nameInfo['count'] > 0)) {
            $jobTitle = LDAP::findField($nameInfo, "title", 0);
            if ($jobTitle == "Adjunct Professor") {
                echo "Record $recordId: $name is $jobTitle<br/>";
            }
        }
    }
}
