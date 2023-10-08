
MAILTO=""
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# every day after the logrotate, launch alternc-metrics collection
# only if systemd is not installed (if it is, this package deploys a timer, which has priority)
0 7 * * * root cd /usr/lib/alternc && if [ ! -d /run/systemd/system ]; then ./cron_metrics.php ; fi  &>/dev/null

