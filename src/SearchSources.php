<?php
/**
 * EGroupware - Developer - Search sources for translatable phrases
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @author Miles Lott <milos(at)groupwhere.org>
 * @package developer
 * @copyright (c) 2024 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Developer;

use EGroupware\Api;

class SearchSources
{
	protected $functions = array(		// functions containing phrases to translate and param#
		'lang'                => array(1),
		'create_input_box'    => array(1,3),
		'create_check_box'    => array(1,3),
		'create_select_box'   => array(1,4),
		'create_text_area'    => array(1,5),
		'create_notify'       => array(1,5),
		'create_password_box' => array(1,3)
	);
	protected $files = array(
		'config.tpl' => 'config',
		'hook_admin.inc.php' => 'file_admin',
		'hook_preferences.inc.php' => 'file_preferences',
		'hook_settings.inc.php' => 'file',
		'hook_sidebox_menu.inc.php' => 'file',
		'hook_acl_manager.inc.php' => 'acl_manager'
	);
	/**
	 * @var array phrase => app-name pairs
	 */
	protected $plist = [];
	protected $root = EGW_SERVER_ROOT;

	public function __construct($root = EGW_SERVER_ROOT)
	{
		$this->root = $root;
	}

	/**
	 * Search app for translatable phrases
	 *
	 * @param string $app
	 * @param string $root
	 * @return array $phrase => ["app" => $app, "occurrences" => [$file => [...$lines]]]
	 */
	public static function searchApp(string $app, string $root=EGW_SERVER_ROOT)
	{
		$search = new self();
		$search->parse_php_app($app == 'phpgwapi' || $app === 'api' ? 'common' : $app, $app . '/');

		return $search->plist;
	}

	protected function add(string $phrase, string $app, string $file, int $line=0)
	{
		if (!isset($this->plist[$phrase]))
		{
			$this->plist[$phrase] = [
				'app' => $app,
				'occurrences' => [],
			];
		}
		if (str_starts_with($file, $this->root.'/'))
		{
			[, $file] = explode($this->root.'/', $file);
		}
		if (!isset($this->plist[$phrase]['occurrences'][$file]) || !in_array($line, $this->plist[$phrase]['occurrences'][$file]))
		{
			$this->plist[$phrase]['occurrences'][$file][] = $line;
		}
	}

	const IGNORE_PREG = '#(^|/).*(\.old|\.min\.js)(\.|$)#';

	protected function parse_php_app($app, $root)
	{
		$reg_expr = '/('.implode('|',array_keys($this->functions)).")[ \t]*\([ \t]*(.*)$/i";
		if (!($d=dir($root))) return;
		while ($fn=$d->read())
		{
			if (is_dir($root.$fn.'/'))
			{
				if (($fn!='.')&&($fn!='..')&&($fn!='CVS') && $fn != '.svn' && $fn != '.git')
				{
					$this->parse_php_app($app,$root.$fn.'/');
				}
				if ($fn == 'inc')
				{
					// make sure all hooks get called, even if they dont exist as hooks
					foreach($this->files as $f => $type)
					{
						if (substr($f,0,5) == 'hook_' && !file_exists($f = $root.'inc/'.$f))
						{
							$this->special_file($app, $f, $this->files[$type], $root);
						}
					}
				}
			}
			elseif (is_readable($root.$fn))
			{
				if (isset($this->files[$fn]))
				{
					$this->special_file($app, $fn, $this->files[$fn], $root);
				}
				if (substr($fn, -4) == '.xet' && !preg_match(self::IGNORE_PREG, $fn))
				{
					$this->xet_file($app, $root.$fn);
					continue;
				}
				$parts = explode('.', $fn);
				if (!in_array(array_pop($parts), ['php','ts','js']) || preg_match(self::IGNORE_PREG, $fn))
				{
					continue;
				}
				$lines = file($root.$fn);

				foreach($lines as $n => $line)
				{
					while (preg_match($reg_expr,$line,$parts))
					{
						$args = $this->functions[$parts[1]];
						$rest = $parts[2];
						for($i = 1; $i <= $args[0]; ++$i)
						{
							$next = 1;
							if (!$rest || empty($del) || strpos($rest,$del,1) === False)
							{
								$rest .= trim($lines[++$n]);
							}
							$del = $rest[0];
							if ($del == '"' || $del == "'")
							{
								while (($next = strpos($rest,$del,$next)) !== False && $rest[$next-1] == '\\')
								{
									$rest = substr($rest,0,$next-1).substr($rest,$next);
								}
								if ($next === False)
								{
									break;
								}
								$phrase = str_replace('\\\\','\\',substr($rest,1,$next-1));

								if ($args[0] == $i)
								{
									$this->add($phrase, $app, $root.$fn, $n+1);
									array_shift($args);
									if (!count($args))
									{
										break;	// no more args needed
									}
								}
								$rest = substr($rest,$next+1);
							}
							if(!preg_match('/'."[ \t\n]*,[ \t\n]*(.*)$".'/',$rest,$parts))
							{
								break;	// nothing found
							}
							$rest = $parts[1];
						}
						$line = $rest;
					}
				}
			}
		}
		$d->close();
	}

	protected function config_file($app, $fname, $root=EGW_SERVER_ROOT)
	{
		$lines = file($root.'/'.$fname);

		if ($app != 'setup')
		{
			$app = 'admin';
		}
		foreach($lines as $n => $line)
		{
			while (preg_match('/\{lang_([^}]+)\}(.*)/',$line,$found))
			{
				$lang = str_replace('_',' ',$found[1]);
				$this->add($lang, $app, $root.'/'.$fname, $n+1);

				$line = $found[2];
			}
		}
	}

	protected function special_file($app, $fname, $langs_in, $root=EGW_SERVER_ROOT)
	{
		$app_in = $app;
		switch ($langs_in)
		{
		 	case 'config':
				$this->config_file($app, $fname, $root);
				return;
			case 'file_admin':
			case 'file_preferences':
				$app = substr($langs_in,5);
				break;
			case 'phpgwapi':
				$app = 'common';
				break;
		}
		$GLOBALS['file'] = $GLOBALS['settings'] = array();
		unset($GLOBALS['acl_manager']);

		ob_start();		// suppress all output
		// call the hooks and not the files direct, as it works for both files and method hooks
		switch(basename($fname))
		{
			case 'hook_settings.inc.php':
				$settings = $GLOBALS['egw']->hooks->single('settings',$app_in,true);
				if (!is_array($settings) || !$settings)
				{
					$settings =& $GLOBALS['settings'];	// old method of setting GLOBALS[settings], instead returning the settings
					unset($GLOBALS['settings']);
				}
				break;

			case 'hook_admin.inc.php':
				$GLOBALS['egw']->hooks->single('admin',$app_in,true);
				break;

			case 'hook_preferences.inc.php':
				$GLOBALS['egw']->hooks->single('preferences',$app_in,true);
				break;

			case 'hook_acl_manager.inc.php':
				$GLOBALS['egw']->hooks->single('acl_manager',$app_in,true);
				break;

			default:
				include($fname);
				break;
		}
		ob_end_clean();

		if (isset($GLOBALS['acl_manager']))	// hook_acl_manager
		{
			foreach($GLOBALS['acl_manager'] as $app => $data)
			{
				foreach ($data as $item => $arr)
				{
					foreach ($arr as $key => $val)
					{
						switch ($key)
						{
							case 'name':
								$this->plist[$val] = $app;
								break;
							case 'rights':
								foreach($val as $lang => $right)
								{
									$this->plist[$lang] = $app;
								}
								break;
						}
					}
				}
			}
		}
		if (count($GLOBALS['file']))	// hook_{admin|preferences|sidebox_menu}
		{
			foreach ($GLOBALS['file'] as $lang => $link)
			{
				$this->plist[$lang] = $app;
			}
		}
		foreach((array)$settings as $data)
		{
			foreach(array('label','help') as $key)
			{
				if (isset($data[$key]) && !empty($data[$key]))
				{
					// run_lang: NULL, true --> help + label, false --> help only, -1 => none
					if (!isset($data['run_lang']) || !$data['run_lang'] && $key == 'help' || $data['run_lang'] != -1)
					{
						$this->plist[$data[$key]] = $app;
					}
				}
			}
		}
	}

	protected function xet_file($app, $fname)
	{
		$matches = null;
		if (($content = file_get_contents($fname)) &&
			preg_match_all('#((<(label|description|et2-label|et2-description)[^>]+value|label|summary|placeholder|statustext|blur)="([^"]+)"|<option[^>]*>(.*)</option>)#', $content, $matches, PREG_PATTERN_ORDER))
		{
			foreach(array_diff(array_merge($matches[4], $matches[5]), ['']) as $label)
			{
				foreach(preg_match_all('/{([^}]+)}/', $label, $matches, PREG_PATTERN_ORDER) ? $matches[1] : [$label] as $label)
				{
					if (!preg_match('/^(\$|@|[( :%s)0-9-]+$)/', $label))	// blacklist variables and other unwanted stuff as numbers
					{
						$this->add($label, $app, $fname);
					}
				}
			}
		}
	}
}

/*
 * Helper functions for searching new phrases in sidebox, preferences or admin menus
 */
if (!function_exists('display_sidebox') && strpos($_GET['menuaction'], '.uilangfile.') !== false)
{
	function display_sidebox($appname,$menu_title,$file)	// hook_sidebox_menu
	{
		if (!is_array($file)) return;

		unset($file['_NewLine_']);
		if (is_array($GLOBALS['file']))
		{
			$GLOBALS['file'] = $file;
		}
		else
		{
			$GLOBALS['file'] += $file;
		}
	}
}
if (!function_exists('display_section') && strpos($_GET['menuaction'], '.uilangfile.') !== false)
{
	function display_section($appname,$file,$file2='')		// hook_preferences, hook_admin
	{
		if (is_array($file2))
		{
			$file = $file2;
		}
		if (!is_array($file)) return;

		if (is_array($GLOBALS['file']))
		{
			$GLOBALS['file'] = $file;
		}
		else
		{
			$GLOBALS['file'] += $file;
		}
	}
}