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
        $db->query("DELETE FROM metrics WHERE class='dom' AND name='web_size_bytes';");
        
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
            $sql.=" ('dom','web_size_bytes',".$value[0].",null,null,".$value[1].") ";
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
            $web_hits_count=[]; $web_out_bytes=[]; $web_hits_404_count=[]; $web_hits_5xx_count=[];
            while ($s=fgets($f,65536)) {                
                $s=trim($s);
                if (preg_match('#^[^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ [^ ]+ ([0-9]+) ([0-9]+|-) .* ([^ ]+)$#',$s,$mat)) { // code taille fqdn
                    $fqdn=preg_replace('#:.*$#','',$mat[3]); // sometimes there is a :80 or :433 :/ remove those
                    if (isset($web_hits_count[$fqdn])) $web_hits_count[$fqdn]++; else $web_hits_count[$fqdn]=1;
                    $hitsize=intval($mat[2]);
                    if (isset($web_out_bytes[$fqdn])) $web_out_bytes[$fqdn]+=$hitsize; else $web_out_bytes[$fqdn]=$hitsize;
                    $code=intval($mat[1]);
                    if ($code==404)
                        if (isset($web_hits_404_count[$fqdn])) $web_hits_404_count[$fqdn]++; else $web_hits_404_count[$fqdn]=1;
                    if ($code>=500 && $code<=599) 
                        if (isset($web_hits_5xx_count[$fqdn])) $web_hits_5xx_count[$fqdn]++; else $web_hits_5xx_count[$fqdn]=1;
                } else { // match
                    if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." dom: Log line is not correct: $s\n";
                }
            } // scan all lines in yesterday's logs.

            fclose($f);
            
            // now delete the old stats and save the new ones.
            $db->query("DELETE FROM metrics WHERE class='dom' AND name!='size' AND account_id=".$account.";");
            
            // we fill the db with all those data:
            foreach($this->types as $var=>$type) {
                if (in_array($var,$this->manualmetrics)) continue; // those are not computed here 
                if ($var=="web_size_bytes") continue; // this one neither :) 

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
        
        if ($metric["name"]=="dom_domain_count") return null;
        if ($metric["name"]=="dom_web_size_bytes") return $this->getAccountName($metric["object_id"]);;
        if ($metric["name"]=="dom_subdomain_count") return $this->getDomainName($metric["object_id"]);;

        if ($metric["name"]=="dom_domain_type_count") return null; // @TODO implement this

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


}