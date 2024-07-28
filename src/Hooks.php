<?php
/**
 * EGroupware - Developer - Business logic
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package developer
 * @subpackage setup
 * @copyright (c) 2023 by Ralf Becker <rb-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Developer;

use EGroupware\Api;

class Hooks
{
	/**
	 * Hook called by link-class to include developer app / host in the appregistry of the linkage
	 *
	 * @param array|string $location location and other parameters (not used)
	 * @return array with method-names
	 */
	static function search_link($location)
	{
		unset($location);	// not used, but required by function signature

		return array(
			'query' => Langfiles::APP.'.'.Langfiles::class.'.link_query',
			'title' => Langfiles::APP.'.'.Langfiles::class.'.link_title',
			'edit'  => array(
				'menuaction' => Langfiles::APP.'.'.TranslationTools::class.'.edit',
			),
			'edit_id' => 'row_id',
			'edit_popup'  => '800x320',
			'list' => array(
				'menuaction' => Langfiles::APP.'.'.TranslationTools::class.'.index',
				'ajax' => 'true'
			),
			'add' => array(
				'menuaction' => Langfiles::APP.'.'.TranslationTools::class.'.edit',
			),
			'add_popup'  => '800x320',
		);
	}

	/**
	 * hooks to build developer app's sidebox-menu plus the admin and preferences sections
	 *
	 * @param string|array $args hook args
	 */
	static function all_hooks($args)
	{
		$appname = Langfiles::APP;
		$location = is_array($args) ? $args['location'] : $args;

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				//'Site Configuration' => Api\Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname,'&ajax=true'),
				//'Custom fields' => Api\Egw::link('/index.php','menuaction=admin.admin_customfields.index&appname='.$appname.'&ajax=true'),
				/*'Global Categories'  => Api\Egw::link('/index.php',array(
					'menuaction' => 'admin.admin_categories.index',
					'appname'    => $appname,
					'global_cats'=> True,
					'ajax' => 'true',
				)),*/
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Admin'),$file);
			}
		}
	}
}