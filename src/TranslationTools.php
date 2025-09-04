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
use Zend\Diactoros\Response\JsonResponse;

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
		'scan'  => true,
	];

	/**
	 * Instance of our business object
	 *
	 * @var Langfiles
	 */
	protected $bo;
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->bo = new Langfiles();
	}

	/**
	 * Edit a host
	 *
	 * @param ?array $content =null
	 */
	public function edit(?array $content=null)
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
							'trans_text' => trim($content['en_text'] ?: $content['phrase']),
							'trans_lang' => 'en',
						]);
					}
					elseif ($content['old_trans_app_for'] !== $content['trans_app_for'])
					{
						$this->bo->updateTransAppFor($content['trans_phrase_id'], $content['trans_app_for']);
					}
					if (!empty($content['trans_text']))
					{
						$content['trans_text'] = trim($content['trans_text']);
						$this->bo->init($content);
						if (!$this->bo->save($content))
						{
							Api\Framework::refresh_opener(
								($content['old_trans_app_for'] === $content['trans_app_for'] ? lang('Entry saved.') :
									' '.lang('Remember to %1, as application has been changed!', '['.lang('Save all').']')),
								self::APP, $this->bo->data['row_id'],
								empty($content['row_id']) ? 'add' : 'edit', null, null, null,
								$content['old_trans_app_for'] !== $content['trans_app_for'] ? 'info' : 'success');

							$content = array_merge($content, $this->bo->data);
							Api\Framework::message(lang('Entry saved.'));
						}
						else
						{
							Api\Framework::message(lang('Error storing entry!'));
							unset($button);
						}
					}
					// delete current lang translation by emptying and saving it
					elseif (!empty($content['trans_id']))
					{
						$this->bo->delete($content['trans_id']);
						Api\Framework::refresh_opener(lang('Entry deleted.'),
							self::APP, $content['row_id'], 'update');   // "update" as we show it again as untranslated
						unset($content['trans_id']);
						Api\Framework::message(lang('Entry deleted.'));
					}
					if ($button === 'save')
					{
						Api\Framework::window_close();	// does NOT return
					}
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
			'en_text' => !empty($content['trans_phrase_id']),
			'phrase' => !empty($content['trans_phrase_id']),
		];
		$sel_options = [
			'trans_app_for' => $this->bo->transAppFor($content['trans_app'], $content['trans_app_for']),
		];
		$tmpl = new Api\Etemplate('developer.translations.edit');
		$tmpl->exec(self::APP.'.'.self::class.'.edit', $content, $sel_options, $readonlys, [
			'old_trans_app_for' => $content['trans_app_for'],
		]+$content, 2);
	}

	/**
	 * Fetch rows to display
	 *
	 * @param array $query
	 * @param array& $rows =null
	 * @param array& $readonlys =null
	 */
	public function get_rows(&$_query, array &$rows=null, array &$readonlys=null, $id_only=false)
	{
		$query = $_query;

		// only for real user-action, not e.g. refresh
		if (empty($query['csv_export']) && empty($query['col_filter']['row_id'][0]) && !$id_only)
		{
			// load lang-files, if app or lang changed (always load "en" too, as we show it as source)
			$state = Api\Cache::getSession(self::class, 'state');
			if (!empty($query['cat_id']) && $query['cat_id'] !== ($state['cat_id']??null) ||
				$query['filter'] !== ($state['filter']??null))
			{
				$this->bo->importLangFiles($query['cat_id'], array_unique(['en', $query['filter']]));
			}
			Api\Cache::setSession(self::class, 'state', $query);

			// store last used app in preferences, if changed
			if ($query['cat_id'] !== ($GLOBALS['egw_info']['user']['preferences']['developer']['last_app']??''))
			{
				$prefs = new Api\Preferences();
				$prefs->read_repository();
				$prefs->add(self::APP, 'last_app', $GLOBALS['egw_info']['user']['preferences']['developer']['last_app']=$query['cat_id']);
				$prefs->save_repository();
			}
		}

		if (empty($query['col_filter']['row_id'][0]))
		{
			$query['col_filter']['trans_app'] = $query['cat_id'];
			$query['col_filter']['trans_lang'] = $query['filter'];
			switch ($query['filter2'])
			{
				case 'untranslated':
					$query['col_filter'][] = Langfiles::TABLE.'.trans_text IS NULL';
					break;
				case 'unsaved':
					if (!empty($query['cat_id']))
					{
						$query['col_filter'][] = Langfiles::TABLE.'.trans_modified > '.$GLOBALS['egw']->db->quote($this->bo->mtimeLangFile($query['cat_id'], $query['filter']), 'timestamp');
					}
					else
					{
						Api\Json\Response::get()->message(lang('"%1" filter only available, if an application is selected.', lang('untranslated')));
						$_query['filter2'] = '';
					}
					break;
			}
		}

		$total = $this->bo->get_rows($query, $rows, $readonlys);
		foreach($rows as &$row)
		{
			if (empty($row['trans_text']))
			{
				$row['class'] = 'untranslated';
			}
			elseif ($row['trans_modified'] > $this->bo->mtimeLangFile($row['trans_app'], $query['filter']))
			{
				$row['class'] = 'unsaved';
			}
			if ($row['trans_app_for'] === 'common')
			{
				$row['trans_app_for'] = ''; // to translate by emptyLabel to "All applications"
			}
		}
		return $total;
	}

	/**
	 * Index
	 *
	 * @param ?array $content =null
	 */
	public function index(?array $content=null)
	{
		if (!is_array($content) || empty($content['nm']))
		{
			// redirect to last active tool, unless $_GET[force] is given, as in sidebox menu
			if (empty($_GET['force']) && ($menuaction = Api\Cache::getSession(self::APP, 'active')) &&
				!str_starts_with($menuaction['menuaction'], self::APP.'.'.self::class.'.'))
			{
				Api\Framework::redirect_link('/index.php', $menuaction, self::APP);
			}
			Api\Cache::setSession(self::APP, 'active', [
				'menuaction' => self::APP.'.'.self::class.'.index',
				'ajax' => 'true',
			]);

			$content = [
				'nm' => Api\Cache::getSession(self::class, 'state') ?:
				[
					'get_rows'       =>	self::APP.'.'.self::class.'.get_rows',
					'cat_id'         => $GLOBALS['egw_info']['user']['preferences']['developer']['last_app'] ?? '',
					'filter'         => $GLOBALS['egw_info']['user']['preferences']['common']['lang'],
					'filter2'        => '',
					'cat_is_select'  => true,
					'order'          =>	'en_text',// IO name of the column to sort after (optional for the sortheaders)
					'sort'           =>	'ASC',// IO direction of the sort: 'ASC' or 'DESC'
					'row_id'         => 'row_id',
					'row_modified'   => 'trans_modified',
					'default_cols'   => '!trans_id,phrase,trans_modified',
					'placeholder_actions' => ['add', 'import'],
				],
			];
			$content['nm']['actions'] = $this->get_actions();
		}
		elseif(!empty($content['nm']['action']) || !empty($content['save']))
		{
			try {
				Api\Framework::message($this->action(key(array_filter($content['save'] ?? [])) ?? $content['nm']['action'],
					$content['nm']['selected'], $content['nm']['select_all']));
				unset($content['nm']['action'], $content['save']);
			}
			catch (\Exception $ex) {
				Api\Framework::message($ex->getMessage(), 'error');
			}
		}
		$sel_options = [
			'cat_id' => [
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
			'trans_app_for' => [    // regular app-names are handled by et2-select-app itself
				'common' => 'All applications',
				'setup' => 'Setup',
			],
		];
		uasort($sel_options['cat_id'], 'strcasecmp');
		$sel_options['cat_id'] = array_merge(['' => 'All applications'], $sel_options['cat_id']);
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
			'scan' => [
				'caption' => 'Scan',
				'icon' => 'view',
				'url' => 'menuaction=developer.'.self::class.'.scan',
				'popup' => '800x800',
				'group' => $group=1,
			],
			'check' => [
				'caption' => 'Check',
				'icon' => 'check',
				'url' => 'menuaction=developer.'.self::class.'.scan&row_id=$id',
				'popup' => '800x800',
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
				'icon' => 'apply',
				'hint' => "Save current language and 'en'",
				'group' => $group,
			],
			'all' => [
				'caption' => 'Save all',
				'icon' => 'apply',
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

			case NULL:  // happens when column-selection changes
				return null;

			default:
				throw new Api\Exception\AssertionFailed(json_encode($action).': To be implemented ;)');
		}
	}

	public function scan(?array $content=null)
	{
		$state = Api\Cache::getSession(self::class, 'state');

		if (!is_array($content))
		{
			$phrases = $this->bo->scanApp($app = $state['cat_id'] ?: 'api', $_GET['row_id']??null);
			$content = [
				'header' => empty($_GET['row_id']) ? 'Scanning for new phrases in' : 'Checking phrases in',
				'app' => $app,
				'new' => array_map(function($data, $phrase)
				{
					return [
						'phrase' => $data['phrase'] ?? $phrase,
						'trans_app_for' => $data['app'],
						'occurrences' => array_map(function($file, $lines)
						{
							[$app, $file] = explode('/', $file, 2);
							return [
								'file' => $file,
								'href' => $this->bo->githubLink($app, $file, $lines[0]),
								'lines' => implode(', ', array_filter($lines)),
							];
						}, array_keys($data['occurrences'] ?? []), $data['occurrences'] ?? []),
					];
				}, $phrases, array_keys($phrases)),
			];
		}
		elseif (!empty($content['button']))
		{
			$button = key($content['button']);
			unset($content['button']);
			switch($button)
			{
				case 'save':
					$added = 0;
					foreach($content['new']['add']??[] as $row => $add)
					{
						if ($add && !empty($data = $content['new'][$row]??[]))
						{
							$this->bo->init([
								'trans_app' => $content['app'],
								'trans_lang' => 'en',
								'trans_phrase_id' => $this->bo->phraseId($data['phrase']),
								'trans_app_for' => $data['trans_app_for'],
								'trans_text' => trim($data['phrase']),
							]);
							$this->bo->save();
							$added++;
						}
					}
					Api\Framework::refresh_opener(lang('%1 phrases added.', $added), self::APP);
					Api\Framework::window_close();
					break;

				case 'delete':
					$deleted = 0;
					foreach($content['new']['add']??[] as $row => $add)
					{
						if ($add && !empty($data = $content['new'][$row]??[]))
						{
							$deleted += $this->bo->delete([
								'trans_app' => $content['app'],
								'trans_phrase_id' => $this->bo->phraseId($data['phrase']),
							]);
						}
					}
					Api\Framework::refresh_opener(lang('%1 translations deleted.', $deleted), self::APP);
					Api\Framework::window_close();
					break;
			}
		}
		$template = new Api\Etemplate('developer.translations.scan');
		$template->exec(self::APP.'.'.self::class.'.scan', $content, [
			'trans_app_for' => $this->bo->transAppFor($content['app']),
		], [
			'button[save]' => !empty($_GET['row_id']),
			'button[delete]' => empty($_GET['row_id']),
		], $content, 2);
	}
}