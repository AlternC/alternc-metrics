<?php

class metrics_sympa extends metrics_base {


    public $prefix="sympa"; 
    public $description="Sympa mailing-list-related metrics";

    // list of metrics handled by this class:
    // those metrics will be prefixed by '${prefix}_' in their final name.
    // see https://prometheus.io/docs/concepts/metric_types/ and https://prometheus.io/docs/practices/naming/ for metric type & naming:
    public $list=[
        // defaults are stored per pop/imap/smtp account, but can be computed per domain, or per alternc account.
        // those metrics are a bit heavy to compute, so they are computer daily via a crontab.
        "archive_size_bytes" => "Size of web archive per list, in bytes",
        "www_hits_count" => "Number of web pages served by wwwsympa per virtual robot (= per domain)", 
        "mail_count" => "Number of emails sent per list per day",
        "mail_total_size_bytes" => "Total size of emails sent per list per day, in bytes",
        "mail_recipient_count" => "Total number of recipients of emails per list per day",
        // those metrics are computed "on the fly" when you get them.
        "list_count" => "Number of mailing-lists per virtual robot (= per domain)",
        "robot_count" => "Number of virtual-robots enabled in Sympa",
    ];

    public $types=[
        "archive_size_bytes" => "gauge",
        "www_hits_count" => "counter", 
        "mail_count" => "counter",
        "mail_total_size_bytes" => "counter",
        "mail_recipient_count" => "counter",
        "list_count" => "gauge",
        "robot_count" => "gauge",
    ];
    
    var $manualmetrics=["list_count","robot_count"];

