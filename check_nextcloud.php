#!/usr/bin/php
<?php

/***
 *
 * Monitoring plugin to check the status of nextcloud serverinfo app
 *
 * Copyright (c) 2019 Kevin Köllmann <mail@kevinkoellmann.de>
 * contribution: Conrad Beckert <kontakt@miradata.de>
 *
 * Usage: /usr/bin/php ./check_nextcloud.php -H cloud.example.com -u /ocs/v2.php/apps/serverinfo/api/v1/info
 *
 *
 * For more information visit https://github.com/koelle25/check_nextcloud
 * For more information visit https://github.com/beccon4/check_nextcloud
 *
 ***/


function convert_filesize($bytes, $decimals = 2) {
  $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), @$size[$factor]);
}

function check_range($pattern,$value) {
    if(preg_match('/@([-0-9\.]+)\:([-0-9\.]+)/',$pattern,$matches)) {
        return ( $value >= $matches[1] and $value <= $matches[2] )? 1 : 0;
    }
    elseif(preg_match('/([-0-9\.]+)\:([-0-9\.]+)/',$pattern,$matches)) {
        return ( $value < $matches[1] or $value > $matches[2] )? 1 : 0;
    }
    elseif(preg_match('/\~\:([-0-9\.]+)/',$pattern,$matches)) {
        return ( $value > $matches[1])? 1 : 0;
    }
    elseif(preg_match('/([-0-9\.]+)\:$/',$pattern,$matches)) {
        return ( $value < $matches[1] ) ? 1 : 0;
    }
    elseif(preg_match('/([-0-9\.]+)/',$pattern,$matches)) {
        return ($value < 0 or $value > $matches[1])?1:0;
    }
    else {
        return 0;
    }
}       

function show_helptext() {
  print "check_nextcloud.php - Monitoring plugin to check the status of nextcloud serverinfo app.\n
You need to specify the following parameters:
  -H hostname: domain address of the nextcloud instance, e.g. cloud.example.com
  -U:  uri of the nextcloud serverinfo api, you'll find it at https://cloud.example.com/settings/admin/serverinfo
  -T token: authenticate using serverinfo token (either -T or -u and -p)
  -P:  Performance Data Parameter:
	freespace
	load1
	load5
	load15
	mem_free
	mem_total
	swap_free
	swap_total
	app_updates_available
	users
	users_active_5min
	users_active_1h
	users_active_24h
	files
	shares
	shares_user
	shares_groups
	shares_link
	shares_fed
	php_version
	db_size
  -c range: Critical Value (returns 2 CRITICAL if -P out of range)
  -w range:  Warning Value  (returns 1 WARNING if -P out of range)
  -u:  username to authenticate against the API endpoint
  -p:  password to authenticate against the API endpoint
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
  exit(2);
}

$nchost = trim($options['H']);
$ncuri  = isset($options['U']) ? trim($options['U']) : "/ocs/v2.php/apps/serverinfo/api/v1/info";
$ncssl  = (isset($options['s']) && is_bool($options['s'])) ? $options['s'] : true;
$ncsit  = isset($options['T'])? trim($options['T']) : "";
$ncpd   = isset($options['P'])?trim($options['P']): 0  ;
$nccrit = isset($options['c'])?trim($options['c']) : "";
$ncwarn = isset($options['w'])?trim($options['w']) : "";  
$ncuser = isset($options['u']) ? trim($options['u']) : "";
$ncpass = isset($options['p']) ? trim($options['p']) : "";

$context=null;
if($ncsit) {
  $opts = array (
    'http' => array (
    'method' => 'GET',
    'header' => "NC-Token:$ncsit",
    )
  );
  $context = stream_context_create($opts);
}
$ncurl = ($ncssl ? "https://" : "http://") . (isset($options['T']) ? "" : $ncuser . ":" . $ncpass . "@") . $nchost . $ncuri;

