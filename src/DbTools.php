<?php
/**
 * EGroupware  DeveloperTools - DB-Tools
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2002-24 by rb@egroupware.org
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package developer
 * @subpackage db-tools
 */

namespace EGroupware\Developer;

use EGroupware\Api;

/**
 * DbTools: creates and modifies eGroupWare schema-files (to be installed via setup)
 */
class DbTools
{
	const APP = 'developer';

	/**
	 * Methods callable via menuaction
	 *
	 * @var array
	 */
	public $public_functions = array(
		'edit'         => True,
		'needs_save'   => True,
	);

	/**
	 * Table definitions
	 *
	 * @var array
	 */
	protected $data = [];
	/**
	 * Used app
	 *
	 * @var string
	 */
	protected $app;
	/**
	 * Used table
	 *
	 * @var string
	 */
	protected $table;
	/**
	 * Available colum types
	 *
	 * @var array
	 */
	protected $types = [
		'varchar'	=> 'varchar',
		'int'		=> 'int',
		'auto'		=> 'auto',
		'blob'		=> 'blob',
		'binary'    => 'binary',
		'char'		=> 'char',
		'date'		=> 'date',
		'decimal'	=> 'decimal',
		'float'		=> 'float',
		'longtext'	=> 'longtext',
		'text'		=> 'text',
		'timestamp'	=> 'timestamp',
		'bool'      => 'boolean',
		'ascii'     => 'ascii',
		'vector'    => 'vector',
//		'abstime'   => 'abstime (mysql:timestamp)',
	];
	/**
	 * Available meta-types
	 *
	 * @var array
	 */
	protected static $meta_types = [
		'' => '',
		'account' => 'user or group',
		'account-commasep' => 'multiple comma-separated users or groups',
		'account-abs' => 'user or group (with positiv id)',
		'user' => 'a single user',
		'user-commasep' => 'multiple comma-separated users',
		'user-serialized' => 'multiple serialized users or groups (do NOT use!)',
		'group' => 'a single group',
		'group-commasep' => 'multiple comma-separated groups',
		'group-abs' => 'single group (with positive id)',
		'timestamp' => 'unix timestamp',
		'category' => 'category id',
		'percent' => '0 - 100',
		'cfname' => 'custom field name',
		'cfvalue' => 'custom field value',
		'json' => 'json serialized',
		'json-php-serialized' => 'json or php serialized',
		'serialize' => 'php serialized',
	];

	/**
	 * constructor of class
	 */
	function __construct()
	{
		if (empty($GLOBALS['egw_info']['apps']) || !is_array($GLOBALS['egw_info']['apps']) || !count($GLOBALS['egw_info']['apps']))
		{
			(new Api\Egw\Applications())->read_installed_apps();
		}
		$GLOBALS['egw_info']['flags']['app_header'] =
			$GLOBALS['egw_info']['apps'][self::APP]['title'].' - '.lang('DB-Tools');
	}

