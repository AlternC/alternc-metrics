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

$m=$metrics->get(null,["object","account","domain"]);
$maxname=1; $maxtype=8; $maxvalue=15; $maxid=6;
$maxaccount=1; $maxdomain=1; $maxobject=1;
foreach($m as $one) {
    $maxname=max($maxname,strlen($one["name"]));
    $maxaccount=max($maxaccount,strlen($one["account"]));
    $maxdomain=max($maxdomain,strlen($one["domain"]));
    $maxobject=max($maxobject,strlen($one["object"]));
}
$space="                                                   ";

foreach($m as $one) {
    echo $one["name"].substr($space,0,$maxname-strlen($one["name"]))." ";
    echo $one["type"].substr($space,0,$maxtype-strlen($one["type"]))." ";
    echo $one["value"].substr($space,0,$maxvalue-strlen($one["value"]))." ";
    echo $one["account_id"].substr($space,0,$maxid-strlen($one["account_id"]))." ";
    echo $one["domain_id"].substr($space,0,$maxid-strlen($one["domain_id"]))." ";
    echo $one["object_id"].substr($space,0,$maxid-strlen($one["object_id"]))." ";
    echo $one["account"].substr($space,0,$maxaccount-strlen($one["account"]))." ";
    echo $one["domain"].substr($space,0,$maxdomain-strlen($one["domain"]))." ";
    echo $one["object"].substr($space,0,$maxobject-strlen($one["object"]))." ";
    echo "\n";
}    



