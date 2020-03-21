<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'yab_api_cache';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.1.2';
$plugin['author'] = 'Tommy schmucker';
$plugin['author_uri'] = 'http://www.yablo.de';
$plugin['description'] = 'Caches url and API calls';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '2';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * yab_api_cache
 *
 * A Textpattern CMS plugin.
 * Caches the output of URI and other GET APIs.
 *
 * @author Tommy Schmucker
 * @link   http://www.yablo.de/
 * @link   http://tommyschmucker.de/
 * @date   2020-03-21
 *
 * This plugin is released under the GNU General Public License Version 2 and above
 * Version 2: http://www.gnu.org/licenses/gpl-2.0.html
 * Version 3: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (class_exists('\Textpattern\Tag\Registry'))
{
  Txp::get('\Textpattern\Tag\Registry')
		->register('yab_api_cache');
}


register_callback('yab_api_cache_install',   'plugin_lifecycle.yab_api_cache', 'installed');
register_callback('yab_api_cache_uninstall', 'plugin_lifecycle.yab_api_cache', 'deleted');


/**
 * Textpattern tag
 * Show the output of url from cache or live
 *
 * @param  array  $atts Textpattern tag attributes
 * @return string 
 */
function yab_api_cache($atts)
{
	extract(lAtts(array(
		'id'          => '', // some unique identifier
		'url'         => null, // api url to be requested
		'cached'      => true, // get cached or requested item
		'cache_time'  => '3600', // cache time
		'clear_cache' => false // if set truly it will clear the cache
	), $atts));

	if ($clear_cache)
	{
		safe_query('TRUNCATE TABLE '.safe_pfx('yab_api_cache'));
		return;
	}

	if (!$url)
	{
		trigger_error('url is empty');
		return;
	}
	
	$url = parse($url);

	if (!$id)
	{
		$id = md5($url);
	}
	else
	{
		$id = parse($id);
	}

	// get from cache
	$data = yab_api_get_from_cache($id, $cache_time);
	if ($data !== false and $cached == true)
	{
		return $data;
	}
	// not chached or expired, create it
	else
	{
		$data = yab_api_get_from_url($url);
		yab_api_set_cache($id, $data);
		return $data;
	}
}

/**
 * Caching. Save data to db
 *
 * @param string   $id   Identifier for this database entry
 * @param string   $data Data to be save cached, saved to db
 * @return boolean       true on success
 */
function yab_api_set_cache($id, $data)
{
	$safe_id   = doSlash($id);
	$safe_data = doSlash($data);

	$save = safe_upsert(
		'yab_api_cache',
		"cachetime = NOW(),
		 content   = '$safe_data'",
		"id        = '$safe_id'"
	);

	return $save;
}


/**
 * Get data from cache/db
 *
 * @param  string  $id         db entry identifier
 * @param  integer $cache_time cache time in seconds
 * @return mixed   db entry as string on success or false
 */
function yab_api_get_from_cache($id, $cache_time)
{
	$safe_id = doSlash($id);

	$rs = safe_row(
		"content, cachetime",
		'yab_api_cache',
		"id = '$safe_id'"
	);

	if ($rs)
	{
		$now   = new DateTime('NOW');
		$cache = new DateTime($rs['cachetime']);
		$cache->modify('+'.$cache_time.' seconds');

		// cache not expired
		if ($cache > $now)
 	 	{
			return doStrip($rs['content']);
		}
	}
	return false;
}


/**
 * Get content from $url
 *
 * @param  string $url URI with content to be cached
 * @return mixed  Output of URI as string on success o false
 */
function yab_api_get_from_url($url)
{
	if (function_exists('curl_init'))
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		$content = curl_exec($ch);
		curl_close($ch);

		return $content;
	}
	else
	{
		return file_get_contents($url);
	}
}


/**
 * Install plugin table to db
 *
 * @return void
 */
function yab_api_cache_install()
{
	safe_query(
		"CREATE TABLE IF NOT EXISTS ".safe_pfx('yab_api_cache')." (
			`id`        VARCHAR(255) NOT NULL DEFAULT '',
			`cachetime` DATETIME NOT NULL,
			`content`   MEDIUMTEXT   NOT NULL,
			PRIMARY KEY(`id`)
		)"
	);
}


/**
 * Delete plugin table from db
 *
 * @return void
 */
function yab_api_cache_uninstall()
{
	@safe_query(
		'DROP TABLE IF EXISTS '.safe_pfx('yab_api_cache')
	);

}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. yab_api_cache

p. Simple URL cache.
Caches the outout of an url (as simple GET APIs, URIs, websites etc.).
It can also be used to cache dynamic pages of the own site. Especially the ones with a lot of huge dynamic lists (you can use "etc_cache":http://www.iut-fbleau.fr/projet/etc/index.php?id=52 parts of the site).

p. *Version:* 0.1.2

h2. Table of contents

# "Plugin requirements":#help-requirements
# "Configuration":#help-config
# "Tags":#help-tags
# "Examples":#help-examples
# "Changelog":#help-changelog
# "License":#help-license
# "Author contact":#help-contact

h2(#help-requirements). Plugin requirements

* Textpattern >= 4.7.x

h2(#help-config). Configuration

Open the plugin code. The yab_api_cache function contains the configuration values. Can also be configured by tag attributes. See tag attribute for info.

h2(#help-tags). Tags

h3. yab_api_cache

This tag will output the content of a given url live or cached.

*id:* any valid string or empty
Default: __not set__
A valid string to identify the content, If not provided ist will be generated by md5 the utl attrbiute.

*url:* a valid string (URI)
Default: __null__
The URI which to be requested and shown

*cached:* integer|bool (1|0)
Default: __true__
If set to 0 (false) the requested content is live instead of the cached one.

*cache_time:* integer (seconds)
Default: __3600__
Cache time in seconds

*clear_cache:* integer|bool (1|0)
Default: __false__
If set to 1 (truly) it will clear the entire cache.

h2(#help-examples). Examples

h3. Example: simplest

bc. <txp:yab_api_cache url="https://exmaple.com/api/show/users" />

p. Shows the cached output of the given url. Renews the chache after 1 hour (3600 seconds). The md5 hash of the url is used as id.

h3. Example: advanced

bc. <txp:yab_api_cache id="my-api-call" url="https://exmaple.com/api/show/users" cache_time="86400" />

p. Shows the cached output of the given url. Renews the chache after 1 day (86400 seconds). An own id is privided

h3. Example: no-cache

bc. <txp:yab_api_cache id="my-api-call" url="https://exmaple.com/api/show/users" cached="0" />

p. Shows the live output of the given url. An own id is privided.

h3. Example: reset

bc. <txp:yab_api_cache clear_cache="1" />

p. Clears the entire cache (Empties the table). 

h2(#help-changelog). Changelog

* v0.1.2 - 2020-03-21
** modified: add plugin help, public release
* v0.1.1 - 2018-02-14
** bugfix: id and url will be parsed
** modifed: id not mandatory, will be created by url
* v0.1.0 - 2017-10-29
** initial release

h2(#help-license). Licence

This plugin is released under the GNU General Public License Version 2 and above

* Version 2: "https://www.gnu.org/licenses/old-licenses/gpl-2.0.html":https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
* Version 3: "https://www.gnu.org/licenses/gpl-3.0.html":https://www.gnu.org/licenses/gpl-3.0.html

h2(#help-contact). Author contact

* "Author's blog":https://www.yablo.de/
* "Author's site":https://tommyschmucker.de/
* "Plugin on GitHub":https://github.com/trenc/yab_api_cache
# --- END PLUGIN HELP ---
-->
<?php
}
?>