	/**
	 * table editor (and the callback/submit-method too)
	 *
	 * @param ?array $content=null
	 * @param string $msg=''
	 */
	function edit(?array $content=null, $msg = '')
	{
		if (is_array($content))
		{
			$this->app = $content['app'];	// this is what the user selected
			$this->table = $content['table_name'];
			$posted_app = $content['posted_app'];	// this is the old selection
			$posted_table = $content['posted_table'];
		}
		else
		{
			$this->app = $_GET['app'] ?? '';
			$this->table = $_GET['table'] ?? '';
		}

		// user changed app or table
		if ($posted_app && $posted_table && ($posted_app != $this->app || $posted_table != $this->table))
		{
			if ($this->needs_save('',$posted_app,$posted_table,$this->content2table($content)))
			{
				return;
			}
			$this->renames = [];
		}
		if (!$this->app)
		{
			$this->table = '';
			$table_names = array('' => lang('none'));
		}
		else
		{
			self::read($this->app,$this->data);

			foreach($this->data as $name => $table)
			{
				$table_names[$name] = $name;
			}
		}
		if (empty($this->table) || !empty($posted_app) && $this->app != $posted_app)
		{
			$this->table = key($this->data);	// use first table
		}
		elseif ($this->app === $posted_app && !empty($posted_table))
		{
			$this->data[$posted_table] = $this->content2table($content);
		}
		// make sure F5 returns to active app&table
		Api\Cache::setSession(self::APP, 'active', [
			'menuaction' => self::APP.'.'.self::class.'.edit',
			'app' => $this->app,
			'table' => $this->table,
			'ajax' => 'true',
		]);
		if (!empty($content['write_tables']))
		{
			if ($this->needs_save('',$this->app,$this->table,$this->data[$posted_table]))
			{
				return;
			}
			$msg .= lang('Table unchanged, no write necessary !!!');
		}
		elseif (!empty($content['delete']))
		{
			unset($this->data[$posted_table]['fd'][$key=$content['Row'.key($content['delete'])]['name']]);
			$this->changes[$posted_table][$key] = '**deleted**';
		}
		elseif (!empty($content['add_column']))
		{
			$this->data[$posted_table]['fd'][''] = [];
		}
		elseif (!empty($content['add_table']) || !empty($content['import']))
		{
			if (empty($this->app))
			{
				$msg .= lang('Select an app first !!!');
			}
			elseif (empty($content['new_table_name']))
			{
				$msg .= lang('Please enter table-name first !!!');
			}
			elseif (!empty($content['add_table']))
			{
				$this->table = $content['new_table_name'];
				$this->data[$this->table] = array('fd' => [],'pk' =>[],'ix' => [],'uc' => [],'fk' => []);
				$msg .= lang('New table created');
			}
			else // import
			{
				$oProc = new Api\Db\Schema($GLOBALS['egw_info']['server']['db_type']);
				if (method_exists($oProc,'GetTableDefinition'))
				{
					$this->data[$this->table = $content['new_table_name']] = $oProc->GetTableDefinition($content['new_table_name']);
				}
				else	// to support eGW 1.0
				{
					$oProc->m_odb = clone($GLOBALS['egw']->db);
					$oProc->_GetColumns($oProc,$content['new_table_name'],$nul);

					foreach ($oProc->sCol as $tbldata)
					{
						$cols .= $tbldata;
					}
					eval('$cols = array('. $cols . ');');

					$this->data[$this->table = $content['new_table_name']] = array(
						'fd' => $cols,
						'pk' => $oProc->pk,
						'fk' => $oProc->fk,
						'ix' => $oProc->ix,
						'uc' => $oProc->uc
					);
				}
			}
		}
		$add_index = isset($content['add_index']);

		// from here on, filling new content for eTemplate
		$content = array(
			'msg' => $msg,
			'table_name' => $this->table,
			'app' => $this->app,
		);
		if (!isset($table_names[$this->table]))	// table is not jet written
		{
			$table_names[$this->table] = $this->table;
		}
		$sel_options = array(
			'table_name' => $table_names,
			'type' => $this->types,
		);
		foreach(self::$meta_types as $value => $title)
		{
			$sel_options['meta'][$value] = $value ? array(
				'label' => $value,
				'title' => $title,
			) : $title;
		}
		foreach($this->data[$this->table]['fd'] as $col => $data)
		{
			$meta = $title = $data['meta'];
			if (empty($meta)) continue;
			if (is_array($meta))
			{
				$this->data[$this->table]['fd'][$col]['meta'] = $meta = serialize($meta);
				$title = self::write_array($data['meta'], 0);
			}
			if (!isset($sel_options['meta'][$meta]))
			{
				$sel_options['meta'][$meta] = array(
					'label' => lang('Custom'),
					'title' => $title,
				);
			}
		}
		if (!empty($this->table) && isset($this->data[$this->table]))
		{
			$content += $this->table2content($this->data[$this->table],$sel_options['Index'],$add_index);
		}
		$readonlys = [];
		if (!$this->app || !$this->table)
		{
			$readonlys['write_tables'] = True;
		}
		$tpl = new Api\Etemplate(self::APP.'.db-tools.edit');
		$tpl->exec(self::APP.'.'.self::class.'.edit', $content, $sel_options, $readonlys, [
			'posted_table' => $this->table,
			'posted_app'   => $this->app,
			'changes'      => $this->changes,
		]);
	}

