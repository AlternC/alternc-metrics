<?php

class metrics_mysql extends metrics_base {


    public $prefix="mysql"; 
    public $description="mysql-related metrics";

    // list of metrics handled by this class:
    // those metrics will be prefixed by '${prefix}_' in their final name.
    // see https://prometheus.io/docs/concepts/metric_types/ and https://prometheus.io/docs/practices/naming/ for metric type & naming:
    public $list=[
        // defaults are stored per pop/imap/smtp account, but can be computed per domain, or per alternc account.
        // those metrics are a bit heavy to compute, so they are computer daily via a crontab.
        "db_size_bytes" => "The size of each database in bytes", 
        // those metrics are computed "on the fly" when you get them.
        "db_count" => "Number of MySQL database per account",
    ];

    public $types=[
        "db_size_bytes" => "gauge", 
        "db_count" => "gauge",
    ];

    var $manualmetrics=["db_count"];

    /**
     * collect all metrics for the mysql DB service.
     * number of DB and number of table per DB can be easily computed via enumeration, but size not. 
     */
    public function collect() {
        global $db;
        
        $db->query("SELECT id,uid,db FROM db");
        $dbs=[];
        while ($db->next_record()) {
            $dbs[$db->Record["id"]]=$db->Record;
        }
        $db->query("DELETE FROM metrics WHERE class='db';");
        // for each db, we enumerate its size, 
        $count=0;
        foreach($dbs as $id => $data) {
            $db->query("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema='".addslashes($data["db"])."';");
            $db->next_record();
            if ($db->Record["size"]) {
                $db->query("INSERT INTO metrics SET class='mysql', name='db_size_bytes', account_id=".$data["uid"].", object_id=".$id.", value=".$db->Record["size"].";");
                $count++;
                if ($this->conf["debug"] && $count%10==0) echo date("Y-m-d H:i:s")." mysql: collected $count metrics\n";
            }
        }
        if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." mysql: collected $count metrics total\n";
        return true;        
    } // collect


    public function getObjectName($metric,$cacheallobjects=false) {
        global $db;
        
        if ($metric["name"]=="mysql_db_count") {
            return $this->getAccountName($metric["object_id"]);
        }

//        "db_size_bytes"
        static $ocache=[];

        if ($cacheallobjects) {
            $db->query("SELECT id,db FROM db;");
            while($db->next_record()) {
                $ocache[$db->Record["id"]]=$db->Record["db"];
            }
        }

        if (isset($ocache[$metric["object_id"]])) return $ocache[$metric["object_id"]];
        $db->query("SELECT id,db FROM db WHERE id=".intval($metric["object_id"]).";");
        if ($db->next_record()) {
            return $ocache[$db->Record["id"]]=$db->Record["db"];
        }
        return null;
    }



}

