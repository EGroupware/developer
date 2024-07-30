/**
 * EGroupware - Developer App
 *
 * @link: https://www.egroupware.org
 * @package developer
 * @author Ralf Becker <rb-At-egroupware.org>
 * @copyright (c) 2024 by Ralf Becker <rb-At-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

import {app} from "../../api/js/jsapi/egw_global";
import { EgwApp } from '../../api/js/jsapi/egw_app';

class DeveloperApp extends EgwApp
{
	// app name
	readonly appname = 'developer';

	/**
	 * app js initialization stage
	 */
	constructor(appname: string)
	{
		super(appname);
	}

	/**
	 * et2 object is ready to use
	 *
	 * @param {object} et2 object
	 * @param {string} name template name et2_ready is called for eg. "example.edit"
	 */
	et2_ready(et2,name)
	{
		// call parent
		super.et2_ready.apply(this, arguments);
	}
}

app.classes.example = DeveloperApp;