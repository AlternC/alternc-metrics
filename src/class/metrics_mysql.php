<?php

class metrics_mysql extends metrics_base {


    public $description="mysql-related metrics";

    // list of metrics handled by this class:
    // those metrics should ALL start by 'mysql_'
    // type = counter or gauge
    // unit = null or bytes or ?
    // object = null if not applicable, or email or subdomain or db or ? 
    // see https://prometheus.io/docs/concepts/metric_types/ and https://prometheus.io/docs/practices/naming/ for metric type & naming:
    public $info=[
        // those metrics are a bit heavy to compute, so they are computer daily via a crontab.
        "mysql_db_size_bytes" => [ "name" => "The size of each database in bytes", "type" => "gauge", "unit" => "bytes", "object" => "db" ],
        // those metrics are computed "on the fly" when you get them.
        "mysql_db_count" => [ "Number of MySQL database per account", "type" => "gauge", "unit" => null, "object" => null ],
    ];

    var $manualmetrics=["mysql_db_count"];

    
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
        $db->query("DELETE FROM metrics WHERE class='mysql';");
        // for each db, we enumerate its size, 
        $count=0;
        foreach($dbs as $id => $data) {
            $db->query("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema='".addslashes($data["db"])."';");
            $db->next_record();
            if ($db->Record["size"]) {
                $db->query("INSERT INTO metrics SET class='mysql', name='mysql_db_size_bytes', account_id=".$data["uid"].", object_id=".$id.", value=".$db->Record["size"].";");
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

//        "mysql_db_size_bytes"
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