	/**
	 * checks if table was changed and if so offers user to save changes
	 *
	 * @param array $cont the content of the form (if called by process_exec)
	 * @param string $posted_app the app the table is from
	 * @param string $posted_table the table-name
	 * @param array $edited_table the edited table-definitions
	 * @return boolean only returns, if no changes
	 */
	function needs_save($cont='',$posted_app='',$posted_table='',$edited_table='',$msg='')
	{
		if (!$posted_app && is_array($cont))
		{
			if (isset($cont['yes']))
			{
				$this->app   = $cont['app'];
				$this->table = $cont['table'];
				self::read($this->app,$this->data);
				$this->data[$this->table] = $cont['edited_table'];
				$this->changes = $cont['changes'];
				if ($cont['new_version'])
				{
					$this->update($this->app,$this->data,$cont['new_version']);
				}
				else
				{
					foreach($this->data as $tname => $tinfo)
					{
						$tables .= ($tables ? ',' : '') . "'$tname'";
					}
					$this->setup_version($this->app,'',$tables);
				}
				if (!$this->write($this->app,$this->data))
				{
					$this->app = $cont['new_app'];	// these are the ones, the users whiches to change too
					$this->table = $cont['new_table'];

					return $this->needs_save('',$cont['app'],$cont['table'],$cont['edited_table'],
						lang('Error: writing file (no write-permission for the webserver) !!!'));
				}
				$msg = lang('File written');
			}
			$this->changes = [];
			// return to edit with everything set, so the user gets the table he asked for
			$this->edit([
				'app' => $cont['new_app'],
				'table_name' => $cont['app'] === $cont['new_app'] ? $cont['new_table'] : '',
				'posted_app' => $cont['new_app']
			], $msg);

			return True;
		}
		$new_app   = $this->app;	// these are the ones, the users wishes to change too
		$new_table = $this->table;

		$this->app = $posted_app;
		$this->data = [];
		self::read($posted_app,$this->data);

		if (isset($this->data[$posted_table]) &&
			self::tables_identical($this->data[$posted_table],$edited_table))
		{
			if ($new_app != $this->app)	// are we changeing the app, or hit the user just write
			{
				$this->app = $new_app;	// if we change init the data empty
				$this->data = [];
			}
			return False;	// continue edit
		}
		$content = [
			'msg' => $msg,
			'app' => $posted_app,
			'table' => $posted_table,
			'version' => $this->setup_version($posted_app)
		];
		$preserv = $content + [
			'new_app' => $new_app,
			'new_table' => $new_table,
			'edited_table' => $edited_table,
			'changes' => $this->changes
		];
		$new_version = explode('.',$content['version']);
		if (count($new_version) <= 2)
		{
			$new_version[] = '001';
		}
		else
		{
			$minor = count($new_version)-1;
			$new_version[$minor] = sprintf('%03d',1+(int)$new_version[$minor]);
		}
		$content['new_version'] = implode('.', $new_version);

		$tmpl = new Api\Etemplate(self::APP.'.db-tools.ask_save');

		if (!file_exists(EGW_SERVER_ROOT."/$posted_app/setup/tables_current.inc.php"))
		{
			$tmpl->disableElement('version');
			$tmpl->disableElement('new_version');
		}
		$tmpl->exec(self::APP.'.'.self::class.'.needs_save',$content,[],[],$preserv);

		return True;	// dont continue in edit
	}

	/**
	 * checks if there is an index (only) on $col (not a multiple index incl. $col)
	 *
	 * @param string $col column name
	 * @param array $index ix or uc array of table-defintion
	 * @param string &$options db specific options
	 * @return True if $col has a single index
	 */
	protected static function has_single_index($col,$index,&$options)
	{
		$options = [];
		foreach($index as $in)
		{
			if ($in == $col || is_array($in) && $in[0] == $col && !isset($in[1]))
			{
				if ($in != $col && isset($in['options']))
				{
					foreach($in['options'] as $db => $opts)
					{
						$options[] = $db.'('.(is_array($opts)?implode(',',$opts):$opts).')';
					}
					$options = implode(', ',$options);
				}
				return True;
			}
		}
		return False;
	}

