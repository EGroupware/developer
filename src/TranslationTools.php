<?php
/**
 * EGroupware - Developer - User interface
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

class TranslationTools
{
	const APP = Langfiles::APP;
	/**
	 * Methods callable via menuaction GET parameter
	 *
	 * @var array
	 */
	public $public_functions = [
		'index' => true,
		'edit'  => true,
	];

	/**
	 * Instance of our business object
	 *
	 * @var Langfiles
	 */
	protected $bo;
	
	const REFRESH_INTERVAL = 7200;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->bo = new Langfiles();
		
		/*if (!($refreshed = Api\Cache::getInstance(Bo::APP, 'phrasesRefreshed')) || $refreshed < time()-self::REFRESH_INTERVAL)
		{
			$time = microtime(true);
			$num = $this->bo->importLangFiles();
			$time = number_format(microtime(true) - $time, 1);

			$push = new Api\Json\Push();
			$push->message(lang('Imported total of %1 translations in %2 seconds', $num, $time), 'success');
			
			Api\Cache::setInstance(self::APP, 'phrasesRefreshed', $refreshed=time());
		}*/
	}

	/**
	 * Edit a host
	 *
	 * @param array $content =null
	 */
	public function edit(array $content=null)
	{
		if (!is_array($content))
		{
			if (empty($_GET['row_id']) || !($content = $this->bo->read($_GET['row_id'])))
			{
				$content = $this->bo->init();
				$state = Api\Cache::getSession(self::class, 'state');
				$content['trans_app_for'] = $content['trans_app'] = $state['cat_id'];
				$content['trans_lang'] = $state['filter'];
			}
		}
		else
		{
			$button = key($content['button']);
			unset($content['button']);
			switch($button)
			{
				case 'save':
				case 'apply':
					// for new phrases, save the "en" text too
					if (empty($content['trans_phrase_id']) && !empty($content['en_text'] ?: $content['phrase']) && $content['trans_lang'] !== 'en')
					{
						$this->bo->init($content);
						$this->bo->save([
							'phrase' => strtolower($content['phrase'] ?: $content['en_text']),
							'trans_text' => $content['en_text'] ?: $content['phrase'],
							'trans_lang' => 'en',
						]);
					}
					$this->bo->init($content);
					if (!$this->bo->save($content))
					{
						Api\Framework::refresh_opener(lang('Entry saved.'),
							self::APP, $this->bo->data['row_id'],
							empty($content['row_id']) ? 'add' : 'edit');

						$content = array_merge($content, $this->bo->data);
					}
					else
					{
						Api\Framework::message(lang('Error storing entry!'));
						unset($button);
					}
					if ($button === 'save')
					{
						Api\Framework::window_close();	// does NOT return
					}
					Api\Framework::message(lang('Entry saved.'));
					break;

				case 'delete':
					if (!($deleted=$this->bo->delete(['trans_app' => $content['trans_app'], 'trans_phrase_id' => $content['trans_phrase_id']])))
					{
						Api\Framework::message(lang('Error deleting entry!'));
					}
					else
					{
						Api\Framework::refresh_opener(lang('%1 translations deleted.', $deleted),
							self::APP, $content['row_id'], 'delete');

						Api\Framework::window_close();	// does NOT return
					}
			}
		}
		$readonlys = [
			'button[delete]' => empty($content['trans_id']),
			'en_text' => !empty($content['trans_id']),
		];
		$sel_options = [
			// api is "common" by default, and never "api"
			'trans_app_for' => (empty($content['trans_app']) || !in_array($content['trans_app'], ['api', 'phpgwapi']) ? [
				$content['trans_app'] => $content['trans_app'],
			// setup only uses setup, as it does NOT load other translations
			] : [])+($content['trans_app'] !== 'setup' ? [
				'common'      => 'common',
				'login'       => 'login',
				'admin'       => 'admin',
				'preferences' => 'preferences',
			] : []),
		];
		$tmpl = new Api\Etemplate('developer.translations.edit');
		$tmpl->exec(self::APP.'.'.self::class.'.edit', $content, $sel_options, $readonlys, $content, 2);
	}

	/**
	 * Fetch rows to display
	 *
	 * @param array $query
	 * @param array& $rows =null
	 * @param array& $readonlys =null
	 */
	public function get_rows($query, array &$rows=null, array &$readonlys=null)
	{
		// load lang-files, if app or lang changed (always load "en" too, as we show it as source)
		$state = Api\Cache::getSession(self::class, 'state');
		if (!empty($query['cat_id']) && $query['cat_id'] !== ($state['cat_id']??null) ||
			$query['filter'] !== ($state['filter']??null))
		{
			$this->bo->importLangFiles($query['cat_id'], array_unique(['en', $query['filter']]));
		}
		Api\Cache::setSession(self::class, 'state', $query);

		$query['col_filter']['trans_app'] = $query['cat_id'];
		$query['col_filter']['trans_lang'] = $query['filter'];
		if (!empty($query['cat_id']))
		{
			$mtime_langfile = $this->bo->mtimeLangFile($query['cat_id'], $query['filter']);
		}
		switch ($query['filter2'])
		{
			case 'untranslated':
				$query['col_filter'][] = Langfiles::TABLE.'.trans_text IS NULL';
				break;
			case 'unsaved':
				$query['col_filter'][] = Langfiles::TABLE.'.trans_modified > '.$GLOBALS['egw']->db->quote($mtime_langfile, 'timestamp');
				break;
		}

		$total = $this->bo->get_rows($query, $rows, $readonlys);
		foreach($rows as &$row)
		{
			if (empty($row['trans_text']))
			{
				$row['class'] = 'untranslated';
			}
			elseif ($query['cat_id'] && $row['trans_modified'] > $mtime_langfile)
			{
				$row['class'] = 'unsaved';
			}
		}
		return $total;
	}

	/**
	 * Index
	 *
	 * @param array $content =null
	 */
	public function index(array $content=null)
	{
		if (!is_array($content) || empty($content['nm']))
		{
			$content = [
				'nm' => Api\Cache::getSession(self::class, 'state') ?: [
					'get_rows'       =>	self::APP.'.'.self::class.'.get_rows',
					'cat_id'         => '',
					'filter'         => $GLOBALS['egw_info']['user']['preferences']['common']['lang'],
					'filter2'        => '',
					'cat_is_select'  => true,
					'order'          =>	'phrase',// IO name of the column to sort after (optional for the sortheaders)
					'sort'           =>	'ASC',// IO direction of the sort: 'ASC' or 'DESC'
					'row_id'         => 'row_id',
					'row_modified'   => 'trans_modified',
					'placeholder_actions' => ['add', 'import']
				],
			];
			$content['nm']['actions'] = $this->get_actions();
		}
		elseif(!empty($content['nm']['action']) || !empty($content['nm']['save']))
		{
			try {
				Api\Framework::message($this->action(key(array_filter($content['nm']['save'] ?? [])) ?? $content['nm']['action'],
					$content['nm']['selected'], $content['nm']['select_all']));
				unset($content['nm']['action'], $content['nm']['save']);
			}
			catch (\Exception $ex) {
				Api\Framework::message($ex->getMessage(), 'error');
			}
		}
		$sel_options = [
			'cat_id' => [
					'' => '',
					'setup' => lang('Setup'),]+array_map(static function(array $app)
			{
				return lang($app['name']);
			}, array_filter($GLOBALS['egw_info']['apps'], function($app)
				{
					return file_exists(EGW_SERVER_ROOT.'/'.$app['name'].'/setup/setup.inc.php');
				})),
			'filter' => Api\Translation::get_available_langs(),
			'filter2' => [
				'' => 'All',
				'untranslated' => 'untranslated',
				'unsaved' => 'unsaved',
			],
		];
		uasort($sel_options['cat_id'], 'strcasecmp');
		$sel_options['cat_id'][''] = 'Select Application';
		$sel_options['filter'] = array_combine(array_keys($sel_options['filter']), array_map(static function($language, $lang)
		{
			return $lang.': '.$language;
		}, $sel_options['filter'], array_keys($sel_options['filter'])));

		$tmpl = new Api\Etemplate(self::APP.'.translations.index');
		$tmpl->exec(self::APP.'.'.self::class.'.index', $content, $sel_options, [], ['nm' => $content['nm']]);
	}

	/**
	 * Return actions for cup list
	 *
	 * @param array $cont values for keys license_(nation|year|cat)
	 * @return array
	 */
	protected function get_actions()
	{
		return [
			'edit' => [
				'caption' => 'Edit',
				'allowOnMultiple' => false,
				'url' => 'menuaction=developer.'.self::class.'.edit&row_id=$id',
				'popup' => '800x320',
				'group' => $group=0,
				'default' => true,
			],
			'add' => [
				'caption' => 'Add',
				'url' => 'menuaction=developer.'.self::class.'.edit',
				'popup' => '800x320',
				'group' => $group,
			],
			'import' => [
				'caption' => 'Import all lang-files',
				'icon' => 'import',
				'hint' => 'Takes a while ...',
				'group' => $group=2,
			],
			'current' => [
				'caption' => 'Save',
				'icon' => 'save',
				'hint' => "Save current language and 'en'",
				'group' => $group,
			],
			'all' => [
				'caption' => 'Save all',
				'icon' => 'save',
				'hint' => "Save all languages",
				'group' => $group,
			],
			'delete' => [
				'caption' => 'Delete',
				'confirm' => 'Delete this phrase for all languages?',
				'group' => $group=5,
			],
		];
	}

	/**
	 * Execute action on list
	 *
	 * @param string $action
	 * @param array|int $selected
	 * @param boolean $select_all
	 * @returns string with success message
	 * @throws Api\Exception\AssertionFailed
	 */
	protected function action($action, $selected, $select_all)
	{
		switch ($action)
		{
			case 'import':
				$state = Api\Cache::getSession(self::class, 'state');
				return lang('%1 translations imported.', $this->bo->importLangFiles($state['cat_id'] ?? null));

			case 'current':	// always save "en" too, in case new phrases were added
			case 'all':
				$state = Api\Cache::getSession(self::class, 'state');
				if (empty($state['cat_id']))
				{
					return lang('You need to select an application first!');
				}
				$this->bo->exportLangFiles($state['cat_id'], $langs = $action === 'all' ? null : array_unique(["en", $state['filter']]));
				return lang('Lang-files exported').': '.($langs ? '"'.implode('", "', $langs).'"' : lang('all'));

			case 'delete':
				$deleted = 0;
				foreach ($selected as $id)
				{
					$keys = array_combine(['trans_app', 'trans_lang', 'trans_phrase_id'], explode(':', $id));
					unset($keys['trans_lang']);
					$deleted += $this->bo->delete($keys);
				}
				return lang('%1 translations deleted.', $deleted);

			default:
				throw new Api\Exception\AssertionFailed($action.': To be implemented ;)');
		}
	}
}