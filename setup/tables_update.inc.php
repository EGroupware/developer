<?php
/**
 * EGroupware - Developer setup
 *
 * @link http://www.egroupware.org
 * @package developer
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

use EGroupware\Api;

/**
 * Bump version to 26.1
 *
 * @return string
 */
function developer_upgrade23_1()
{
	return $GLOBALS['setup_info']['developer']['currentver'] = '26.1';
}