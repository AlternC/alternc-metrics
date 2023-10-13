<?php

class metrics {
    
    var $conf=[
        "debug" => true
    ];
    var $classes=[];
    var $metricInstance=[]; // list of sub classes (for each class basically :) ) 

    function __construct() {
        if (is_file("/etc/alternc/metrics.json")) {
            $this->conf=@json_decode(file_get_contents("/etc/alternc/metrics.json"));
        }
        // get the list of alternc's installed classes:
        $this->classes=["mail"=>1,"dom"=>1,"mysql"=>1];

        if (is_file("/usr/share/alternc/panel/class/m_mailman.php")) {
            $this->classes["mailman"]=1;
        }
        if (is_file("/usr/share/alternc/panel/class/m_sympa.php")) {
            $this->classes["sympa"]=1;
        }

        // get the list of metric classes:
        $d=opendir(__DIR__);
        while (($c=readdir($d))!==false) {
            if (substr($c,0,8)=="metrics_" && substr($c,-4)==".php") {
                require_once(__DIR__."/".$c);
                $classname=substr($c,0,-4);
                $this->metricInstance[substr($c,8,-4)]=new $classname($this->conf);
            }
        }
        closedir($d);
    }


    /** 
     * function called at install time to install the metric tables if needed.
     * should be idem-potent
     */
    public function install() {
        global $db;        
        $db->query("SHOW TABLES LIKE 'metrics';");
        if (!$db->next_record()) {
            $db->query("
      CREATE TABLE `metrics` (
      `udate` datetime DEFAULT current_timestamp(),
      `class` varchar(32) NOT NULL,
      `name` varchar(128) NOT NULL,
      `account_id` bigint(20) unsigned DEFAULT NULL,
      `domain_id` bigint(20) unsigned DEFAULT NULL,
      `object_id` bigint(20) unsigned NOT NULL,
      `value` bigint(20) unsigned DEFAULT NULL,
      PRIMARY KEY (`class`,`name`,`object_id`),
      KEY `classacount` (`class`,`account_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
      ");
            echo date("Y-m-d H:i:s")." metrics: installed table metrics\n";
        }
        // now launch install on all classes as needed: 

        foreach($this->metricInstance as $module=>$object) {
            if (isset($this->classes[$module]) && method_exists($object,"install")) {
                $object->install();
            }
        }
        
    }


    /* -------------------------------------------------------------------------------- */
    /**
     * Collect all metrics for all installed classes     
     * call the collect() method on all metrics_* classes.
     */
    public function daily_collect() {

        foreach($this->metricInstance as $module=>$object) {
            if (isset($this->classes[$module])) {
                $object->collect();
            }
        }
 
        // now display errors:
        $first=true;
        foreach($this->metricInstance as $module=>$object) {
            if (isset($this->classes[$module])) {
                if (count($object->error)) {
                    if ($first) { $first=false; echo "The following errors happened:\n"; }
                    echo implode("\n",$object->error)."\n";
                }
            }
        }
        
    }


    /* -------------------------------------------------------------------------------- */
    /**
     * return all metric informations
     * get the info hash on all metrics_* classes.
     */
    public function info() {
        $info=[];
        foreach($this->metricInstance as $module=>$object) {
            if (isset($this->classes[$module])) {
                $info=array_merge($info,$object->info);
            }
        }
        return $info;
    }


    /* -------------------------------------------------------------------------------- */
    /** 
     * returns one or more metric (the latest recorded value) from one or all classes / names, for one account or all, for one domain or all.
     * metrics can be dereferenced, in that case account, domain, and object name are returned too, as strings.
     * @param $filter array of filtering values: 
     * classes => [list of metric classes NAME to restrict to, all if unspecified] 
     * names => [list of metric names, all if unspecified],   (you can use classes or names, but not both)
     * accounts => [list of accounts-ID to restrict to, all if unspecified], 
     * domains => [list of domain-IDs to restrict to, all if unspecified]
     * @param $dereference array of things to dereference as string too (along with their ids). could be : domain, account, object
     * @returns an array of metrics 
     * each metric has the following keys: 
     * [ class, name, type, value and, if applicable: account_id, domain_id, object_id, and if dereferenced: account, domain, object
    */
    public function get($filter=[], $dereference=[]) {

        // default values
        if (!is_array($filter) || !count($filter))
            $filter=[];
        if (!isset($filter["classes"])) {
            $filter["classes"]=array_keys($this->classes);
        }

        if (!isset($filter["names"])) {           
            // call the get metric for all requested classes:
            $metrics=[];
            foreach($this->metricInstance as $class=>$object) {
                if (in_array($class,$filter["classes"])) {
                    $m=$object->get(null,$filter,$dereference);
                    $metrics=array_merge($metrics,$m);
                }                
            }
        } else {
            // call the get metric for all requested names:
            $metrics=[];
            foreach($filter["names"] as $metric) {
                list($classname,$metricname)=explode("_",$metric,2); // we get the first part of the metric name: this is the class name 
                if (isset($this->metricInstance[$classname])) {
                    $m=$this->metricInstance[$classname]->get($metricname,$filter,$dereference);
                    $metrics=array_merge($metrics,$m);
                } else {
                    echo "error: you requested an unknown metric name: $metric\n";
                }
            }
        }

        return $metrics;
    }



} // class metrics



/* -------------------------------------------------------------------------------- */
/** base of all metrics classes, provides metricInstance with utilities 
 */ 
class metrics_base {

    var $conf=[];
    var $error=[];

    // those should be set by the child class:
    public $prefix="";
    public $description="";
    public $list=[];
    public $types=[];
    public $manualmetrics=[];

    function __construct($conf) {
        $this->conf=$conf;
    }

    /** given an email address
     * return the account id, domain id and the address id of it, if locally hosted.
     * or null if the email is not locally hosted.
     */
    protected function getMailInfo($email) {
        global $db;
        static $mailcache=[];
        $email=strtolower($email);
        if (isset($mailcache[$email])) return $mailcache[$email];
        list($m,$d)=explode("@",$email);
        $domid = $this->getDomainInfo($d);
        if (is_null($domid)) {
            return $mailcache[$email]=null;
        }
        $db->query("SELECT id FROM address WHERE address='".addslashes($m)."' AND domain_id=".$domid[1].";");
        if (!$db->next_record()) {
            return $mailcache[$email]=null;
        } 
        return $mailcache[$email]=[$domid[0],$domid[1],$db->f("id")];
    }


    /** given an account id
     * return the account login (name)
     * or null if the account has not been found.
     * cacheall is an indicator that is true if it's likely that we will ask for all accounts...
     */
    protected function getAccountName($account_id,$cacheall=true) {
        global $db;
        static $accountcache=[];
        // cacheall = true => we precache:
        if ($cacheall && !count($accountcache)) {
            $db->query("SELECT uid,login FROM membres;");
            while($db->next_record()) {
                $accountcache[$db->Record["uid"]]=$db->Record["login"];
            }
        }
        if (isset($accountcache[$account_id])) return $accountcache[$account_id];
        $db->query("SELECT login FROM membres WHERE uid=".intval($account_id).";");
        if (!$db->next_record()) {
            return $accountcache[$account_id]=null;
        } 
        return $accountcache[$account_id]=$db->Record["login"];
    }


    /** given a domain id
     * return the domain name
     * or null if the domain has not been found.
     * cacheall is an indicator that is true if it's likely that we will ask for all domains...
     */
    protected function getDomainName($domain_id,$cacheall=true) {
        global $db;
        static $domaincache=[];
        // cacheall = true => we precache:
        if ($cacheall && !count($domaincache)) {
            $db->query("SELECT id,domaine FROM domaines;");
            while($db->next_record()) {
                $domaincache[$db->Record["id"]]=$db->Record["domaine"];
            }
        }
        if (isset($domaincache[$domain_id])) return $domaincache[$domain_id];
        $db->query("SELECT domaine FROM domaines WHERE id=".intval($domain_id).";");
        if (!$db->next_record()) {
            return $domaincache[$domain_id]=null;
        } 
        return $domaincache[$domain_id]=$db->Record["domaine"];
    }


    /** given a FQDN
     * return the account id, domain id and the sub_domain id of it, if locally hosted.
     * or null if this FQDN is not locally hosted.
     */
    protected function getFqdnInfo($fqdn,$account) {
        global $db,$fqdncache;
        static $hostingtypes=null;
        if (is_null($hostingtypes)) {
            $hostingtypes=$this->getHostingTypes();
        }
        $fqdn=strtolower($fqdn);
        if (isset($fqdncache[$fqdn])) return $fqdncache[$fqdn];

        // we search which is the domain, which is the sub part :/ 
        $split=explode(".",$fqdn);
        $found=false;
        for($i=0;$i<count($split);$i++) {
            // we search the biggest longest fqdn hosted on this machine for this account
            $domid = $this->getDomainInfoAccount(implode(".",array_slice($split,$i)),$account); 
            if (!is_null($domid)) {
                $found=true;
                break;
            }
        }
        if (!$found) {
            return $fqdncache[$fqdn]=null;
        }
        // here $i says how many elements we take from $split to search for the sub in sub_domain table for domain $domid

        $domain=implode(".",array_slice($split,$i));
        $sub="";
        if ($i>0) $sub=implode(".",array_slice($split,0,$i)); // undocumented: in array_slice, what about length=0 ? 
        $db->query("SELECT id FROM sub_domaines WHERE domaine='".addslashes($domain)."' AND sub='".addslashes($sub)."' AND type IN ('".implode("','",$hostingtypes)."');");
        if (!$db->next_record()) {
            return $fqdncache[$fqdn]=null;
        } 
        return $fqdncache[$fqdn]=[$domid[0],$domid[1],$db->f("id")];
    }

    /** given a domain name
     * return the account id and domain id, if locally hosted.
     * or null if the domain is not locally hosted.
     */
    protected function getDomainInfo($dom) {
        global $db;
        static $domcache=[];
        $dom=strtolower($dom);
        if (isset($domcache[$dom])) return $domcache[$dom];
        $db->query("SELECT id,compte FROM domaines WHERE domaine='".addslashes($dom)."';");
        if (!$db->next_record()) {
            return $domcache[$dom]=null;
        } 
        return $domcache[$dom]=[$db->f("compte"),$db->f("id")];
    }


    /** given a domain name
     * return the account id and domain id, if locally hosted.
     * or null if the domain is not locally hosted.
     */
    protected function getDomainInfoAccount($dom,$account) {
        global $db;
        static $domcache=[];
        $dom=strtolower($dom);
        if (isset($domcache[$dom."|".$account])) return $domcache[$dom."|".$account];
        $db->query("SELECT id,compte FROM domaines WHERE domaine='".addslashes($dom)."' AND compte=".intval($account).";");
        if (!$db->next_record()) {
            return $domcache[$dom."|".$account]=null;
        } 
        return $domcache[$dom."|".$account]=[$db->f("compte"),$db->f("id")];
    }


    /** list the domaines_type that are hosting ones (having templates in /etc/alternc/templates/apache2/*.conf)
     */
    protected function getHostingTypes() {
        global $db;
        $db->query("SELECT name FROM domaines_type;");
        $ht=[];
        while ($db->next_record()) {
            if (is_file("/etc/alternc/templates/apache2/".$db->Record["name"].".conf")) 
                $ht[]=$db->Record["name"];
        }
        return $ht;
    }
    
    /**
     * return one or all the metrics for this class.
     * you can override this function if needed.
     * if "object" dereferencing is requested, this will call $subclass->dereference($object_id); 
     * to get the object NAME (please use a static cache!)
     * also, if a metric should by obtained not via "metrics" table but via a manual computation, 
     * set that metric in the $this->manualmetrics array, and provide a get_<metricname> method in your subclass.
     */
    public function get($metricname=null, $filter=[], $dereference=[]) {
        global $db;
        if (is_null($metricname)) $metricname=array_keys($this->types); else $metricname=[$metricname];
        $metrics=[];
        foreach($metricname as $m) {
            // some metrics are manual, let's get them via get_<metricname>
            if (in_array($m,$this->manualmetrics)) {
                $func="get_".$m;
                if (method_exists($this,$func)) {
                    $metrics=array_merge($metrics, $this->$func($filter) ); // we call the method on the subclass, and merge the metric it returns.
                }
            } else {
                // @TODO filter by domain_id or account_id or object_id ? 
                // get those from the DB:
                $db->query("SELECT value,account_id,domain_id,object_id FROM metrics where class='".addslashes($this->prefix)."' AND name='".addslashes($m)."';");
                while ($db->next_record()) {
                    $metrics[]=[
                        "name" => $this->prefix."_".$m,
                        "type" => $this->types[$m],
                        "value" => $db->Record["value"],
                        "account_id" => $db->Record["account_id"],
                        "domain_id" => $db->Record["domain_id"],
                        "object_id" => $db->Record["object_id"],
                    ];
                }
            }
        }


        if (isset($dereference) && count($dereference)) {

            if (in_array("domain",$dereference)) {
                $cachealldomain = !isset($filter["domain_id"]);
                foreach($metrics as $i=>$m) {
                    $metrics[$i]["domain"]=$this->getDomainName($m["domain_id"],$cachealldomain);
                }
            }
            if (in_array("account",$dereference)) {
                $cacheallaccounts = !isset($filter["account_id"]);
                foreach($metrics as $i=>$m) {
                    $metrics[$i]["account"]=$this->getAccountName($m["account_id"],$cacheallaccounts);
                }
            }
            if (in_array("object",$dereference)) {
                $cacheallobjects= (count($metrics)>100); // if we have more than 100 metrics, it's likely that we will need all objects anyway
                foreach($metrics as $i=>$m) {
                    $metrics[$i]["object"]=$this->getObjectName($m,$cacheallobjects);
                }                
            }

        } // dereferences if needed
        return $metrics;
    } // get 


    // SHOULD be overriden by child:
    public function getObjectName($metric,$cacheallobjects=false) {
        return null;
    }


} // metrics_base class

