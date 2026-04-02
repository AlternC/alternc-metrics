# alternc-metrics

Compute and publish various metrics on an AlternC server

This software is not really useful alone. You should install alternc-metrics-basic to get a crontab that stores the metrics collected by this package, and show a simple webpage to user to see those metrics. 

Or you could install the alternc-metrics-prometheus package to export those metrics to a prometheus endpoint. 

# How does it work?

the main class/file is `metrics.php`, which scans for all metrics_*.php files as submodules.

The main class is named `metrics` and is in charge of installing tables for itself and other classes, launching daily collection of hard-to-get metrics, and provide informations about all metrics submodules.

Metrics submodule are defined for each AlternC class like dom (for domains), mysql, mail, but also from alternc external modules such as sympa or mailman.

Any AlternC module can provide its own `metrics_modulename.php`  file as long as it provides a subclass of `metric_base` baseclass.



# Installation: 

To get a debian package, use debuild: 

```
apt install devscripts 
debuild
```

