<?php
/**
 * Test the ajax endpoint the translation list's context-menu actions now call
 *
 * @link https://www.egroupware.org
 * @package developer
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Developer;

use EGroupware\Api;
use EGroupware\Api\LoggedInTest;

require_once realpath(__DIR__ . '/../../api/tests/LoggedInTest.php');

/**
 * TranslationTools::ajax_action() is a new endpoint: Import, Save, Save all, Move to api and
 * Delete used to submit the whole eTemplate, which rebuilds the list - on a list of every phrase
 * in the installation, that is the most expensive reload in the product.
 *
 * WHAT IS AND IS NOT COVERED
 * Delete is exercised against real rows. Import, Save and Save all rewrite lang-files on disk and
 * Move to api rewrites every language of a phrase, so they are covered only where they decline to
 * run (no application selected) - enough to pin that the endpoint reaches action() and reports
 * back, without a test that edits the source tree.
 *
 * PASS CRITERIA
 * The rows really went away (read back through Langfiles), and the response carries an
 * egw.refresh call - without which the list drops no rows.
 */
class AjaxActionTest extends LoggedInTest
{
	/** @var TranslationTools */
	protected $ui;
	/** @var Langfiles */
	protected $bo;
	/** @var string[] row ids this test created */
	protected $rows = [];

	const APP = 'ajaxactiontest';

	protected function setUp() : void
	{
		Api\Json\Response::get()->initResponseArray();
		$this->ui = new TranslationTools();
		$this->bo = new Langfiles();
		Api\Cache::unsetSession(TranslationTools::class, 'state');
	}

	protected function tearDown() : void
	{
		// by trans_app, so a row the action already deleted - or one it created in another
		// language - cannot leak
		$GLOBALS['egw']->db->delete(Langfiles::TABLE, ['trans_app' => self::APP], __LINE__, __FILE__, 'developer');
		$this->rows = [];
		Api\Cache::unsetSession(TranslationTools::class, 'state');
	}

	/**
	 * A real eTemplate request id, the way the browser sends one along - the endpoint refuses
	 * without it, see Nextmatch::validateExecId().  Writing to the request is what persists it.
	 */
	protected function execId() : string
	{
		$request = \EGroupware\Api\Etemplate\Request::read();
		$id = $request->id();
		$request->content = ['nm' => []];
		unset($request);
		return $id;
	}

	/**
	 * The egw.refresh call the response should carry, or null
	 */
	protected function refreshCall() : ?array
	{
		$response = Api\Json\Response::get();
		$prop = (new \ReflectionClass($response))->getProperty('responseArray');
		$prop->setAccessible(true);
		foreach((array)$prop->getValue($response) as $chunk)
		{
			$chunk = (array)$chunk;
			if (($chunk['type'] ?? null) === 'apply' && (($chunk['data']['func'] ?? null) === 'egw.refresh'))
			{
				return (array)$chunk['data']['parms'];
			}
		}
		return null;
	}

	/**
	 * One phrase in a throw-away app, in the two languages the list treats as a pair
	 *
	 * @return string the row id the context menu would send, trans_app:trans_lang:trans_phrase_id
	 */
	protected function makePhrase(string $phrase) : string
	{
		$phrase_id = crc32($phrase) % 1000000000;	// crc32 can overflow int(11)
		foreach(['en', 'de'] as $lang)
		{
			$GLOBALS['egw']->db->insert(Langfiles::TABLE, [
				'trans_phrase_id' => $phrase_id,
				'trans_app'       => self::APP,
				'trans_lang'      => $lang,
				'trans_app_for'   => self::APP,
				'trans_text'      => $phrase.' ('.$lang.')',
			], false, __LINE__, __FILE__, 'developer');
		}
		return $this->rows[] = self::APP.':en:'.$phrase_id;
	}

	protected function countRows(string $row_id) : int
	{
		[,, $phrase_id] = explode(':', $row_id);
		return (int)$GLOBALS['egw']->db->select(Langfiles::TABLE, 'COUNT(*)',
			['trans_app' => self::APP, 'trans_phrase_id' => $phrase_id],
			__LINE__, __FILE__, false, '', 'developer')->fetchColumn();
	}

