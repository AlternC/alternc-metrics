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

$info=$metrics->info();
$max=1;
foreach($info as $m) {
    $max=max($max,strlen($m["name"]));
}
$space="                                               ";
$maxtype=7;

foreach($info as $m) {
    echo $m["name"].substr($space,0,$max-strlen($m["name"]))."  ";
    echo $m["type"].substr($space,0,$maxtype-strlen($m["type"]))."  ";
    echo $m["description"]." ";
    echo "\n";
}

