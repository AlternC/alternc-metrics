<?php

class metrics_mail extends metrics_base {

    public $description="email-related (pop imap & smtp) metrics";

    // list of metrics handled by this class:
    // those metrics should ALL start by 'mail_'
    // type = counter or gauge
    // unit = null or bytes or ?
    // object = null if not applicable, or email or subdomain or db or domtype or ? 
    // see https://prometheus.io/docs/concepts/metric_types/ and https://prometheus.io/docs/practices/naming/ for metric type & naming:
    public $info=[
        // defaults are stored per pop/imap/smtp account, but can be computed per domain, or per alternc account.
        // those metrics are a bit heavy to compute, so they are computer daily via a crontab.
        "mail_pop_login_count" => [ "name" => "number of POP login per account per day", "type" => "counter", "unit" => null, "object" => "email" ],
        "mail_pop_usage_out_bytes"  => [ "name" => "outgoing bandwidth used via POP protocol per account per day", "type" => "counter", "unit" => "bytes", "object" => "email" ], 
        "mail_imap_login_count" => [ "name" => "number of IMAP login per account per day", "type" => "counter", "unit" => null, "object" => "email" ],
        "mail_imap_usage_in_bytes" => [ "name" => "incoming bandwidth used via IMAP protocol per account per day", "type" => "counter", "unit" => "bytes", "object" => "email" ],
        "mail_imap_usage_out_bytes" => [ "name" => "outgoing bandwidth used via IMAP protocol per account per day", "type" => "counter", "unit" => "bytes", "object" => "email" ],
        "mail_smtp_relay_message_count" => [ "name" => "number of messages sent via authenticated SMTP per account per day", "type" => "counter", "unit" => null, "object" => "email" ],
        "mail_smtp_relay_message_size_bytes" => [ "name" => "size of all messages sent via authenticated SMTP per account per day", "type" => "counter", "unit" => "bytes", "object" => "email" ],
        "mail_smtp_relay_message_recipient_count" => [ "name" => "total number of recipients for messages sent via authenticated SMTP per account per day", "type" => "counter", "unit" => null, "object" => "email" ],
        "mail_smtp_incoming_message_size_bytes" => [ "name" => "size of all messages received via SMTP on an IMAP account per account per day", "type" => "counter", "unit" => "bytes", "object" => "email" ],
        "mail_smtp_incoming_message_count" => [ "name" => "number of messages received via SMTP on an IMAP account per account per day", "type" => "counter", "unit" => null, "object" => "email" ],
        // those metrics are computed "on the fly" when you get them.
        "mail_mailbox_count" => [ "name" => "number of imap mailboxes per domain", "type" => "gauge", "unit" => null, "object" => null ],
        "mail_mailbox_size_bytes" => [ "name" => "current size of each imap mailbox", "type" => "gauge", "unit" => "bytes", "object" => "email" ],
        "mail_alias_count" => [ "name" => "number of mail aliases per domain", "type" => "gauge", "unit" => null, "object" => null ],
    ];

    var $manualmetrics=["mail_mailbox_count","mail_mailbox_size_bytes","mail_alias_count"];