	/**
	 * creates content-array from a table
	 *
	 * @param array $table table-definition, eg. $phpgw_baseline[$table_name]
	 * @param array &$columns returns array with column-names
	 * @param bool $extra_index add an additional index-row
	 * @return array content-array to call exec with
	 */
	protected function table2content($table,&$columns,$extra_index=False)
	{
		$content = $columns = [];
		$n = 1;
		foreach($table['fd'] as $col_name => $col_defs)
		{
			$col_defs['name'] = $col_name;
			$col_defs['pk'] = in_array($col_name,$table['pk']);
			$col_defs['uc']  = self::has_single_index($col_name,$table['uc'],$col_defs['options']);
			$col_defs['ix'] = self::has_single_index($col_name,$table['ix'],$col_defs['options']);
			$col_defs['fk'] = $table['fk'][$col_name];
			if (isset($col_defs['default']) && $col_defs['default'] == '')
			{
				$col_defs['default'] = is_int($col_defs['default']) ? '0' : "''";	// spezial value for empty, but set, default
			}
			$col_defs['notnull'] = isset($col_defs['nullable']) && !$col_defs['nullable'];

			$col_defs['n'] = $n;

			$content["Row$n"] = $col_defs;

			if (!empty($col_name))
			{
				$columns[$col_name] = $col_name;
			}
			++$n;
		}
		$n = 2;
		foreach(array('uc','ix') as $type)
		{
			foreach($table[$type] as $index)
			{
				if (is_array($index) && isset($index[1]))	// multi-column index
				{
					$content['Index'][$n]['unique'] = $type === 'uc';
					$content['Index'][$n]['n'] = $n - 1;
					foreach($index as $col)
					{
						$content['Index'][$n][] = $col;
					}
					++$n;
				}
			}
		}
		if ($extra_index)
		{
			$content['Index'][$n]['n'] = $n-1;
		}
		return $content;
	}

	/**
	 * creates table-definition from posted content
	 *
	 * It sets some reasonable defaults for not set precisions (else setup will not install)
	 *
	 * @param array $content posted content-array
	 * @return array table-definition
	 */
	protected function content2table($content)
	{
		if (!is_array($this->data))
		{
			self::read($content['posted_app'],$this->data);
		}
		$old_cols = $this->data[$posted_table = $content['posted_table']]['fd'];
		$this->changes = $content['changes'];

		$table = [];
		$table['fd'] = [];	// do it in the default order of tables_*
		$table['pk'] = [];
		$table['fk'] = [];
		$table['ix'] = [];
		$table['uc'] = [];
		for ($n = 1; isset($content["Row$n"]); ++$n)
		{
			$col = $content["Row$n"];

			if ($col['type'] === 'auto')	// auto columns are the primary key and not null!
			{
				$col['pk'] = $col['notnull'] = true;	// set it, in case the user forgot
			}

			while ((list($old_name,$old_col) = @each($old_cols)) &&
				$this->changes[$posted_table][$old_name] == '**deleted**')
			{

			}

			if (!empty($name = $col['name']))		// ignoring lines without column-name
			{
				if ($col['name'] != $old_name && is_array($old_cols) && $n <= count($old_cols))	// column renamed --> remeber it
				{
					$this->changes[$posted_table][$old_name] = $col['name'];
				}
				if ($col['precision'] <= 0)
				{
					switch ($col['type']) // set some defaults for precision, else setup fails
					{
						case 'float':
						case 'int':     $col['precision'] = 4; break;
						case 'char':    $col['precision'] = 1; break;
						case 'varchar': $col['precision'] = 255; break;
					}
				}
				foreach($col as $prop => $val)
				{
					switch ($prop)
					{
						case 'default':
						case 'type':	// selectbox ensures type is not empty
						case 'meta':
						case 'precision':
						case 'scale':
						case 'comment':
							if ($val != '')
							{
								$table['fd'][$name][$prop] = $prop=='default'&& $val=="''" ? '' : $val;
							}
							break;
						case 'notnull':
							if ($val)
							{
								$table['fd'][$name]['nullable'] = False;
							}
							break;
						case 'pk':
						case 'uc':
						case 'ix':
							if ($val)
							{
								if ($col['options'])
								{
									$opts = [];
									foreach(explode(',',$col['options']) as $opt)
									{
										list($db,$opt) = preg_split('/[(:)]/',$opt);
										$opts[$db] = is_numeric($opt) ? intval($opt) : $opt;
									}
									$table[$prop][] = array(
										$name,
										'options' => $opts
									);
								}
								else
								{
									$table[$prop][] = $name;
								}
							}
							break;
						case 'fk':
							if ($val != '')
							{
								$table['fk'][$name] = $val;
							}
							break;
					}
				}
				$num2col[$n] = $col['name'];
			}
		}
		foreach($content['Index'] as $n => $index)
		{
			$idx_arr = array_filter($index);
			unset($idx_arr['unique']);
			if (count($idx_arr) && !isset($content['delete_index'][$n]))
			{
				if ($index['unique'])
				{
					$table['uc'][] = $idx_arr;
				}
				else
				{
					$table['ix'][] = $idx_arr;
				}
			}
		}
		return $table;
	}

