#!/usr/bin/php
<?php

/***
 *
 * Monitoring plugin to check the status of nextcloud serverinfo app
 *
 * Copyright (c) 2019 Kevin Köllmann <mail@kevinkoellmann.de>
 *
 * Usage: /usr/bin/php ./check_nextcloud.php -H cloud.example.com -u /ocs/v2.php/apps/serverinfo/api/v1/info
 *
 *
 * For more information visit https://github.com/koelle25/check_nextcloud
 *
 ***/

// 1k => 1024  
function scan_bytesize($bytesize_str) {
    $exponent = array('B'=>0 ,'kB'=>1,'MB'=> 2 ,'GB'=>3,'TB'=>4,'PB'=>5,'EB'=>6,'ZB'=>7,'YB'=>8,"KB"=>1);
    while(preg_match("/([0-9\.]+)([kKMGT]*B)/",$bytesize_str,$matches)) {
        $bytesize_value = $matches[1] * pow(1024,$exponent[$matches[2]]);
        $bytesize_str = preg_replace("/[0-9\.]+([kKMGT]*B)/",$bytesize_value,$bytesize_str,1);
    }
    return $bytesize_str;
}

// 1024 => 1kB
function format_bytesize($bytes, $decimals = 2) {
  $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), @$size[$factor]);
}

function out_of_range($pattern,$value) {
    if(preg_match('/@([-0-9\.]+)\:([-0-9\.]+)/',scan_bytesize($pattern),$matches)) {
        return ( $value >= $matches[1] and $value <= $matches[2] )? 1 : 0;
    }
    elseif(preg_match('/([-0-9\.]+)\:([-0-9\.]+)/',scan_bytesize($pattern),$matches)) {
        return ( $value < $matches[1] or $value > $matches[2] )? 1 : 0;
    }
    elseif(preg_match('/\~\:([-0-9\.]+)/',scan_bytesize($pattern),$matches)) {
        return ( $value > $matches[1])? 1 : 0;
    }
    elseif(preg_match('/([-0-9\.]+)\:$/',scan_bytesize($pattern),$matches)) {
        return ( $value < $matches[1] ) ? 1 : 0;
    }
    elseif(preg_match('/([-0-9\.]+)/',scan_bytesize($pattern),$matches)) {
        return ($value < 0 or $value > $matches[1])?1:0;
    }
    else {
        return 0;
    }
}       

function performance_status($parameter_value,$ncwarn,$nccrit) {
    if( isset($nccrit) && $nccrit > "" ) {
        if( out_of_range($nccrit,$parameter_value) ) {
            return(2);
        }
    }
    if( isset($ncwarn) && $ncwarn > "" ) {
        if( out_of_range($ncwarn,$parameter_value) ) {
            return(1);
        }
    }
    return(0);
}

function show_helptext() {
  print "check_nextcloud.php - Monitoring plugin to check the status of nextcloud serverinfo app.\n
You need to specify the following parameters:
  -H  hostname: domain address of the nextcloud instance, e.g. cloud.example.com
  -U  uri: of the nextcloud serverinfo api, you'll find it at https://cloud.example.com/settings/admin/serverinfo
  -T  token: authenticate using serverinfo token (either -T or -u and -p)
  -P  Performance Data Parameter:
    freespace  - space available on disk
    memory     - memory usage (free memory triggers alarm, total memory for information purposes)
    database   - size on disk
    swap       - free space in swap
    load       - cpu load (last 1min, 5min, 15min - 1min triggers alarm)
    files      - number of files under management
    users      - number of users logged in (actual, last 5min, 1h, 24h - actual triggers alarm)
    shares     - number of shares published
    patchlevel - pending updates for Nextcloud and modules (yes or no)

  -c  <range>: Critical Value (returns 2 CRITICAL if -P out of range)
  -w  <range>:  Warning Value  (returns 1 WARNING if -P out of range)
  -u  username: to authenticate against the API endpoint
  -p  password: to authenticate against the API endpoint
  -s:  (optional) should the check be done over HTTPS? (default: true)\n
  
  Range:	Alerts when:
    x           value > x or value < 0
    x:          value < x
    ~:x         value > x
    x:y         outside [x,y]
    @x:y        inside  (x,y)
  where x is a number (may include . and -)
  \n\n";
}



// get commands passed as arguments
$options = getopt("H:U:u:p:T:s::c:w:P:");
if (!is_array($options) ) {
  print "There was a problem reading the passed option.\n\n";
  exit(1);
}

if( !isset($options['H']) ) {
  show_helptext();
  exit(3);
}

