<?php

# only if project is copied; otherwise, setup process automatically makes the unique hash

$pid = $_GET['pid'];
$module->setProjectSetting("unique_hash", "d615e1ed4f4e25ba", $pid);
