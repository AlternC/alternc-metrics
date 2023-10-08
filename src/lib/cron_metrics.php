#!/usr/bin/php -q
<?php

if (posix_getuid()!=0) {
    echo "FATAL: this crontab MUST be launched as root.\n";
    exit();
}

require("/usr/share/alternc/panel/class/config_nochk.php");
$admin->enabled=1;

require_once("/usr/share/alternc/panel/class/metrics.php");
$metrics = new metrics();

$metrics->daily_collect();