	/**
	 * includes $app/setup/tables_current.inc.php
	 * @param string $app application name
	 * @param array &$phpgw_baseline where to return the data
	 * @return boolean True if file found, False else
	 */
	protected static function read($app,&$phpgw_baseline)
	{
		$file = EGW_SERVER_ROOT."/$app/setup/tables_current.inc.php";

		$phpgw_baseline = [];

		if ($app != '' && file_exists($file))
		{
			include($file);
		}
		else
		{
			return False;
		}
		return True;
	}

	/**
	 * returns an array as string in php-notation
	 *
	 * @param array $arr
	 * @param int $depth for idention
	 * @param string $parent
	 * @return string
	 */
	protected static function write_array($arr,$depth,$parent='')
	{
		if (in_array($parent,array('pk','fk','ix','uc')))
		{
			$depth = 0;
		}
		if ($depth)
		{
			$tabs = "\n".str_repeat("\t",$depth-1);
			++$depth;
		}
		$def = 'array('.$tabs.($tabs ? "\t" : '');

		$n = 0;
		foreach($arr as $key => $val)
		{
			if (!is_int($key))
			{
				if (strpos($key, "'") !== false && strpos($key, '"') === false)
				{
					$def .= '"'.$key.'"';
				}
				else
				{
					$def .= "'".addslashes($key)."'";
				}
				$def .= ' => ';
			}
			// unserialize custom meta values
			if ($key === 'meta' && is_string($val) && (($v = @unserialize($val, ['allowed_classes'=>false])) !== false || $val === serialize(false)))
			{
				$val = $v;
			}
			if (is_array($val))
			{
				$def .= self::write_array($val,$parent == 'fd' ? 0 : $depth,$key);
			}
			else
			{
				if ($key === 'nullable')
				{
					$def .= $val ? 'True' : 'False';
				}
				elseif (strpos($val, "'") !== false && strpos($val, '"') === false)
				{
					$def .= '"'.$val.'"';
				}
				else
				{
					$def .= "'".addslashes($val)."'";
				}
			}
			if ($n < count($arr)-1)
			{
				$def .= ','.$tabs.($tabs ? "\t" : '');
			}
			++$n;
		}
		$def .= $tabs.')';

		return $def;
	}

	/**
	 * writes tabledefinitions $phpgw_baseline to file /$app/setup/tables_current.inc.php
	 *
	 * @param string $app app-name
	 * @param array $phpgw_baseline tabledefinitions
	 * @return boolean True if file writen else False
	 */
	protected function write($app,$phpgw_baseline)
	{
		$file = EGW_SERVER_ROOT."/$app/setup/tables_current.inc.php";

		if (file_exists($file) && ($f = fopen($file,'r')))
		{
			$header = fread($f,filesize($file));
			if ($end = strpos($header,');'))
			{
				$footer = substr($header,$end+3);	// this preservs other stuff, which should not be there
			}
			$header = substr($header,0,strpos($header,'$phpgw_baseline'));
			fclose($f);

			if (is_writable(EGW_SERVER_ROOT."/$app/setup"))
			{
				$old_file = EGW_SERVER_ROOT . "/$app/setup/tables_current.old.inc.php";
				if (file_exists($old_file))
				{
					unlink($old_file);
				}
				rename($file,$old_file);
			}
			while ($header[strlen($header)-1] == "\t")
			{
				$header = substr($header,0,-1);
			}
		}
		if (!$header)
		{
			$header = self::setup_header($this->app) . "\n\n";
		}
		if (!is_writable(EGW_SERVER_ROOT."/$app/setup") || !($f = fopen($file,'w')))
		{
			return False;
		}
		$def = "\$phpgw_baseline = ";
		$def .= self::write_array($phpgw_baseline,1);
		$def .= ";";

		fwrite($f,$header . $def . $footer);
		fclose($f);

		// need to invalidate OpCache, to be able to read/include changed file
		if (function_exists('opcache_invalidate'))
		{
			opcache_invalidate($file);
		}

		return True;
	}