	/**
	 * The regression shape: the endpoint has to reach action()'s body and really delete - and
	 * delete drops the phrase in EVERY language, which is what its confirmation promises.
	 */
	public function testDeleteRemovesThePhraseInAllLanguages()
	{
		$row = $this->makePhrase('AjaxActionTest delete');
		$this->assertSame(2, $this->countRows($row), 'fixture was not created');

		$this->ui->ajax_action($this->execId(), 'delete', [$row]);

		$this->assertSame(0, $this->countRows($row),
			'delete must remove the phrase for all languages');
		$this->assertNotNull($this->refreshCall(),
			'the endpoint must answer with egw.refresh, or the list drops no rows');
	}

	/**
	 * Without a valid exec id the endpoint must do nothing at all.
	 */
	public function testABogusExecIdDeletesNothing()
	{
		$row = $this->makePhrase('AjaxActionTest bogus exec id');

		$this->ui->ajax_action('developer_nobody_not-a-real-request-id', 'delete', [$row]);

		$this->assertSame(2, $this->countRows($row), 'a rejected request must not run the action');
		$this->assertNull($this->refreshCall(), 'and must not answer with egw.refresh either');
	}

	/**
	 * A single delete is the only action here that can be a row update.
	 */
	public function testASingleDeleteNamesTheRow()
	{
		$row = $this->makePhrase('AjaxActionTest single');

		$this->ui->ajax_action($this->execId(), 'delete', [$row]);

		$parms = $this->refreshCall();
		$this->assertSame($row, $parms[2]);
		$this->assertSame('delete', $parms[3]);
	}

	/**
	 * Everything else reloads: egw.refresh() takes a single id, and Et2Nextmatch.refresh(id, null)
	 * only defaults its type when that type is undefined - a literal null falls through and
	 * updates nothing.
	 */
	public function testAMultiRowDeleteAsksForAFullReload()
	{
		$first = $this->makePhrase('AjaxActionTest multi 1');
		$second = $this->makePhrase('AjaxActionTest multi 2');

		$this->ui->ajax_action($this->execId(), 'delete', [$first, $second]);

		$parms = $this->refreshCall();
		$this->assertNull($parms[2], 'no single id for a multi-row action');
		$this->assertNull($parms[3], 'and no type, so egw.refresh reloads the list');
	}

	/**
	 * Save reads the application out of the list's saved state, and declines when there is none -
	 * this pins that the endpoint reaches that branch rather than writing lang-files.
	 */
	public function testSaveWithoutAnApplicationDeclines()
	{
		$this->ui->ajax_action($this->execId(), 'current', []);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms, 'the endpoint must still answer');
		$this->assertStringContainsString('application', (string)$parms[0],
			'and say why it did nothing');
		$this->assertNull($parms[2], 'Save is never a single-row update');
	}

	/**
	 * _targetapp must be a real app name: egw.refresh() resolves it before its msg-only
	 * early-return, and a name that is not an app throws in the kdots framework.
	 */
	public function testRefreshNamesTheAppInBothSlots()
	{
		$row = $this->makePhrase('AjaxActionTest refresh args');

		$this->ui->ajax_action($this->execId(), 'delete', [$row]);

		$parms = $this->refreshCall();
		$this->assertSame('developer', $parms[1],
			'developer sends no push, so it cannot use the msg-only sentinel');
		$this->assertSame('developer', $parms[4], 'never the msg-only-push-refresh sentinel');
	}

	/**
	 * An unknown action throws out of action(); the endpoint has to turn that into a message
	 * rather than a 500 the context menu shows nothing for.
	 */
	public function testAnUnknownActionIsReportedAsAnError()
	{
		$this->ui->ajax_action($this->execId(), 'no_such_action', []);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms, 'the endpoint must answer even when action() throws');
		$this->assertSame('error', $parms[7] ?? null);
	}
}
