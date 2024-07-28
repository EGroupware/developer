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
			throw new \Exception('Not yet implemented ;)');
			switch($button)
			{
				case 'save':
				case 'apply':
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
					if (!$this->bo->delete(['row_id' => $content['row_id']]))
					{
						Api\Framework::message(lang('Error deleting entry!'));
					}
					else
					{
						Api\Framework::refresh_opener(lang('Entry deleted.'),
							self::APP, $content['row_id'], 'delete');

						Api\Framework::window_close();	// does NOT return
					}
			}
		}
		$readonlys = [
			'button[delete]' => empty($content['trans_id']),
		];
		$sel_options = [
			'trans_app_for' => [
				$content['trans_app'] => $content['trans_app'],
				'common'      => 'common',
				'login'       => 'login',
				'admin'       => 'admin',
				'preferences' => 'preferences'
			],
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
		Api\Cache::setSession(self::class, 'state', $query);

		$query['col_filter']['trans_app'] = $query['cat_id'];
		$query['col_filter']['trans_lang'] = $query['filter'];
		switch ($query['filter2'])
		{
			case 'untranslated':
				$query['col_filter'][] = Langfiles::TABLE.'.trans_text IS NULL';
				break;
		}

		$total = $this->bo->get_rows($query, $rows, $readonlys);
		foreach($rows as &$row)
		{
			if (empty($row['trans_text']))
			{
				$row['class'] = 'untranslated';
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
					'placeholder_actions' => ['add']
				],
			];
			$content['nm']['actions'] = $this->get_actions();
		}
		elseif(!empty($content['nm']['action']) || !empty($content['nm']['save']))
		{
			try {
				Api\Framework::message($this->action($content['nm']['action'] ?: 'save',
					$content['nm']['selected'], $content['nm']['select_all']));
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
			'delete' => [
				'caption' => 'Delete',
				'confirm' => 'Delete this translation',
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
	 * @returns tring with success message
	 * @throws Api\Exception\AssertionFailed
	 */
	protected function action($action, $selected, $select_all)
	{
		switch ($action)
		{
			case 'save':	// always save "en" too, in case new phrases were added
			$state = Api\Cache::getSession(self::class, 'state');
				$this->bo->exportLangFiles($state['cat_id'], array_unique("en", $state['filter']));
				break;

			default:
				throw new Api\Exception\AssertionFailed('To be implemented ;)');
		}
	}
}