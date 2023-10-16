<?php


class metrics_dom extends metrics_base {


    public $description="domain & web-hosting-related metrics";

    // list of metrics handled by this class:
    // those metrics will be prefixed by '${prefix}_' in their final name.
    // see https://prometheus.io/docs/concepts/metric_types/ and https://prometheus.io/docs/practices/naming/ for metric type & naming:
    public $info=[
        // defaults are stored per subdomain, but can be computed per domain, or per alternc account.
        // those metrics are a bit heavy to compute, so they are computer daily via a crontab.
        "dom_web_hits_count" => [ "description" => "Number of HTTP hits per sub_domain per day", "type" => "counter", "unit" => null, "object" => "subdomain" ], 
        "dom_web_out_bytes" => [ "description" => "HTTP traffic in bytes per sub_domain per day", "type" => "counter", "unit" => "bytes" ],
        "dom_web_hits_404_count" => [ "description" => "Number of HTTP hits per sub_domain per day that answered with a 404 http-code", "type" => "counter", "unit" => null, "object" => "subdomain" ],
        "dom_web_hits_5xx_count" => [ "description" => "Number of HTTP hits per sub_domain per day that answered with a 502/503/5xx http-code", "type" => "counter", "unit" => null, "object" => "subdomain" ],        
        // those metrics are computed "on the fly" when you get them.
        "dom_subdomain_count" => [ "description" => "Number of sub_domains entries (DNS & hosting) per domain", "type" => "gauge", "unit" => null, "object" => null ],
        "dom_domain_count" => [ "description" => "Total number of domains on the server", "type" => "gauge", "unit" => null, "object" => null ],
        "dom_domain_type_count" => [ "description" => "Number of sub_domain objects of each domain-type (a, mx, php81-fpm...)", "type" => "gauge", "unit" => null, "object" => "domtype" ],
        "dom_web_size_bytes" => [ "description" => "Size of each alternc account in bytes", "type" => "gauge", "unit" => "bytes", "object" => null ],
    ];