$nchost = trim($options['H']);
$ncuri  = isset($options['U']) ? trim($options['U']) : "/ocs/v2.php/apps/serverinfo/api/v1/info";
$ncssl  = (isset($options['s']) && is_bool($options['s'])) ? $options['s'] : true;
$nctoken  = isset($options['T'])? trim($options['T']) : "";
$ncpd   = isset($options['P'])?trim($options['P']): 0  ;
$nccrit = isset($options['c'])?trim($options['c']) : "";
$ncwarn = isset($options['w'])?trim($options['w']) : "";  
$ncuser = isset($options['u']) ? trim($options['u']) : "";
$ncpass = isset($options['p']) ? trim($options['p']) : "";

$context=null;
if($nctoken) {
  $opts = array (
      'http' => array (
      'method' => 'GET',
      'header' => "NC-Token:$nctoken",
    )
  );
  $context = stream_context_create($opts);
}
$ncurl = ($ncssl ? "https://" : "http://") . (isset($options['T']) ? "" : $ncuser . ":" . $ncpass . "@") . $nchost . $ncuri;

$url = "${ncurl}?format=json&skipApps=false&skipUpdate=false";
$res_str = file_get_contents($url, false, $context);
if(empty($res_str)) {
  print "Cannot access Nextcloud server info\n\n";
  exit(2);
}
$result = json_decode($res_str, true);