// get UUID from scan.nextcloud.com service
$url = "${ncurl}?format=json";
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
# --- Performance data ---
$pd['freespace'] = $result['ocs']['data']['nextcloud']['system']['freespace'];
$pd['load1'] = $result['ocs']['data']['nextcloud']['system']['cpuload']['0'];
$pd['load5'] = $result['ocs']['data']['nextcloud']['system']['cpuload']['1'];
$pd['load15'] = $result['ocs']['data']['nextcloud']['system']['cpuload']['2'];
$pd['mem_free'] = $result['ocs']['data']['nextcloud']['system']['mem_free'] * 1024;
$pd['mem_total'] = $result['ocs']['data']['nextcloud']['system']['mem_total'] * 1024;
$pd['swap_free'] = $result['ocs']['data']['nextcloud']['system']['swap_free'] * 1024;
$pd['swap_total'] = $result['ocs']['data']['nextcloud']['system']['swap_total'] * 1024;
$pd['app_updates_available'] = $result['ocs']['data']['nextcloud']['system']['apps']['num_updates_available'];
$app_updates = array_keys($result['ocs']['data']['nextcloud']['system']['apps']['app_updates']);
$pd['users'] = $result['ocs']['data']['nextcloud']['storage']['num_users'];
$pd['users_active_5min'] = $result['ocs']['data']['activeUsers']['last5minutes'];
$pd['users_active_1h'] = $result['ocs']['data']['activeUsers']['last1hour'];
$pd['users_active_24h'] = $result['ocs']['data']['activeUsers']['last24hours'];
$pd['files'] = $result['ocs']['data']['nextcloud']['storage']['num_files'];
$pd['shares'] = $result['ocs']['data']['nextcloud']['shares']['num_shares'];
$pd['shares_user'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_user'];
$pd['shares_groups'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_groups'];
$pd['shares_link'] = $result['ocs']['data']['nextcloud']['shares']['num_shares_link'];
$pd['shares_fed'] = $result['ocs']['data']['nextcloud']['shares']['num_fed_shares_sent'];
$webserver = $result['ocs']['data']['server']['webserver'];
$pd['php_version']= $result['ocs']['data']['server']['php']['version'];
$db = $result['ocs']['data']['server']['database']['type'] . " " . $result['ocs']['data']['server']['database']['version'];
$pd['db_size'] = $result['ocs']['data']['server']['database']['size'];

// check Performance Data Parameter 
if($ncpd) {
    if( !isset($pd[$ncpd]) ) {
        echo "UNKNOWN|Wrong parameter ${$ncpd}";
        exit(3);
    }
    if( isset($nccrit) ) {
        if( check_range($nccrit,$pd[$ncpd]) ) {
            echo "ERROR|${ncpd} ${nccrit}";
            exit(2);
        }
    }
    if( isset($ncwarn) ) {
        if( check_range($ncwarn,$pd[$ncpd]) ) {
            echo "WARNING|${ncpd} ${ncwarn}";
            exit(1);
        }
    }
}

// print output for icinga
if ($statuscode == 200) {
  $status = 'OK';
  $returncode = 0;
  if ($pd['app_updates_available'] > 0) {
    $status = 'WARNING';
    $returncode = 1;
  }
  printf("%s - Nextcloud %s (%s available), ", $status, $nc_version, convert_filesize($pd['freespace']));
  if ($pd['app_updates_available'] > 0) {
    printf("%d app updates available (%s), ", $pd['app_updates_available'], implode(", ", $app_updates));
  }
  printf("%d users (%d < 5min, %d < 1h, %d < 24h), %d files, ", $pd['users'], $pd['users_active_5min'], $pd['users_active_1h'], $pd['users_active_24h'], $pd['files']);
  printf("%d shares (%d user, %d group, %d link, %d federated), ", $pd['shares'], $pd['shares_user'], $pd['shares_groups'], $pd['shares_link'], $pd['shares_fed']);
  printf("%s, PHP %s, %s (%s)", $webserver, $pd['php_version'], $db, convert_filesize($pd['db_size']));
  echo "| free_space=${pd['freespace']}B ";
  echo "load1=${pd['load1']} ";
  echo "load5=${pd['load5']} ";
  echo "load15=${pd['load15']} ";
  echo "mem_free=${pd['mem_free']}B;;;0;${pd['mem_total']} ";
  echo "swap_free=${pd['swap_free']}B;;;0;${pd['swap_total']} ";
  echo "app_updates=${pd['app_updates_available']} ";
  echo "users=${pd['users']} ";
  echo "users5m=${pd['users_active_5min']} ";
  echo "users1h=${pd['users_active_1h']} ";
  echo "users24h=${pd['users_active_24h']} ";
  echo "files=${pd['files']} ";
  echo "shares=${pd['shares']} ";
  echo "db_size=${pd['db_size']}B ";
  echo "\n";
  exit($returncode);
} else if ($statuscode >= 400 && $statuscode < 600) {
  echo "CRITICAL: $status\n";
  exit(2);
} else {
  echo "WARNING: $status\n";
  exit(1);
}
?>
