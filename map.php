<?php

$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");

echo "<title>Flight Connector Installation</title>";
echo "<link rel='stylesheet' href='$cssUrl' />";
echo "<link rel='icon' href='$ftLogo' />";