    /**
     * collect all metrics for the mail service.
     * quota (disk space) are already available via dovecot_quota.
     * number of hit per mail address (imap/pop/smtp login count and bandwidth) can be computed via syslog parsing (yesterday) 
     * this should be launched daily by a crontab.
     */
    public function collect() {
        global $db;

        // read syslog from yesterday and parse email bandwidth and usage.
        $f=fopen("/var/log/syslog.1","rb");
        $first=true;

        // we will remember the pop/imap/smtp sessions as we go:
        $pop=[]; $imap=[]; $smtp=[];
        $mail_pop_login_count=[];
        $mail_pop_usage_out_bytes=[];
        $mail_imap_login_count=[];
        $mail_imap_usage_in_bytes=[];
        $mail_imap_usage_out_bytes=[];
        $mail_smtp_relay_message_count=[];
        $mail_smtp_relay_message_size_bytes=[];
        $mail_smtp_relay_message_recipient_count=[]; 
        $mail_smtp_incoming_message_size_bytes=[];
        $mail_smtp_incoming_message_count=[];
        $count=0; $line=0; $match=0;
        while ($s=fgets($f,65536)) {
            $line++;
            // the first line MUST match the date of YESTERDAY. If not, we skip those logs altogether and trigger an error 
            if ($first) {
                $firstlinedate = DateTime::createFromFormat("M j H:i:s",substr($s,0,15));                // format "Oct  5 03:39:27"
                $y = DateTime::createFromFormat("U",time()-86400); // yesterday
                // compare with yesterday:  (ignore the year, so that Jan 1st works too)
                if ($firstlinedate->format("dm") != $y->format("dm")) {
                    $this->error[]="Error while parsing syslog.1 for email stats: first line doesn't seem to be yesterday. Will skip";
                    return false;
                }
                $first=false;
            }

            // now search for dovecot pop pattern:
            if (preg_match('#dovecot: pop3-login: Login: user=<([^>]*)>, .*session=<([^>]*)>#',$s,$mat)) {
                if (isset($mail_pop_login_count[$mat[1]])) $mail_pop_login_count[$mat[1]]++; else $mail_pop_login_count[$mat[1]]=1;
                $match++;
            }
            if (preg_match('#dovecot: pop3\(([^\)]*)\)<[^>]*><([^>]*)>: Disconnected:.*, size=([0-9]*)#',$s,$mat)) {
                if (isset($mail_pop_usage_out_bytes[$mat[1]])) $mail_pop_usage_out_bytes[$mat[1]]+=intval($mat[3]); else $mail_pop_usage_out_bytes[$mat[1]]=intval($mat[3]);
                $match++;
            }

            // now search for dovecot imap pattern:
            if (preg_match('#dovecot: imap-login: Login: user=<([^>]*)>, .*session=<([^>]*)>#',$s,$mat)) {
                if (isset($mail_imap_login_count[$mat[1]])) $mail_imap_login_count[$mat[1]]++; else $mail_imap_login_count[$mat[1]]=1;
                $match++;
            }
            if (preg_match('#dovecot: imap\(([^\)]*)\)<[^>]*><([^>]*)>: (Connection closed|Logged out).*in=([0-9]*) out=([0-9]*) #',$s,$mat)) {
                if (isset($mail_imap_usage_in_bytes[$mat[1]])) $mail_imap_usage_in_bytes[$mat[1]]+=intval($mat[4]); else $mail_imap_usage_in_bytes[$mat[1]]=intval($mat[4]);
                if (isset($mail_imap_usage_out_bytes[$mat[1]])) $mail_imap_usage_out_bytes[$mat[1]]+=intval($mat[5]); else $mail_imap_usage_out_bytes[$mat[1]]=intval($mat[5]);
                $match++;
            }

            // now search for postfix smtp pattern:
            if (preg_match('# postfix.*\[[0-9]+\]: ([0-9A-F]+): client=.*, sasl_username=(.*)#',$s,$mat)) {
                if (isset($mail_smtp_relay_message_count[$mat[2]])) $mail_smtp_relay_message_count[$mat[2]]++; else $mail_smtp_relay_message_count[$mat[2]]=1;
                $smtp[$mat[1]]=$mat[2];
                $match++;
            }
            if (preg_match('#postfix/qmgr\[[0-9]+\]: ([0-9A-F]+): .*, size=([0-9]+), nrcpt=([0-9]+)#',$s,$mat)) {
                if (isset($smtp[$mat[1]])) {
                    // we know the original sasl_username, let's save those statistics
                    if (isset($mail_smtp_relay_message_size_bytes[ $smtp[$mat[1]] ])) $mail_smtp_relay_message_size_bytes[ $smtp[$mat[1]] ]+=intval($mat[2]); else $mail_smtp_relay_message_size_bytes[ $smtp[$mat[1]] ]=intval($mat[2]);
                    if (isset($mail_smtp_relay_message_recipient_count[ $smtp[$mat[1]] ])) $mail_smtp_relay_message_recipient_count[ $smtp[$mat[1]] ]+=intval($mat[3]); else $mail_smtp_relay_message_recipient_count[ $smtp[$mat[1]] ]=intval($mat[3]);
                    unset($smtp[$mat[1]]);
                }
                $match++;
            }

            if (preg_match('#postfix/qmgr\[[0-9]+\]: ([0-9A-F]+): .*, size=([0-9]+), nrcpt=([0-9]+)#',$s,$mat)) {
                $smtpqueue[ $mat[1] ] = $mat[2];
                $match++;
            }
            if (preg_match('#postfix/pipe\[[0-9]+\]: ([0-9A-F]+): to=<([^>]+)>, relay=dovecot.*, status=sent #',$s,$mat)) {
                if (isset($smtpqueue[ $mat[1] ])) {
                    // we know the size from the qmgr via QueueID, let's save those statistics for users now
                    if (isset($mail_smtp_incoming_message_size_bytes[ $mat[2] ])) $mail_smtp_incoming_message_size_bytes[ $mat[2] ]+=intval($smtpqueue[ $mat[1] ]); else $mail_smtp_incoming_message_size_bytes[ $mat[2] ]=intval($smtpqueue[ $mat[1] ]);
                    if (isset($mail_smtp_incoming_message_count[ $mat[2] ])) $mail_smtp_incoming_message_count[ $mat[2] ]+=intval($smtpqueue[ $mat[1] ]); else $mail_smtp_incoming_message_count[ $mat[2] ]=intval($smtpqueue[ $mat[1] ]);
                }
                $match++;
            }
            $lastline=$s;
            if ($this->conf["debug"] && $line%100000==0) echo date("Y-m-d H:i:s")." mail: read $line lines, found $match postfix/dovecot information\n";
        } // scan all lines in yesterday's logs.
        fclose($f);
        // parse last line date : 
        $lastlinedate = DateTime::createFromFormat("M j H:i:s",substr($lastline,0,15));                // format "Oct  5 03:39:27"
        // if we parse more than 36 hours of logs, we ignore those :/ your logrotate is bugguy enough ;)  
        if ( ($lastlinedate->format('U')-$firstlinedate->format('U')) > (86400*36) )  {
            $this->error[]="Error while parsing syslog.1 for email stats: delay between first and last line is >36h, your logrotate seems bugguy... Skipping";
            return false;            
        }
        
        // now delete the old stats and save the new ones.
        $db->query("DELETE FROM metrics WHERE class='mail';");

        if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." mail: inserting metrics, please wait\n";
        // and store those stats in the db:

        foreach($this->list as $var=>$info) {
            if (in_array($var,$this->manualmetrics)) continue; // those are not computed here 

            //  we used to do that on each metric : 
            //      $db->query("INSERT INTO metrics SET class='mail', name='$var', account_id=".$id[0].", domain_id=".$id[1].", object_id=".$id[2].", value=".$value.";"); 
            // but that's clearly VERY slow. ~20sec vs. ~1sec if we use the code below

            // the code below generates a big sql query with multiple inserts. Once it goes above 1MB, it launches the $sql query and reset it.
            $sql="";
            foreach($$var as $email => $value) {
                if (!$sql || strlen($sql)>1048576) { // should be a bit less than max_packet_size for MySQL ...
                    if ($sql && $this->conf["debug"]) echo date("Y-m-d H:i:s")." mail: collected $count metrics\n";
                    $db->query($sql);
                    $sql="INSERT INTO metrics (class,name,account_id,domain_id,object_id,value) VALUES ";
                    $first=true;
                }
                $id=$this->getMailInfo($email);
                if (is_null($id)) {
                    continue;
                }
                if (!$first) $sql.=",";
                $sql.=" ('mail','$var',".$id[0].",".$id[1].",".$id[2].",".$value.") ";
                $first=false;
                $count++;
            }
            // of course, in the end, we must launch the remaining sql query:
            if (!$first) $db->query($sql);
        }

        if ($this->conf["debug"]) echo date("Y-m-d H:i:s")." mail: collected $count metrics total\n";
        return true;

    } // collect_mail


    public function getObjectName($metric,$cacheallobjects=false) {
        global $db;
        static $ocache=[];
        if ($cacheallobjects) {
            $db->query("SELECT id,address FROM address;");
            while($db->next_record()) {
                $ocache[$db->Record["id"]]=$db->Record["address"];
            }
        }

        $null=["mail_mailbox_count","mail_alias_count"];
        if (in_array($metric["name"],$null)) {
            return null;
        }
        
        $perdomain=["mail_mailbox_count", "mail_mailbox_size_bytes", "mail_alias_count"];
        if (in_array($metric["name"],$perdomain)) {
            return $this->getDomainName($metric["object_id"]);
        }
        
        if (isset($ocache[$metric["object_id"]])) return $ocache[$metric["object_id"]];
        $db->query("SELECT id,address FROM address WHERE id=".intval($metric["object_id"]).";");
        if ($db->next_record()) {
            return $ocache[$db->Record["id"]]=$db->Record["address"];
        }
        return null;
    }


} // class metrics_mail

