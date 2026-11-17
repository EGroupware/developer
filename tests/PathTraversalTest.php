<?php
/**
 * Regression tests for path-traversal hardening in Langfiles::langPath() and
 * SearchSources::searchApp()
 *
 * @link https://www.egroupware.org
 * @package developer
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Developer;

use EGroupware\Api;
use EGroupware\Api\LoggedInTest;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * Regression tests for path hardening in Langfiles::langPath() and SearchSources::searchApp():
 * both now sanitize their "app name" input before building a filesystem path from it.
 */
class PathTraversalTest extends LoggedInTest
{
	public function testLangPathConfinesAppAndLangToOwnSegment()
	{
		$bo = new Langfiles();
		$path = $bo->langPath('..', 'en');

		$this->assertSame($bo->langfile_root.'/!../lang/egw_en.lang', $path,
			'langPath() must neutralize a ".." $app into a single safe segment, not let it escape langfile_root');
	}

	public function testSearchAppDoesNotEscapeRoot()
	{
		$tmp = sys_get_temp_dir().'/DevSearchSourcesTraversalTest-'.uniqid();
		mkdir($tmp.'/root/realapp/inc', 0777, true);
		mkdir($tmp.'/secret/inc', 0777, true);
		file_put_contents($tmp.'/root/realapp/inc/class.inside.inc.php', "<?php\nlang('INSIDE_PHRASE_MARKER');\n");
		file_put_contents($tmp.'/secret/inc/class.outside.inc.php', "<?php\nlang('OUTSIDE_SECRET_MARKER');\n");

		try
		{
			$phrases = SearchSources::searchApp('../secret', $tmp.'/root');

			$this->assertArrayNotHasKey('OUTSIDE_SECRET_MARKER', $phrases,
				'searchApp() must not resolve a ".."-laden $app outside its root directory');
		}
		finally
		{
			foreach ([
				$tmp.'/root/realapp/inc/class.inside.inc.php',
				$tmp.'/secret/inc/class.outside.inc.php',
			] as $f) @unlink($f);
			foreach ([
				$tmp.'/root/realapp/inc', $tmp.'/root/realapp', $tmp.'/root',
				$tmp.'/secret/inc', $tmp.'/secret', $tmp,
			] as $d) @rmdir($d);
		}
	}
}
