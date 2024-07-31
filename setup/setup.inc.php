<?php
/**
 * EGroupware - Developer - setup definitions
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package developer
 * @subpackage setup
 * @copyright (c) 2024 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

$setup_info['developer']['name']      = 'developer';
$setup_info['developer']['version']   = '23.1';
$setup_info['developer']['app_order'] = 1;
$setup_info['developer']['enable']    = 1;
$setup_info['developer']['tables']    = array('egw_translations');
$setup_info['developer']['index']     = 'developer.EGroupware\\Developer\\TranslationTools.index&ajax=true';

$setup_info['developer']['author'] =
$setup_info['developer']['maintainer'] = array(
	'name'  => 'Ralf Becker',
	'email' => 'rb@egroupware.org',
);
$setup_info['developer']['license']  = 'GPL';
$setup_info['developer']['description'] =
'Some tools for EGroupware developers: translation, DB-schema, ...';

// Hooks we implement
$setup_info['developer']['hooks']['search_link'] = 'EGroupware\\Developer\\Hooks::search_link';
$setup_info['developer']['hooks']['admin'] = 'EGroupware\\Developer\\Hooks::all_hooks';
$setup_info['developer']['hooks']['sidebox_menu'] = 'EGroupware\\Developer\\Hooks::all_hooks';

/* Dependencies for this app to work */
$setup_info['developer']['depends'][] = array(
	 'appname' => 'api',
	 'versions' => Array('23.1')
);