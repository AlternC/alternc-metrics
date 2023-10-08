# alternc-metrics

Compute and publish various metrics on an AlternC server

This software is not really useful alone. You should install alternc-metrics-basic to get a crontab that stores the metrics collected by this package, and show a simple webpage to user to see those metrics. 

Or you could install the alternc-metrics-prometheus package to export those metrics to a prometheus endpoint. 

# Installation : 

To get a debian package, use debuild: 

```
apt install devscripts 
debuild
```

