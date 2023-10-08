
MAILTO=""
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# every day after the logrotate, launch alternc-metrics collection
0 7 * * * root cd /usr/lib/alternc && ./cron_metrics.php &>/dev/null