	/**
	 * reads and updates the version and tables info in file $app/setup/setup.inc.php
	 *
	 * @param string $app the app
	 * @param string $new new version number to set, if $new != ''
	 * @param string $tables new tables to include (comma delimited), if != ''
	 * @return the version or False if the file could not be read or written
	 */
	protected function setup_version($app,$new = '',$tables='')
	{
		$setup_info = [];
		$file = EGW_SERVER_ROOT."/$app/setup/setup.inc.php";
		if (file_exists($file))
		{
			include($file);
		}
		if (!isset($setup_info[$app]) || !is_array($setup_info[$app]) || !isset($setup_info[$app]['version']))
		{
			return False;
		}
		if (($new == '' || $setup_info[$app]['version'] == $new) &&
				(!$tables || $setup_info[$app]['tables'] && "'".implode("','",$setup_info[$app]['tables'])."'" == $tables))
		{
			return $setup_info[$app]['version'];	// no change requested or not necessary
		}
		if ($new == '')
		{
			$new = $setup_info[$app]['version'];
		}
		if (!($f = fopen($file,'r')))
		{
			return False;
		}
		$fcontent = fread($f,filesize($file));
		fclose ($f);

		$app_pattern = "'$app'";
		if (preg_match("/define\('([^']+)',$app_pattern\)/",$fcontent,$matches))
		{
			$app_pattern = $matches[1];
		}
		if (is_writable(EGW_SERVER_ROOT."/$app/setup"))
		{
			$old_file = EGW_SERVER_ROOT . "/$app/setup/setup.old.inc.php";
			if (file_exists($old_file))
			{
				unlink($old_file);
			}
			rename($file,$old_file);
		}
		$fnew = preg_replace('/(.*\\$'."setup_info\\[$app_pattern\\]\\['version'\\][ \\t]*=[ \\t]*)'[^']*'(.*)/i","\\1'$new'\\2",$fcontent);

		if ($tables != '')
		{
			if (isset($setup_info[$app]['tables']))	// if there is already tables array, update it
			{
				$fnew = preg_replace('/(.*\\$'."setup_info\\[$app_pattern\\]\\['tables'\\][ \\t]*=[ \\t]*array\()[^)]*/i","\\1$tables",$fwas=$fnew);

				if ($fwas == $fnew)	// nothing changed => tables are in single lines
				{
					$fwas = explode("\n",$fwas);
					$fnew = $prefix = '';
					$stage = 0;	// 0 = before, 1 = in, 2 = after tables section
					foreach($fwas as $line)
					{
						if (preg_match('/(.*\\$'."setup_info\\[$app_pattern\\]\\['tables'\\]\\[[ \\t]*\\][ \\t]*=[ \\t]*)'/i",$line,$parts))
						{
							if ($stage == 0)	// first line of tables-section
							{
								$stage = 1;
								$prefix = $parts[1];
							}
						}
						else					// not in table-section
						{
							if ($stage == 1)	// first line after tables-section ==> add it
							{
								$tables = explode(',',$tables);
								foreach ($tables as $table)
								{
									$fnew .= $prefix . $table . ";\n";
								}
								$stage = 2;
							}
							if (strpos($line,'?>') === False)	// dont write the closeing tag
							{
								$fnew .= $line . "\n";
							}
						}
					}
				}
			}
			else	// add the tables array
			{
				if (strpos($fnew,'?>') !== false)	// remove a closeing tag
				{
					$fnew = str_replace('?>','',$fnew);
				}
				$fnew .= "\t\$setup_info[$app_pattern]['tables'] = array($tables);\n";
			}
		}
		if (!is_writable(EGW_SERVER_ROOT."/$app/setup") || !($f = fopen($file,'w')))
		{
			return False;
		}
		fwrite($f,$fnew);
		fclose($f);

		return $new;
	}