    var $manualmetrics=["dom_subdomain_count", "dom_domain_count", "dom_domain_type_count"];

    
    /** 
     * function called at install time to install the metric tables if needed.
     * should be idem-potent
     */
    public function install() {
        global $db;        
        $db->query("SHOW TABLES LIKE 'metrics_domaines_type';");
        if (!$db->next_record()) {
            $db->query("
      CREATE TABLE `metrics_domaines_type` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `name` varchar(255) NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `name` (`name`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
      ");
            echo date("Y-m-d H:i:s")." dom: installed table metrics_domaines_type\n";
        }
    }


    /* -------------------------------------------------------------------------------- */
    /**
     * collect all metrics for the hosting (domain) service.
     * number of hit per sub_domain/domain/account, computed via apache log parsing (from yesterday) 
     * space measures by du on each html account.
     */
    public function collect() {
        global $db,$L_ALTERNC_LOGS,$L_ALTERNC_HTML;

        // read the size of each list archive in /var/lib/sympa/arc/
        $db->query("SELECT uid,login FROM membres;");
        $websize=[];
        while ($db->next_record()) {
            $login=$db->Record["login"];
            $uid=$db->Record["uid"];
            // get its size:
            $out=[];
            exec("du -s --block-size=1 ".escapeshellarg($L_ALTERNC_HTML."/".substr($login,0,1)."/".$login),$out,$res);
            if ($res!=0) {
                if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: can't DU user folder, exit code $res for user $login\n";
                continue;
            } else {
                $size=intval(substr($out[0],0,strpos($out[0],chr(9) ))); // get the first field before tab
                if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: size of login $login is $size\n";
            }
            $websize[]=[ $uid, $size ];
        }

        // now delete the old stats and save the new ones.
        $db->query("DELETE FROM metrics WHERE class='dom' AND name='dom_web_size_bytes';");
        
        $count=0;
        $sql="";
        foreach($websize as $value) {
            if (!$sql || strlen($sql)>1048576) { // should be a bit less than max_packet_size for MySQL ...
                if ($sql && $this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: collected $count metrics\n";
                $db->query($sql);
                $sql="INSERT INTO metrics (class,name,account_id,domain_id,object_id,value) VALUES ";
                $first=true;
            }
            if (!$first) $sql.=",";
            $sql.=" ('dom','dom_web_size_bytes',".$value[0].",null,null,".$value[1].") ";
            $first=false;
            $count++;
        }
        if (!$first) $db->query($sql);


        // read all apache2 logs from yesterday and parse web bandwidth and usage per sub_domain.
        if (!is_dir($L_ALTERNC_LOGS)) {
            $this->error[]="Error while opening apachelog, $L_ALTERNC_LOGS folder does not exist.";
            return false;
        }
        $logdirh=opendir($L_ALTERNC_LOGS);
        $yesterday=date("Ymd",time()-86400);
        while (($logdir=readdir($logdirh))!==false) {
            if (!preg_match('#^([0-9]+)-(.*)$#',$logdir,$mat)) {
                continue; // skip folders not named properly
            }
            if (intval($mat[1])<2000) continue; // also skip folders such as 0000-panel, we don't know what to do with that as of now...
            $log=$L_ALTERNC_LOGS."/".$logdir."/access-".$yesterday.".log";
            if (!is_file($log))
                continue; // skip log folder if there are no apache log for yesterday there...
            
            $account=intval($mat[1]);
            $f=fopen($log,"rb");
            if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: opening log $log \n";

            // we purge the fqdn cache for each account : they may be different (if a subdomain is on another account... yeah...)
            $fqdncache=[];

            // we will remember the hits & bandwidth per FQDN as we go for this account.
            $dom_web_hits_count=[]; $dom_web_out_bytes=[]; $dom_web_hits_404_count=[]; $dom_web_hits_5xx_count=[];
            while ($s=fgets($f,65536)) {                
                $s=trim($s);
                if (preg_match('#^[^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ ([0-9]+) ([0-9]+|-) .* ([^ ]+)$#',$s,$mat)) { // code taille fqdn
                    $fqdn=preg_replace('#:.*$#','',$mat[3]); // sometimes there is a :80 or :433 :/ remove those
                    if (isset($dom_web_hits_count[$fqdn])) $dom_web_hits_count[$fqdn]++; else $dom_web_hits_count[$fqdn]=1;
                    $hitsize=intval($mat[2]);
                    if (isset($dom_web_out_bytes[$fqdn])) $dom_web_out_bytes[$fqdn]+=$hitsize; else $dom_web_out_bytes[$fqdn]=$hitsize;
                    $code=intval($mat[1]);
                    if ($code==404)
                        if (isset($dom_web_hits_404_count[$fqdn])) $dom_web_hits_404_count[$fqdn]++; else $dom_web_hits_404_count[$fqdn]=1;
                    if ($code>=500 && $code<=599) 
                        if (isset($dom_web_hits_5xx_count[$fqdn])) $dom_web_hits_5xx_count[$fqdn]++; else $dom_web_hits_5xx_count[$fqdn]=1;
                } else { // match
                    if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: Log line is not correct: $s\n";
                }
            } // scan all lines in yesterday's logs.

            fclose($f);
            
            // now delete the old stats and save the new ones.
            $db->query("DELETE FROM metrics WHERE class='dom' AND name!='dom_web_size_bytes' AND account_id=".$account.";");
            
            // we fill the db with all those data:
            foreach($this->info as $var=>$info) {
                if (in_array($var,$this->manualmetrics)) continue; // those are not computed here 
                if ($var=="dom_web_size_bytes") continue; // this one neither :) 

                $sql="";
                foreach($$var as $fqdn => $value) {
                    if (!$sql || strlen($sql)>1048576) { // should be a bit less than max_packet_size for MySQL ...
                        if ($sql && $this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: collected $count metrics\n";
                        $db->query($sql);
                        $sql="INSERT INTO metrics (class,name,account_id,domain_id,object_id,value) VALUES ";
                        $first=true;
                    }
                    $id=$this->getFqdnInfo($fqdn,$account);
                    if (is_null($id)) {
                        continue;
                    }
                    if (!$first) $sql.=",";
                    $sql.=" ('dom','$var',".$id[0].",".$id[1].",".$id[2].",".$value.") ";
                    $first=false;
                    $count++;
                }
                if (!$first) $db->query($sql);
            }
        } // for each log directory
        closedir($logdirh);
        
        if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: collected $count metrics total\n";

        return true;
    } // collect


    public function getObjectName($metric,$cacheallobjects=false) {
        global $db;
        static $ocache=[];
        static $dtcache=[];

        if ($metric["name"]=="dom_domain_count") return null;
        if ($metric["name"]=="dom_web_size_bytes") return $this->getAccountName($metric["object_id"]);
        if ($metric["name"]=="dom_subdomain_count") return null;

        if ($metric["name"]=="dom_domain_type_count") {
            if (!count($dtcache)) {
                $db->query("SELECT id,name FROM metrics_domaines_type;");
                while ($db->next_record()) {
                    $dtcache[$db->Record["id"]]=$db->Record["name"];
                }
            }
            return $dtcache[$metric["object_id"]];
        }

        // all other metrics have sub_domains as object_id:
        // we cache the sub_domains:
        if ($cacheallobjects) {
            $db->query("SELECT id,sub FROM sub_domaines;");
            while($db->next_record()) {
                $ocache[$db->Record["id"]]=$db->Record["sub"];
            }
        }

        if (isset($ocache[$metric["object_id"]])) return $ocache[$metric["object_id"]];
        $db->query("SELECT id,sub FROM sub_domaines WHERE id=".intval($metric["object_id"]).";");
        if ($db->next_record()) {
            return $ocache[$db->Record["id"]]=$db->Record["sub"];
        }
        return null;
    }

    
    /* -------------------------------------------------------------------------------- */
    /**
     * returns the number of subdomains per domain. 
     * may be filtered by accounts or domains (no check is done on their values, sql injection may happen !)
     */
    function get_dom_subdomain_count($filter=[]) {
        global $db;
        $sql="SELECT COUNT(*) AS ct, d.id, s.compte FROM sub_domaines s, domaines d WHERE s.web_action='OK' AND d.domaine=s.domaine ";
        if (isset($filter["accounts"])) {
            $sql.=" AND s.compte IN (".implode(",",$filter["accounts"]).") ";
        }
        if (isset($filter["domains"])) {
            $sql.=" AND d.id IN (".implode(",",$filter["domains"]).") ";
        }
        $sql.=" GROUP BY s.domaine ";
        $db->query($sql);
        $metrics=[];
        // a metric = [ name, value and, if applicable: account_id, domain_id, object_id ]
        while ($db->next_record()) {
            $metrics[]=[ "name" => "dom_subdomain_count", "value" => $db->Record["ct"], "account_id" => $db->Record["compte"], "domain_id" => $db->Record["id"], "object_id" => null ];
        }
        return $metrics;
    }
    

    /* -------------------------------------------------------------------------------- */
    /**
     * returns the number of domains per account  dom_domain_type_count
     * may be filtered by accounts or domains (no check is done on their values, sql injection may happen !)
     */
    function get_dom_domain_count($filter=[]) {
        global $db;
        $sql="SELECT COUNT(*) AS ct, d.id, d.compte FROM domaines d WHERE 1 ";
        if (isset($filter["accounts"])) {
            $sql.=" AND d.compte IN (".implode(",",$filter["accounts"]).") ";
        }
        $sql.=" GROUP BY d.compte "; 
        // this filter doesn't make sense : we ignore it :/ 
        /*
          if (isset($filter["domains"])) {
            $sql.=" AND d.id IN (".implode(",",$filter["domains"]).") ";
        }
        */
        $db->query($sql);
        $metrics=[];
        // a metric = [ name, value and, if applicable: account_id, domain_id, object_id ]
        while ($db->next_record()) {
            $metrics[]=[ "name" => "dom_domain_count", "value" => $db->Record["ct"], "account_id" => $db->Record["compte"], "domain_id" => null, "object_id" => null ];
        }
        return $metrics;
    }

    
    /* -------------------------------------------------------------------------------- */
    /**
     * returns the number of subdomains per type
     * may be filtered by accounts or domains (no check is done on their values, sql injection may happen !)
     */
    function get_dom_domain_type_count($filter=[]) {
        global $db;
        $db->query("INSERT IGNORE INTO metrics_domaines_type (name) SELECT name FROM domaines_type;");
        $sql="SELECT COUNT(*) AS ct, d.id, s.compte, dt.id AS typeid FROM sub_domaines s, domaines d, metrics_domaines_type dt WHERE s.web_action='OK' AND d.domaine=s.domaine AND dt.name=s.type ";
        if (isset($filter["accounts"])) {
            $sql.=" AND s.compte IN (".implode(",",$filter["accounts"]).") ";
        }
        if (isset($filter["domains"])) {
            $sql.=" AND d.id IN (".implode(",",$filter["domains"]).") ";
        }
        $db->query($sql." GROUP BY s.type ");
        $metrics=[];
        // a metric = [ name, value and, if applicable: account_id, domain_id, object_id ]
        while ($db->next_record()) {
            $metrics[]=[ "name" => "dom_domain_type_count", "value" => $db->Record["ct"], "account_id" => null, "domain_id" => null, "object_id" => $db->Record["typeid"] ];
        }
        return $metrics;
    }


}