// collect performance data results for output
$statuscode = $result['ocs']['meta']['statuscode'];
$status = $result['ocs']['meta']['status'] . ": " . $result['ocs']['meta']['message'];
$nc_version = $result['ocs']['data']['nextcloud']['system']['version'];
// --- Performance data ---
$pd['freespace'] = $result['ocs']['data']['nextcloud']['system']['freespace'];
$pd['load1'] = $result['ocs']['data']['nextcloud']['system']['cpuload']['0'];
$pd['load5'] = $result['ocs']['data']['nextcloud']['system']['cpuload']['1'];
$pd['load15'] = $result['ocs']['data']['nextcloud']['system']['cpuload']['2'];
$pd['mem_free'] = $result['ocs']['data']['nextcloud']['system']['mem_free'] * 1024;
$pd['mem_total'] = $result['ocs']['data']['nextcloud']['system']['mem_total'] * 1024;
$pd['swap_free'] = $result['ocs']['data']['nextcloud']['system']['swap_free'] * 1024;
$pd['swap_total'] = $result['ocs']['data']['nextcloud']['system']['swap_total'] * 1024;
if(array_('apps',$result['ocs']['data']['nextcloud']['system'])) {
    $pd['app_updates_available'] = $result['ocs']['data']['nextcloud']['system']['apps']['num_updates_available'];
    $pd['app_updates'] = array_keys($result['ocs']['data']['nextcloud']['system']['apps']['app_updates']);
}
else {
    $pd['app_updates_available'] = "";
    $pd['app_updates'] = array();
}
$pd['users'] = $result['ocs']['data']['nextcloud']['storage']['num_users'];
$pd['users_active_5min'] = $result['ocs']['data']['activeUsers']['last5minutes'];
$pd['users_active_1h'] = $result['ocs']['data']['activeUsers']['last1hour'];
$pd['users_active_24h'] = $result['ocs']['data']['activeUsers']['last24hours'];
$pd['files'] = $result['ocs']['data']['nextcloud']['storage']['num_files'];
$pd['shares'] = $result['ocs']['data']['nextcloud']['shares']['num_shares'];
$pd['shares_user'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_user'];
$pd['shares_groups'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_groups'];
$pd['shares_link'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_link'];
$pd['shares_link_no_password'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_link_no_password'];
$pd['shares_fed_sent'] = $result['ocs']['data']['nextcloud']['shares']['num_fed_shares_sent'];
$pd['shares_fed_received'] = $result['ocs']['data']['nextcloud']['shares']['num_fed_shares_received'];
$pd['webserver'] = $result['ocs']['data']['server']['webserver'];
$pd['php_version']= $result['ocs']['data']['server']['php']['version'];
$pd['db'] = $result['ocs']['data']['server']['database']['type'] . " " . $result['ocs']['data']['server']['database']['version'];
$pd['db_size'] = $result['ocs']['data']['server']['database']['size'];


$returncode=0;
// print output for icinga
if ($statuscode == 200) {
    $perf_data="";
    $status_message = ""; # sprintf("Nextcloud Version: %s ",$nc_version);
    // check Performance Data Parameter 
    // returns: 'label'=value[UOM];[warn];[crit];[min];[max]
    if($ncpd == "freespace") {
        $status_message .= sprintf("%s disk space available ", format_bytesize($pd['freespace']));
        $perf_data .= sprintf(" free_space=%sB;%sB;%sB;; ",$pd['freespace'],$ncwarn,$nccrit);
        $returncode = performance_status($pd['freespace'],$ncwarn,$nccrit);
    }
    if($ncpd == "memory") {
        $status_message .= sprintf("%s memory available of %s ", format_bytesize($pd['mem_free']), format_bytesize($pd['mem_total']));
        $perf_data .= sprintf(" memory_free=%sB;%sB;%sB;; ",$pd['mem_free'],$ncwarn,$nccrit);
        $perf_data .= sprintf(" memory_total=%sB;;;; ",$pd['mem_total']);
        $returncode = performance_status($pd['mem_free'],$ncwarn,$nccrit);
    }
    if($ncpd == "swap") {
        $status_message .= sprintf("%s swap available of %s ", format_bytesize($pd['swap_free']), format_bytesize($pd['swap_total']));
        $perf_data .= sprintf(" swap_free=%sB;%sB;%sB;; ",$pd['swap_free'],$ncwarn,$nccrit);
        $perf_data .= sprintf(" swap_total=%sB;;;; ",$pd['swap_total']);
        $returncode = performance_status($pd['swap_free'],$ncwarn,$nccrit);
    }
    if($ncpd == "database") {
        $status_message .= sprintf("database %s size %s ", $pd['db'], format_bytesize($pd['db_size']));
        $perf_data .= sprintf(" database=%sB;%sB;%sB;; ",$pd['db_size'],$ncwarn,$nccrit);
        $returncode = performance_status($pd['db_size'],$ncwarn,$nccrit);
    }
    if($ncpd == "patchlevel") {
        $status_message .= sprintf("Nextcloud version: %s webserver %s, PHP %s, database %s size %s ",
                                   $nc_version, $pd['webserver'],$pd['php_version'],$pd['db'], format_bytesize($pd['db_size']));
        $status_message .= sprintf("%d app updates available (%s) ", $pd['app_updates_available'], implode(", ", $pd['app_updates']));
        $returncode = $pd['app_updates_available'] > 0 ? 1 : 0; 
    }
    if($ncpd == "load") {
        $status_message .= sprintf("cpu load 1min %f 5min %f 15min %f ", $pd['load1'],$pd['load5'],$pd['load15']);
        $perf_data .= sprintf(" cpuload1min=%f;%sB;%sB;; ",$pd['load1'],$ncwarn,$nccrit);
        $perf_data .= sprintf(" cpuload5min=%f;;;; ",$pd['load5']);
        $perf_data .= sprintf(" cpuload15min=%f;;;; ",$pd['load15']);
        $returncode = performance_status($pd['load1'],$ncwarn,$nccrit);
    }

    if($ncpd == "files") {
        $status_message .= sprintf("files - %d", $pd['files']);
        $perf_data .= sprintf("files=%d;%s;%s;; ",$pd['files'],$ncwarn,$nccrit);
        $returncode = performance_status($pd['files'],$ncwarn,$nccrit);
    }
	
    if($ncpd == "users") {
        $status_message .= sprintf("user count: %d 5min: %d 1h: %d 24h %d ",
                                    $pd['users'],$pd['users_active_5min'],$pd['users_active_1h'],$pd['users_active_24h']);
        $perf_data .= sprintf(" users=%d;%sB;%sB;; ",$pd['users'],$ncwarn,$nccrit);
        $perf_data .= sprintf(" users5min=%d;;;; ",$pd['users_active_5min']);
        $perf_data .= sprintf(" users1h=%d;;;; ",$pd['users_active_1h']);
        $perf_data .= sprintf(" users24h=%d;;;; ",$pd['users_active_24h']);
        $returncode = performance_status($pd['users'],$ncwarn,$nccrit);
    }
    if($ncpd == "shares") {
        $status_message .= sprintf("shares: %d user: %d group: %d linked: %d without password: %d federated send: %d received: %d ",
                                    $pd['shares'],$pd['shares_user'],$pd['shares_groups'],
                                    $pd['shares_link'],$pd['shares_link_no_password'],
                                    $pd['shares_fed_sent'],$pd['shares_fed_received']);
        $perf_data .= sprintf(" shares=%d;%s;%s;; ",$pd['shares'],$ncwarn,$nccrit);
        $perf_data .= sprintf(" shares_user=%d;;;; ",$pd['shares_user']);
        $perf_data .= sprintf(" shares_groups=%d;;;; ",$pd['shares_groups']);
        $perf_data .= sprintf(" shares_link=%d;;;; ",$pd['shares_link']);
        $perf_data .= sprintf(" shares_link_no_password=%d;;;; ",$pd['shares_link_no_password']);
        $perf_data .= sprintf(" shares_federated_sent=%d;;;; ",$pd['shares_fed_sent']);
        $perf_data .= sprintf(" shares_federated_received=%d;;;; ",$pd['shares_fed_received']);
        $returncode = performance_status($pd['shares'],$ncwarn,$nccrit);
    }
    printf("%s : %s%s%s", ($returncode==0?'OK':($returncode==1?'WARNING':'CRITICAL')), $status_message, $perf_data>""?"\n|":"", $perf_data);
    exit($returncode);
} 
else if ($statuscode >= 400 && $statuscode < 600) {
    printf("CRITICAL: %s\n",$status);
    exit(2);
} 
else {
  printf("WARNING: %s\n", $status);
  exit(1);
}

?>
