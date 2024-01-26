# check_nextcloud

This is a monitoring plugin for [icinga](https://www.icinga.com) to check the status of the [nextcloud](https://nextcloud.com) serverinfo API.

![Icingaweb2 screenshot showing the check_nextcloud script](/screenshot.png?raw=true "Icingaweb2 screenshot")


## Usage
Try the plugin at the command line like this:
```
/usr/bin/php ./check_nextcloud.php -H cloud.example.com -T access-token
```
For backward compatibility the old user/password method is also possible. Just remember that the user used has to be in the admin group, so use appropriate measures (a strong password, HTTPS only, etc.):
```
/usr/bin/php ./check_nextcloud.php -H cloud.example.com -u apiuser -p apipassword
```

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
  -A:  (optional) air gapped server (no patch level checks to Nextcloud - default: false)
  
  Range:	Alerts when:
    x:          value < x
    :x          value > x
    x:y         outside [x,y]
    @x:y        inside  (x,y)
    x%          value less than x percent (memory and swap only)
  where x is a number (which may include . and -)

  Examples:  
   -P memory -w 5G:  - warn when free memory drops below 5G
   -P memory -c 1G:  - less than 1GB memory is critical
   -P users  -w :100 - warn if more than 100 users are concurrently logged in
   -P patchlevel     - warn if new version or app updates available



You can define the icinga2 check command as follows:
```
object CheckCommand "nextcloud" {
  import "plugin-check-command"

  command = [ PluginDir + "/check_nextcloud.php" ]

  arguments = {
    "-H" = {
      "required" = true
      "description" = "Nextcloud hostname/fqdn (e.g. 'cloud.example.com')"
      "value" = "$nc_host$"
    }
    "-U" = {
      "required" = false
      "description" = "serverinfo API url, you can find it on https://cloud.example.com/settings/admi$
      "value" = "$nc_api_url$"
    }
    "-T" = {
      "required" = false
      "description" = "server info token - define it here https://cloud.example.com/index.php/settings/admin/serverinfo"
      "value" = "$nc_api_user$"
    }
    "-u" = {
      "required" = false
      "description" = "API user (has to be an admin) - obsolete when -T provided"
      "value" = "$nc_api_user$"
    }
    "-p" = {
      "required" = false
      "description" = "API-user's password - obsolete when -T provided"
      "value" = "$nc_api_password$"
    }
    "-P" = {
            description = "Performance Data Parameter"
            value = "$nextcloud_performance_data$"
        }
    "-c" = {
      "required" = false
      "description" = "Critical - when -P performance data out of this rage"
      "value" = "$nc_api_range_criticals$"
    }
    "-w" = {
      "required" = false
      "description" = "Warning - when -P performance data out of this rage"
      "value" = "$nc_api_range_warnings$"
    }
  }

  vars.nc_api_url = "/ocs/v2.php/apps/serverinfo/api/v1/info"
}
```
The script automatically adds skipApps=false&skipUpdate=false to the URL except for air gapped installations (-A)  where automatic updates do not apply.

## Changelog
* 2019-05-08: initial version (koelle25)
* 2023-04-07: add token athentification (beccon4)
* 2024-01-26: enhanced processing for warning and critical threasholds, implement API changes for NC27 and above, introduce percental threasholds (beccon4)
## Authors
* [Kevin Köllmann](https://github.com/koelle25)

## Contributers
* [Conrad Beckert](https://github.com/beccon4)