	/**
	 * updates file /$app/setup/tables_update.inc.php to reflect changes in $current
	 *
	 * @param string $app app-name
	 * @param array $current new table-definitions
	 * @param string $version new version
	 * @return boolean True if file is written else False
	 */
	protected function update($app,$current,$version)
	{
		if (!is_writable(EGW_SERVER_ROOT."/$app/setup"))
		{
			return False;
		}
		$file_current  = EGW_SERVER_ROOT."/$app/setup/tables_current.inc.php";
		$file_update   = EGW_SERVER_ROOT."/$app/setup/tables_update.inc.php";

		$old_version = $this->setup_version($app);
		$old_version_ = str_replace('.','_',$old_version);

		if (file_exists($file_update))
		{
			$f = fopen($file_update,'r');
			$update = fread($f,filesize($file_update));
			$update = str_replace('?>','',$update);
			fclose($f);
			$old_file = EGW_SERVER_ROOT . "/$app/setup/tables_update.old.inc.php";
			if (file_exists($old_file))
			{
				unlink($old_file);
			}
			rename($file_update,$old_file);
		}
		else
		{
			$update = self::setup_header($this->app);
		}
		$update .= "

function $app"."_upgrade$old_version_()
{\n";

			$update .= $this->update_schema($app,$current,$tables);

			$update .= "
	return \$GLOBALS['setup_info']['$app']['currentver'] = '$version';
}";
		if (!($f = fopen($file_update,'w')))
		{
			return False;
		}
		fwrite($f,$update);
		fclose($f);

		$this->setup_version($app,$version,$tables);

		return True;
	}

	/**
	 * unsets all keys in an array which have a given value
	 *
	 * @param array &$arr
	 * @param mixed $value value to check against
	 */
	protected static function remove_from_array(&$arr,$value)
	{
		foreach($arr as $key => $val)
		{
			if ($val == $value)
			{
				unset($arr[$key]);
			}
		}
	}

	/**
	 * creates an update-script
	 *
	 * @param string $app app-name
	 * @param array $current new table-defintion
	 * @param string &$tables returns comma delimited list of new table-names
	 * @return string the update-script
	 */
	protected function update_schema($app,$current,&$tables)
	{
		self::read($app,$old);

		$tables = '';
		foreach($old as $name => $table_def)
		{
			if (!isset($current[$name]))	// table $name dropped
			{
				$update .= "\t\$GLOBALS['egw_setup']->oProc->DropTable('$name');\n";
			}
			else
			{
				$tables .= ($tables ? ',' : '') . "'$name'";

				$new_table_def = $table_def;
				foreach($table_def['fd'] as $col => $col_def)
				{
					if (!isset($current[$name]['fd'][$col]))	// column $col droped
					{
						if (!isset($this->changes[$name][$col]) || $this->changes[$name][$col] == '**deleted**')
						{
							unset($new_table_def['fd'][$col]);
							self::remove_from_array($new_table_def['pk'],$col);
							self::remove_from_array($new_table_def['fk'],$col);
							self::remove_from_array($new_table_def['ix'],$col);
							self::remove_from_array($new_table_def['uc'],$col);
							$update .= "\t\$GLOBALS['egw_setup']->oProc->DropColumn('$name',";
							$update .= self::write_array($new_table_def,2).",'$col');\n";
						}
						else	// column $col renamed
						{
							$new_col = $this->changes[$name][$col];
							$update .= "\t\$GLOBALS['egw_setup']->oProc->RenameColumn('$name','$col','$new_col');\n";
						}
					}
				}
				if (is_array($this->changes[$name]))
				{
					foreach($this->changes[$name] as $col => $new_col)
					{
						if ($new_col != '**deleted**')
						{
							$old[$name]['fd'][$new_col] = $old[$name]['fd'][$col];	// to be able to detect further changes of the definition
							unset($old[$name]['fd'][$col]);
						}
					}
				}
			}
		}
		foreach($current as $name => $table_def)
		{
			if (!isset($old[$name]))	// table $name added
			{
				$tables .= ($tables ? ',' : '') . "'$name'";

				$update .= "\t\$GLOBALS['egw_setup']->oProc->CreateTable('$name',";
				$update .= self::write_array($table_def,2).");\n";
			}
			else
			{
				$old_norm = self::normalize($old[$name]);
				$new_norm = self::normalize($table_def);
				$old_norm_fd = $old_norm['fd']; unset($old_norm['fd']);
				$new_norm_fd = $new_norm['fd']; unset($new_norm['fd']);

				// check if the indices are changed and refresh the table if so
				$do_refresh = serialize($old_norm) != serialize($new_norm);
				// we comment out the Add or AlterColumn code as it is not needed, but might be useful for more complex updates
				foreach($table_def['fd'] as $col => $col_def)
				{
					if (($add = !isset($old[$name]['fd'][$col])) ||	// column $col added
						 serialize($old_norm_fd[$col]) != serialize($new_norm_fd[$col])) // column definition altered
					{
						$update .= "\t".($do_refresh ? "/* done by RefreshTable() anyway\n\t" : '').
							"\$GLOBALS['egw_setup']->oProc->".($add ? 'Add' : 'Alter')."Column('$name','$col',";
						$update .= self::write_array($col_def,2) . ');' . ($do_refresh ? '*/' : '') . "\n";
					}
				}
				if ($do_refresh)
				{
					$update .= "\t\$GLOBALS['egw_setup']->oProc->RefreshTable('$name',";
					$update .= self::write_array($table_def,2).");";
				}
			}
		}
		return $update;
	}