    /** 
     * function called at install time to install the metric tables if needed.
     * should be idem-potent
     */
    public function install() {
        global $db;        
        $db->query("SHOW TABLES LIKE 'metrics_sympa';");
        if (!$db->next_record()) {
            $db->query("
      CREATE TABLE `metrics_sympa` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `account_id` bigint(20) unsigned DEFAULT NULL,
      `domain_id` bigint(20) unsigned DEFAULT NULL,
      `name` varchar(255) NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `domain_id` (`domain_id`,`name`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
      ");
            echo date("Y-m-d H:i:s")." sympa: installed table metrics_sympa\n";
        }
    }


    /**
     * collect all metrics for the sympa service.
     * number of hit per mail can be computed via syslog parsing (yesterday) 
     */
    public function collect() {
        global $db;

        $this->sympa_sync_lists();

        $sympa_arcdir="/var/lib/sympa/arc";
        // read the size of each list archive in /var/lib/sympa/arc/
        $arch=opendir($sympa_arcdir);
        $arcsize=[];
        while (($arc=readdir($arch))!==false) {
            if (substr($arc,0,1)!="." && is_dir($sympa_arcdir."/".$arc)) {
                // get its size if it's a known list.
                $sympaid = $this->getSympaInfo($arc);
                if (is_null($sympaid)) {
                    if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: can't find list $arc in sympa or domain alternc table\n";
                    continue;
                }
                // get its size:
                $out=[];
                exec("du -s --block-size=1 ".escapeshellarg($sympa_arcdir."/".$arc),$out,$res);
                if ($res!=0) {
                    if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: can't DU archive folder, exit code $res\n";
                    continue;
                }
                $size=intval(substr($out[0],0,strpos($out[0],chr(9) ))); // get the first field before tab
                $arcsize[]=[ $sympaid[0],$sympaid[1],$sympaid[2],$size ];
            }
        }
        closedir($arch);

        // read syslog from yesterday and parse email bandwidth and usage.
        $f=fopen("/var/log/sympa.log.1","rb");
        $first=true;

        // we will remember the pop/imap/smtp sessions as we go:
        $www_hits_count=[]; // hits on wwsympa, per robot (so, per domain) 
        $mail_count=[]; // number of email sent per list
        $mail_total_size_bytes=[]; // number of bytes sent (bytes*recipients) via SMTP for a list.
        $mail_recipient_count=[]; // total number of recipients per day per list. 
        $count=0; $line=0; $match=0;
        while ($s=fgets($f,65536)) {
            $line++;
            // the first line MUST match the date of YESTERDAY. If not, we skip those logs altogether and trigger an error 
            if ($first) {
                $firstlinedate = DateTime::createFromFormat("M j H:i:s",substr($s,0,15));                // format "Oct  5 03:39:27"
                $y = DateTime::createFromFormat("U",time()-86400); // yesterday
                // compare with yesterday:  (ignore the year, so that Jan 1st works too)
                if ($firstlinedate->format("dm") != $y->format("dm")) {
                    $this->error[]="Error while parsing sympa.log.1 for sympa stats: first line doesn't seem to be yesterday. Will skip";
                    return false;
                }
                $first=false;
            }

            //  search for sympa incoming pattern:
            if (preg_match('#sympa_msg\[[0-9]+\]: notice Sympa::Spindle::ProcessIncoming::_twist.. Processing Sympa::Message <([^>]+)>;#',$s,$mat)) {
                if (isset($mail_count[$mat[1]])) $mail_count[$mat[1]]++; else $mail_count[$mat[1]]=1;
                $match++;
            }

            // search for sympa outgoing pattern:
            if (preg_match('#sympa_msg\[[0-9]+\]: info Sympa::Spindle::ToList::_twist.. Message Sympa::Message <[^>]+> for Sympa::List <([^>]+)> .*, ([0-9]+) subscribers.*, size=([0-9]+)$#',$s,$mat)) { // list / rcpt / size 
                if (isset($mail_recipient_count[$mat[1]])) $mail_recipient_count[$mat[1]]+=intval($mat[2]); else $mail_recipient_count[$mat[1]]=intval($mat[2]);
                if (isset($mail_total_size_bytes[$mat[1]])) $mail_total_size_bytes[$mat[1]]+=intval($mat[2])*intval($mat[3]); else $mail_total_size_bytes[$mat[1]]=intval($mat[2])*intval($mat[3]);
                $match++;
            }

            // hit on wwsympa, per robot: Oct  6 09:52:03 fcgtweb1 wwsympa[3490023]: info main::do_home() [robot listes.ferc-cgt.org] [session 57885542889862] [client 212.83.165.226             // now search for wwsympa patterin:
            if (preg_match('#wwsympa\[[0-9]+\]: .*\[robot ([^\]]+)\] #',$s,$mat)) {
                if (isset($www_hits_count[$mat[1]])) $www_hits_count[$mat[1]]++; else $www_hits_count[$mat[1]]=1;
                $match++;
            }
            $lastline=$s;
            if ($this->conf["debug"] && $line%100000==0) echo date("Y-m-d H:i:s")." sympa: read $line lines, found $match information\n";
        } // scan all lines in yesterday's logs.
        fclose($f);
        // parse last line date : 
        $lastlinedate = DateTime::createFromFormat("M j H:i:s",substr($lastline,0,15));                // format "Oct  5 03:39:27"
        // if we parse more than 36 hours of logs, we ignore those :/ your logrotate is bugguy enough ;)  
        if ( ($lastlinedate->format('U')-$firstlinedate->format('U')) > (86400*36) )  {
            $this->error[]="Error while parsing sympa.log.1 for sympa stats: delay between first and last line is >36h, your logrotate seems bugguy... Skipping";
            return false;            
        }

        // now delete the old stats and save the new ones.
        $db->query("DELETE FROM metrics WHERE class='sympa';");
        if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: inserting metrics, please wait\n";

        //        $arcsize[]=[$dom[0],$dom[1],$db->Record["id"],$size];
        // insert archive size metric (it's structured different than the others...
        $sql="";  
        foreach($arcsize as $one) {
            if (!$sql || strlen($sql)>1048576) { // should be a bit less than max_packet_size for MySQL ...
                if ($sql && $this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: collected $count metrics\n";
                $db->query($sql);
                $sql="INSERT INTO metrics (class,name,account_id,domain_id,object_id,value) VALUES ";
                $first=true;
            }
            if (!$first) $sql.=",";
            $sql.=" ('sympa','archive_size_bytes',".$one[0].",".$one[1].",".$one[2].",".$one[3].") ";
            $first=false;
            $count++;
        }
        if (!$first) $db->query($sql);
        
        foreach($this->types as $var=>$type) {
            if (in_array($var,$this->manualmetrics)) continue; // those are not computed here 
            if ($var=="archive_size_bytes") continue; // this one neither

            $sql="";
            foreach($$var as $email => $value) {
                if (!$sql || strlen($sql)>1048576) { // should be a bit less than max_packet_size for MySQL ...
                    if ($sql && $this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: collected $count metrics\n";
                    $db->query($sql);
                    $sql="INSERT INTO metrics (class,name,account_id,domain_id,object_id,value) VALUES ";
                    $first=true;
                }
                if ($var=="www_hits_count") {
                    $id=$this->getDomainInfo($email); // it's a ROBOT (therefore a DOMAIN, not a MAIL)
                    $id[2]="null"; // no object_id involved
                } else {
                    $id=$this->getSympaInfo($email);
                }
                if (is_null($id)) {
                    continue;
                }
                if (!$first) $sql.=",";
                $sql.=" ('sympa','$var',".$id[0].",".$id[1].",".$id[2].",".$value.") ";
                $first=false;
                $count++;
            }
            if (!$first) $db->query($sql);
        }
        if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: collected $count metrics total\n";
        return true;
        
    } // collect_sympa




    /** given a sympa mail name (list@robot) returns its sympa id, along with domain & account
     */
    private function getSympaInfo($list) {
        global $db;
        static $sympacache=[];
        $list=strtolower($list);
        if (isset($sympacache[$list])) return $sympacache[$list];
        list($m,$d)=explode("@",$list);
        if (!$d) {
            if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: list $list is malformed\n";
        }
        $dom=$this->getDomainInfo($d);
        if (is_null($dom)) {
            return $sympacache[$list]=null;
        }
        // get its sympa-id
        $db->query("SELECT id FROM metrics_sympa WHERE domain_id=".$dom[1]." AND name='".addslashes($m)."';");
        if (!$db->next_record()) {
            return $sympacache[$list]=null;
        }
        return $sympacache[$list]= [ $dom[0], $dom[1], $db->Record["id"] ];
    }


    private function sympa_sync_lists() {
        global $db;
        // we need to sync the list of lists from sympa bdd to alternc table
        // this table does not exist, so this class creates it :) 
        $db->query("select name_list,robot_list from sympa.list_table;");
        $sympa=[];
        while ($db->next_record()) {
            $sympa[]=$db->Record["name_list"]."@".$db->Record["robot_list"];
        }

        // delete the sympa lists for a now deleted domain:
        $db->query("DELETE m FROM metrics_sympa m LEFT JOIN domaines d ON d.id=m.domain_id WHERE d.id IS NULL;");
        if ($aff=$db->affected_rows() && $this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: deleted $aff lists in alternc due to missing domains\n"; 
        // insert missing sympa lists into the cache table:
        $created=0;
        foreach($sympa as $list) {
            list($m,$d)=explode("@",$list);
            $dom=$this->getDomainInfo($d);
            if (is_null($dom)) {
                if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: the list $list is on an unknown domain... skipping\n";
                continue;
            }
            $sql="INSERT IGNORE INTO metrics_sympa SET account_id=".$dom[0].", domain_id=".$dom[1].", name='".addslashes($m)."';";
            $db->query($sql);
            $created+=$db->affected_rows();
        }
        if ($created && $this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: created $created lists in alternc\n";
        // remove sympa lists that have been deleted: 
        $db->query("SELECT m.id, CONCAT(m.name,'@',d.domaine) AS list FROM domaines d, metrics_sympa m WHERE d.id=m.domain_id;");
        $delids=[];
        while ($db->next_record()) {
            if (!in_array($db->Record["list"],$sympa)) {
                $delids[]=$db->Record["id"];
            }
        }
        if (count($delids)) {
            $db->query("DELETE FROM metrics_sympa WHERE id IN (".implode(",",$delids).");");
            if ($aff=$db->affected_rows() && $this->conf["debug"]) echo date("Y-m-d H:i:s")." sympa: deleted $aff lists from alternc due to deleted lists\n"; 
        }
    }


    public function getObjectName($metric,$cacheallobjects=false) {
        global $db;
        static $ocache=[];

        if ($metric["name"]=="sympa_robot_count") return null; // no object in there (per server)

        if ($cacheallobjects) {
            $db->query("SELECT id,name FROM metrics_sympa;");
            while($db->next_record()) {
                $ocache[$db->Record["id"]]=$db->Record["name"];
            }
        }

        // the following metrics are per-robot, not per list
        $perdomain=["sympa_www_hits_count","sympa_list_count"];
        if (in_array($metric["name"],$perdomain)) {
            $db->query("SELECT id,mail FROM sympa WHERE id=".intval($metric["object_id"]).";");
            if ($db->next_record()) {
                return $db->Record["mail"]; // no cache here 
            }
        } else {
            // all the others are per-list.
            if (isset($ocache[$metric["object_id"]])) return $ocache[$metric["object_id"]];
            $db->query("SELECT id,name FROM metrics_sympa WHERE id=".intval($metric["object_id"]).";");
            if ($db->next_record()) {
                return $ocache[$db->Record["id"]]=$db->Record["name"];
            }
        }
        return null;
    }


}
