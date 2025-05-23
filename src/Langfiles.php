<?php
/**
 * EGroupware - Developer - Business logic
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package developer
 * @subpackage setup
 * @copyright (c) 2024 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Developer;

use EGroupware\Api;

class Langfiles extends Api\Storage\Base
{
	const APP = 'developer';
	const TABLE = 'egw_translations';

	const PHRASE_JOIN = ' JOIN '.self::TABLE.' phrases ON phrases.trans_id='.self::TABLE.'.trans_phrase_id';

	public $columns_to_search = ['phrases.trans_text', 'phrases.trans_remark', self::TABLE.'.trans_text', self::TABLE.'.trans_remark'];

	public array $config;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		parent::__construct(self::APP, self::TABLE, null, '', true);
		$this->set_times('object');

		$this->config = Api\Config::read(self::APP);
		$this->langfile_root = $this->config['langfile_root'] ?? EGW_SERVER_ROOT;
	}

	/**
	 * Get path of lang-file(s)
	 *
	 * @param string $app
	 * @param ?string $lang
	 * @return string path of $app's lang-directory or file for $lang
	 */
	public function langPath(string $app, ?string $lang=null)
	{
		return $this->langfile_root.'/'.$app.'/lang'.($lang ? '/egw_'.$lang.'.lang' : '');
	}

	/**
	 * Get modification time of lang-file
	 *
	 * @param string $app
	 * @param string $lang
	 * @return Api\DateTime|null
	 * @throws Api\Exception
	 */
	public function mtimeLangFile(string $app, string $lang)
	{
		static $mtimes = [];
		if (!array_key_exists($path=$this->langPath($app, $lang), $mtimes))
		{
			$mtimes[$path] = !file_exists($path) ? null : new Api\DateTime(filemtime($path), new \DateTimeZone('UTC'));
		}
		return $mtimes[$path];
	}

	/**
	 * Update/refresh DB table from lang-files
	 *
	 * @param string[]|string|null $_app
	 * @param string[]|string|null $_lang
	 * @return int number of translations imported
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 * @throws Api\Exception\WrongParameter
	 * @throws Api\Json\Exception
	 */
	public function importLangFiles($_app=null, $_lang=null) : int
	{
		$n = 0;
		$push = new Api\Json\Push();
		foreach($_app ? (array)$_app : scandir($this->langfile_root) as $app)
		{
			if ($app == '.' || $app == '..' || !file_exists($lang_dir=$this->langPath($app)) || !is_dir($lang_dir))
			{
				continue;
			}
			$added = [];
			set_time_limit(90);
			foreach($_lang ? array_map(static function($_lang) use ($app) {
					return "egw_$_lang.lang";
				}, (array)$_lang) : scandir($lang_dir) as $file)
			{
				if (!file_exists($lang_dir.'/'.$file) ||
					!preg_match('/^egw_([a-z]{2}(-[[a-z]{2})?).lang$/', $file, $matches))
				{
					continue;
				}
				$lang = $matches[1];

				($fp = fopen($lang_dir.'/'.$file, 'r')) || throw new \Exception("Can NOT open lang-file $app/lang/$file!");
				$mtime = new Api\DateTime(filemtime($lang_dir.'/'.$file), new \DateTimeZone('UTC'));
				$format = Api\DateTime::$user_dateformat.' '.Api\DateTime::$user_timeformat;    //.':s';
				$push->message(lang('Importing %1 (%2) ...', "$app/lang/$file", Api\DateTime::server2user($mtime, $format)), 'success');

				// read all (existing) translations of $app and ($lang OR "en"), "en" to have the trans_phrase_id available
				$translations = [];
				foreach($this->db->select(self::TABLE, self::TABLE.'.*,phrases.trans_text AS phrase', [
					self::TABLE.'.trans_app='.$this->db->quote($app),
					'('.self::TABLE.'.trans_lang='.$this->db->quote($lang).' OR '.self::TABLE.".trans_lang='en')",
				], __LINE__, __FILE__, false, '', self::APP, 0,
					' JOIN '.self::TABLE.' phrases ON phrases.trans_id='.self::TABLE.'.trans_phrase_id') as $row)
				{
					$phrase = $row['phrase'];
					if ($row['trans_lang'] === $lang)
					{
						$row['trans_modified'] = new Api\DateTime($row['trans_modified'], Api\DateTime::$server_timezone);
						$translations[$phrase] = $row;
					}
					elseif (!isset($translations[$phrase]))
					{
						$translations[$phrase] = ['trans_phrase_id' => $row['trans_phrase_id']];
					}
				}

				// iterate through all lang-files
				$l = 0;
				while ($line = fgetcsv($fp, null, "\t", "\0", ''))
				{
					++$l;
					[$phrase, $for_app, $lang, $translation] = $line;
					$phrase = strtolower($phrase);

					if (isset($translations[$phrase]) && (($translations[$phrase]['trans_text']??null) === $translation ||
						// ignore (newer) modification in the DB
						!empty($translations[$phrase]['trans_modified']) && $translations[$phrase]['trans_modified'] > $mtime))
					{
						unset($translations[$phrase]);
						$n++;
						continue;   // translation already identical in DB
					}

					try
					{
						// add translation, if it does not exist, otherwise update it
						$this->db->insert(self::TABLE, [
							'trans_text' => $translation,
							'trans_modified' => $mtime,
							'trans_app_for' => $for_app,
						], [
							'trans_app' => $app,
							'trans_lang' => $lang,
							'trans_phrase_id' => $translations[$phrase]['trans_phrase_id'] ?? $this->phraseId($phrase),
						], __LINE__, __FILE__, self::APP);
						unset($translations[$phrase]);
						$n++;
					}
					catch(Api\Db\Exception $ex) {
						$line = implode('<tab>', $line);
						$push->message($msg=$ex->getMessage()." while importing file='$app/lang/egw_$lang.lang' line $l:\n$line\n--> ignored", 'error');
					}
				}
				// delete all no longer existing translations, but keep recently added ones
				// this assumes the translations will be dumped/written BEFORE git pulling changes!
				if ($translations)
				{
					$this->db->delete(self::TABLE, [
						'trans_id' => array_map(static function ($translation) {
							return $translation['trans_id'];
						}, $translations),
						'trans_modified <= ' . $this->db->quote($mtime, 'timestamp'),
					], __LINE__, __FILE__, Langfiles::APP);
				}
			}
		}
		return $n;
	}

	const CHUNK_SIZE = 1024;

	/**
	 * Export/write lang-files to filesystem
	 *
	 * @param string[]|string|null $_app app(s) to save, null for all
	 * @param string[]|string|null $_lang lang(s) to save, null for all
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function exportLangFiles($_app=null, $_lang=null)
	{
		$n = 0;
		$last_app = $last_lang = $fp = null;
		do {
			$row = null;
			foreach($this->db->select(self::TABLE, self::TABLE.'.*,phrases.trans_text AS phrase', array_merge([
					$_lang === 'en' ? self::TABLE.'.trans_phrase_id IS NOT NULL' :
						// only export phrases, which are also in "en"
						self::TABLE.".trans_phrase_id IN (SELECT trans_phrase_id FROM ".self::TABLE." WHERE trans_lang='en' AND trans_app=".$this->db->quote($_app).")",
				], ($_app ? [$this->db->expression(self::TABLE, self::TABLE.'.', ['trans_app' => $_app])] : []),
				($_lang ? [$this->db->expression(self::TABLE, self::TABLE.'.', ['trans_lang' => $_lang])] : [])),
				__LINE__, __FILE__, $n,
				'ORDER BY '.self::TABLE.'.trans_app,'.self::TABLE.'.trans_lang,phrases.trans_text',
				self::APP, self::CHUNK_SIZE, self::PHRASE_JOIN) as $row)
			{
				if ($last_app !== $row['trans_app'] || $last_lang !== $row['trans_lang'])
				{
					if ($fp) fclose($fp);
					if (file_exists($path=$this->langPath($row['trans_app'], $row['trans_lang'])))
					{
						rename($path, $path.'.old');
					}
					($fp = fopen($path, 'w')) || throw new \Exception(lang("Can NOT open $path for writing!"));
					$last_app = $row['trans_app'];
					$last_lang = $row['trans_lang'];
				}
				fwrite($fp, "$row[phrase]\t$row[trans_app_for]\t$row[trans_lang]\t$row[trans_text]\n");
			}
			$n += self::CHUNK_SIZE;
		}
		while ($row);
		if ($fp) fclose($fp);

		// invalidate lang-files, so they get read by next reload
		foreach((array)$_app as $app)
		{
			foreach((array)$_lang as $lang)
			{
				Api\Translation::invalidate_lang_file($app, $lang);
			}
		}
	}

	/**
	 * Searches DB for rows matching search-criteria
	 *
	 * '*' and '?' are replaced with sql-wildcards '%' and '_'
	 *
	 * For a union-query you call search for each query with $start=='UNION' and one more with only $order_by and $start set to run the union-query.
	 *
	 * @param array|string $criteria array of key and data cols, OR string with search pattern (incl. * or ? as wildcards)
	 * @param boolean|string|array $only_keys =true True returns only keys, False returns all cols. or
	 *	comma seperated list or array of columns to return
	 * @param string $order_by ='' fieldnames + {ASC|DESC} separated by colons ',', can also contain a GROUP BY (if it contains ORDER BY)
	 * @param string|array $extra_cols ='' string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $wildcard ='' appended befor and after each criteria
	 * @param boolean $empty =false False=empty criteria are ignored in query, True=empty have to be empty in row
	 * @param string $op ='AND' defaults to 'AND', can be set to 'OR' too, then criteria's are OR'ed together
	 * @param mixed $start =false if != false, return only maxmatch rows begining with start, or array($start,$num), or 'UNION' for a part of a union query
	 * @param array $filter =null if set (!=null) col-data pairs, to be and-ed (!) into the query without wildcards
	 * @param string $join ='' sql to do a join, added as is after the table-name, eg. "JOIN table2 ON x=y" or
	 *	"LEFT JOIN table2 ON (x=y AND z=o)", Note: there's no quoting done on $join, you are responsible for it!!!
	 * @param boolean $need_full_no_count =false If true an unlimited query is run to determine the total number of rows, default false
	 * @return array|NULL|true array of matching rows (the row is an array of the cols), NULL (nothing matched) or true (multiple union queries)
	 */
	function &search($criteria, $only_keys=True, $order_by='', $extra_cols='', $wildcard='', $empty=False, $op='AND', $start=false, $filter=null, $join='', $need_full_no_count=false)
	{
		//error_log(__METHOD__.'('.array2string(array_combine(array_slice(array('criteria','only_keys','order_by','extra_cols','wildcard','empty','op','start','filter','join','need_full_no_count'), 0, count(func_get_args())), func_get_args())).')');
		$extra_cols = $extra_cols ? explode(',', $extra_cols) : [];
		if ($only_keys === false)
		{
			$only_keys = array_keys($this->db->get_table_definitions(self::APP, self::TABLE)['fd']);
		}
		elseif ($only_keys === true)
		{
			$only_keys = ['trans_id'];
		}
		elseif (is_string($only_keys))
		{
			$only_keys = explode(',', $only_keys);
		}
		if (isset($filter['row_id']))
		{
			unset($filter['row_id']);
		}
		if (empty($join))
		{
			// we use "FROM egw_translations en_translations" to always get the untranslated phrases too
			$join = " JOIN egw_translations phrases ON phrases.trans_id=en_translations.trans_phrase_id".
                " LEFT JOIN egw_translations on en_translations.trans_phrase_id=egw_translations.trans_phrase_id".
					" AND egw_translations.trans_app=en_translations.trans_app".
					" AND egw_translations.trans_lang=".$this->db->quote($filter['trans_lang']);
			$filter[] = "en_translations.trans_lang='en'";
			if (array_key_exists('trans_app', $filter) && !empty($filter['trans_app']))
			{
				$filter[] = "en_translations.trans_app=".$this->db->quote($filter['trans_app']);
			}
			unset($filter['trans_app']);
			$extra_cols[] = 'phrases.trans_text AS phrase';
			$extra_cols[] = 'phrases.trans_remark AS phrase_remark';
			// use en ones, otherwise they would be NULL for not (yet) translated phrases!
			$extra_cols[] = 'en_translations.trans_app AS trans_app';
			$extra_cols[] = 'en_translations.trans_app_for AS trans_app_for';
			$extra_cols[] = 'en_translations.trans_phrase_id AS trans_phrase_id';
			$extra_cols[] = 'en_translations.trans_text AS en_text';
			// use queried language, in case not (yet) translated phrases
			$extra_cols[] = $this->db->quote($filter['trans_lang']).' AS trans_lang';
			unset($filter['trans_lang']);
			$only_keys = array_map(static function($column)
			{
				return self::TABLE.'.'.$column.' AS '.$column;
			}, $only_keys);
			$order_by = str_replace('trans_', self::TABLE.'.trans_', $order_by);
			$this->table_name = self::TABLE.' en_translations'; // FROM egw_translations en_translations
		}
		$ret =& parent::search($criteria, $only_keys, $order_by, $extra_cols, $wildcard, $empty, $op, $start, $filter, $join, $need_full_no_count);
		$this->table_name = self::TABLE;
		return $ret;
	}

	/**
	 * Get a default list of columns to search
	 * This is to be used as a fallback, for when the extending class does not define
	 * $this->columns_to_search.  All the columns are considered, and any with $skip_columns_with in
	 * their name are discarded because these columns are expected to be foreign keys or other numeric
	 * values with no meaning to the user.
	 *
	 * @return array of column names
	 */
	protected function get_default_search_columns()
	{
		$search_cols = parent::get_default_search_columns();
		$search_cols[] = 'en_translations.trans_text';
		return $search_cols;
	}

		/**
	 * Reads row matched by key and puts all cols in the data array
	 *
	 * @param array $keys array with keys in form internalName => value, may be a scalar value if only one key
	 * @param string|array $extra_cols ='' string or array of strings to be added to the SELECT, e.g. "count(*) as num"
	 * @param string $join ='' sql to do a join, added as is after the table-name, e.g. ", table2 WHERE x=y" or
	 * @return array|boolean data if row could be retrieved else False
	 */
	function read($keys, $extra_cols='', $join='')
	{
		if (!is_array($keys))
		{
			$keys = is_numeric($keys) ? ['trans_id' => $keys] : array_combine(['trans_app', 'trans_lang', 'trans_phrase_id'], explode(':', $keys));
		}
		$extra_cols = $extra_cols ? explode(',', $extra_cols) : [];
		if (empty($join))
		{
			$join = ' JOIN egw_translations phrases ON phrases.trans_id=en_translations.trans_phrase_id'.
				' LEFT JOIN egw_translations on en_translations.trans_phrase_id=egw_translations.trans_phrase_id'.
					' AND en_translations.trans_app=egw_translations.trans_app'.
					(!empty($keys['trans_id']) ? ' AND egw_translations.trans_id='.(int)$keys['trans_id'] :
						' AND egw_translations.trans_lang='.$this->db->quote($keys['trans_lang']));
			$keys[] = "en_translations.trans_lang='en'";
			foreach(['trans_app', 'trans_phrase_id'] as $key)
			{
				if (!empty($keys[$key]))
				{
					$keys[] = "en_translations.$key=".$this->db->quote($keys[$key], $key === 'trans_phrase_id' ? 'int' : 'varchar');
					unset($keys[$key]);
				}
			}
			// add regular columns prefixed with table-name to not be ambiguous
			$extra_cols = array_merge(array_map(static function($column)
			{
				return self::TABLE.'.'.$column.' AS '.$column;
			}, array_keys($this->db->get_table_definitions(self::APP, self::TABLE)['fd'])), $extra_cols);
			// add phrase
			$extra_cols[] = 'phrases.trans_text AS phrase';
			$extra_cols[] = 'phrases.trans_remark AS phrase_remark';
			// use en ones, otherwise they would be NULL for not (yet) translated phrases!
			$extra_cols[] = 'en_translations.trans_app AS trans_app';
			$extra_cols[] = 'en_translations.trans_app_for AS trans_app_for';
			$extra_cols[] = 'en_translations.trans_phrase_id AS trans_phrase_id';
			$extra_cols[] = 'en_translations.trans_text AS en_text';
			// use queried language, in case not (yet) translated phrases
			$extra_cols[] = $this->db->quote($keys['trans_lang']).' AS trans_lang';
			unset($keys['trans_lang']);
			foreach($keys as $name => $value)
			{
				if (is_string($name))
				{
					$keys[] = $this->db->expression(self::TABLE, self::TABLE.'.', [$name => $value]);
					unset($keys[$name]);
				}
			}
			$this->data = $this->db->select(self::TABLE.' en_translations', $extra_cols, $keys, __LINE__, __FILE__,
				false, '', self::APP, 1, $join)->fetch(Api\Db::FETCH_ASSOC);
			return $this->db2data();
		}
		return parent::read($keys, $extra_cols, $join);
	}

	/**
	 * changes the data from the db-format to your work-format
	 *
	 * It gets called everytime when data is read from the db.
	 * This default implementation only converts the timestamps mentioned in $this->timestamps from server to user time.
	 * You can reimplement it in a derived class like this:
	 *
	 *
	 *
	 * @param array $data =null if given works on that array and returns result, else works on internal data-array
	 * @return array
	 */
	function db2data($data=null)
	{
		if (($intern = !is_array($data)))
		{
			$data =& $this->data;
		}
		// stable row_id independent of phrase being translated or not (trans_id only exists if translated)
		$data['row_id'] = $data['trans_app'].':'.$data['trans_lang'].':'.$data['trans_phrase_id'];

		return parent::db2data($intern ? null : $data);	// important to use null, if $intern!
	}

	/**
	 * Get ID of existing phrase, or create it if it does not currently exist
	 *
	 * @param string $phrase
	 * @return int
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 * @throws Api\Exception\WrongParameter
	 */
	public function phraseId(string $phrase) : int
	{
		$phrase = strtolower(trim($phrase));
		if (!($trans_phrase_id = $this->db->select(self::TABLE, 'trans_id', [
			'trans_app' => null,
			'trans_lang' => null,
			'trans_app_for' => null,
			'trans_phrase_id' => null,
			'trans_text' => $phrase,
		], __LINE__, __FILE__, false, '', self::APP)->fetchColumn()))
		{
			$this->db->insert(self::TABLE, [
				'trans_app' => null,
				'trans_lang' => null,
				'trans_app_for' => null,
				'trans_phrase_id' => null,
				'trans_text' => $phrase,
			], false, __LINE__, __FILE__, self::APP);
			$trans_phrase_id = $this->db->get_last_insert_id(self::TABLE, 'trans_id');
		}
		return $trans_phrase_id;
	}

	/**
	 * Saves a translation and, if necessary, creates phrase and en-translation too
	 *
	 * @param array $keys =null if given $keys are copied to data before saveing => allows a save as
	 * @param string|array $extra_where =null extra where clause, eg. to check an etag, returns true if no affected rows!
	 * @return int|boolean 0 on success, or errno != 0 on error, or true if $extra_where is given and no rows affected
	 */
	public function save($keys = null, $extra_where = null)
	{
		$this->data_merge($keys);

		$this->data['trans_modified'] = $this->now;

		if (empty($this->data['trans_phrase_id']))
		{
			$this->data['trans_phrase_id'] = $this->phraseId($keys['phrase'] ?? $keys['en_text']);
		}

		return parent::save(null, $extra_where);
	}

	/**
	 * Update trans_app_for for all translations of a phrase
	 *
	 * @param int $trans_phrase_id
	 * @param string $trans_app_for
	 * @return int
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function updateTransAppFor(int $trans_phrase_id, string $trans_app_for) : int
	{
		$this->db->update(self::TABLE, [
			'trans_app_for' => $trans_app_for,
		], [
			'trans_phrase_id' => $trans_phrase_id,
		], __LINE__, __FILE__, self::APP);

		return $this->db->affected_rows();
	}

	/**
	 * Deletes a phrase including all it's translations
	 *
	 * @param array|string|int|null $keys int trans_id, string with row_id (trans_app:trans_lang:trans_phrase_id)
	 *  or array with col => value pairs to characterise the rows to delete, null to delete row in $this->data
	 * @param boolean $only_return_query =false * NOT supported, but required by PHP 8 *
	 * @return int affected rows, should be 1 if ok, 0 if an error
	 */
	function delete($keys=null, $only_return_query=false)
	{
		if ($keys && !is_array($keys))
		{
			$keys = is_numeric($keys) ? ['trans_id' => $keys] : array_combine(['trans_app', 'trans_lang', 'trans_phrase_id'], explode(':', $keys));
		}
		return parent::delete($keys);
	}

	/**
	 * Scan app for new phrases or check existing ones: phrases not in app's or common translations
	 *
	 * @param string $app
	 * @param null|string|string[] $row_ids row-ids to check and return their occurrences or not found
	 * @return array $phrase => ["app" => $trans_app_for, "occurrences" => [$file => [...$lines]]]
	 */
	function scanApp(string $app, $row_ids=null)
	{
		if (is_string($row_ids))
		{
			$row_ids = $row_ids ? explode(',', $row_ids) : null;
		}
		$phrases = SearchSources::searchApp($app, $this->langfile_root);
		uksort($phrases, 'strnatcasecmp');

		$existing = [];
		foreach($this->db->select(self::TABLE, 'DISTINCT '.self::TABLE.'.trans_text AS phrase,en_translations.trans_text AS en_text', array_merge([
			self::TABLE.'.trans_phrase_id IS NULL',
			"en_translations.trans_app IN ('api', ".$this->db->quote($app).")",
		], !empty($row_ids) ? [
			$this->db->expression(self::TABLE, 'en_translations.', ['trans_phrase_id' => array_map(static function($row_id) {
				return explode(':', $row_id)[2];
			}, $row_ids)])
		] : []), __LINE__, __FILE__, false, 'ORDER BY phrase', self::APP, 0,
			' JOIN '.self::TABLE." en_translations ON en_translations.trans_lang='en' AND en_translations.trans_phrase_id=".self::TABLE.'.trans_id') as $row)
		{
			$existing[$row['phrase']] = $row['en_text'];
		}
		if ($row_ids)
		{
			$result = array_map(static function($phrase) use ($phrases)
			{
				$found = array_filter($phrases, function($text) use ($phrase) {
					return $phrase === strtolower(trim($text));
				}, ARRAY_FILTER_USE_KEY);
				return ['phrase' => $phrase]+($found ? array_pop($found) : []);
			}, array_keys($existing));
		}
		else
		{
			$result = array_filter($phrases, static function($phrase) use ($existing)
			{
				return !(isset($existing[strtolower(trim($phrase))]) || strlen($phrase) < 2);
			}, ARRAY_FILTER_USE_KEY);
		}
		return $result;
	}

	/**
	 * Get Link to Github repos
	 *
	 * @param string $app
	 * @param string $file
	 * @param int $line
	 * @return string
	 */
	function githubLink(string $app, string $file, ?int $line=null)
	{
		static $app2repo = [
			'stylite'  => 'https://github.com/EGroupwareGmbH/epl/tree/master',
			'esyncpro' => 'https://github.com/EGroupwareGmbH/esyncpro/tree/master',
			'policy'   => 'https://github.com/EGroupwareGmbH/policy/tree/master',
			'webauthn' => 'https://github.com/EGroupwareGmbH/webautnn/tree/master',
			'kanban'   => 'https://github.com/EGroupwareGmbH/kanban/tree/master',
			'smallpart'=> 'https://github.com/EGroupware/smallpart/blob/master/'
		];
		static $main_apps = [
			'addressbook', 'admin', 'api', 'calendar', 'filemanager', 'home', 'importexport', 'infolog', 'kdots',
			'mail', 'notifications', 'pixelegg', 'preferences', 'resources', 'setup', 'timesheet'
		];
		if (isset($app2repo[$app]))
		{
			$url = $app2repo[$app].'/'.$file;
		}
		elseif (in_array($app, $main_apps))
		{
			$url = 'https://github.com/EGroupware/egroupware/tree/master/'.$app.'/'.$file;
		}
		else
		{
			$url = 'https://github.com/EGroupware/'.$app.'/tree/master/'.$file;
		}
		if ($line)
		{
			$url .= '#L'.$line;
		}
		return $url;
	}

	/**
	 * Get trans_app_for values for a given $app
	 *
	 * @param string $app
	 * @param string|null $trans_app_for =null
	 * @return string[] app-name => label
	 */
	function transAppFor(string $app, ?string $trans_app_for=null)
	{
		// api is "common" by default, and never "api"
		$apps = !empty($app) && !in_array($app, ['api', 'phpgwapi']) ? [
			$app => $app,
		] : [];
		// if we have a different trans_app_for, add it
		if (!empty($trans_app_for) && $trans_app_for !== $app)
		{
			$apps[$trans_app_for] = $trans_app_for;
		}
		// setup only uses setup, as it does NOT load other translations
		if ($app !== 'setup')
		{
			$apps += [
				'common'      => 'All applications',
				'login'       => 'login',
				'admin'       => 'admin',
				'preferences' => 'preferences',
			];
		}
		// for EPL functions we might need all apps
		if ($app === 'stylite')
		{
			foreach($GLOBALS['egw_info']['apps'] as $app_name => $info)
			{
				if (!in_array($app_name, ['api', 'phpgwapi', 'setup']))
				{
					$apps[$app_name] = $app_name;
				}
			}
		}
		return $apps;
	}
}