	/**
	 * orders the single-colum-indices after the columns and the multicolunm ones behind
	 *
	 * @param array $index array with indices
	 * @param array $cols array with column-defs (col-name is the key)
	 * @return array the new array of indices
	 */
	protected static function normalize_index($index,$cols)
	{
		$normalized = [];
		foreach($cols as $col => $data)
		{
			foreach($index as $n => $idx)
			{
				if ($idx == $col || is_array($idx) && $idx[0] == $col && !isset($idx[1]))
				{
					$normalized[] = isset($idx['options']) ? $idx : $col;
					unset($index[$n]);
					break;
				}
			}
		}
		foreach($index as $idx)
		{
			$normalized[] = $idx;
		}
		return $normalized;
	}

	/**
	 * normalizes all properties in a table-definition, eg. all nullable properties to True or False
	 *
	 * this is necessary to compare two table-definitions
	 *
	 * @param array $table table-definition
	 * @return array the normalized definition
	 */
	protected static function normalize($table)
	{
		foreach($table['fd'] as $col => $props)
		{
			$table['fd'][$col] = array(
				'type' => (string)$props['type'],
				'precision' => 0+$props['precision'],
				'scale' => 0+$props['scale'],
				'nullable' => !isset($props['nullable']) || !!$props['nullable'],
				'default' => (string)$props['default'],
				'comment' => (string)$props['comment'],
				'meta' => is_array($props['meta']) ? serialize($props['meta']) : $props['meta'],
			);
		}
		return array(
			'fd' => $table['fd'],
			'pk' => $table['pk'],
			'fk' => $table['fk'],
			'ix' => self::normalize_index($table['ix'],$table['fd']),
			'uc' => self::normalize_index($table['uc'],$table['fd'])
		);
	}

	/**
	 * compares two table-definitions, by comparing normaliced string-representations (serialize)
	 *
	 * @param array $a
	 * @param array $b
	 * @return boolean true if they are identical (would create an identical schema), false otherwise
	 *
	 */
	protected static function tables_identical($a,$b)
	{
		$a = serialize(self::normalize($a));
		$b = serialize(self::normalize($b));

		return $a == $b;
	}

	/**
	 * creates file header
	 */
	protected static function setup_header($app)
	{
		return '<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package '. $app. '
 * @subpackage setup
 */
';
	}
}

/**
 * Polyfill for removed each function to keep old code alive
 */
if ((float)PHP_VERSION >= 8.0 && !function_exists('each'))
{
	function each(&$arr)
	{
		if (!is_array($arr) || key($arr) === null) return null;
		$ret = [key($arr), current($arr)];
		next($arr);
		return $ret;
	}
}