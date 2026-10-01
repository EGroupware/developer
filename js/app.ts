/**
 * EGroupware - Developer App
 *
 * @link: https://www.egroupware.org
 * @package developer
 * @author Ralf Becker <rb-At-egroupware.org>
 * @copyright (c) 2024 by Ralf Becker <rb-At-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

import { EgwApp } from '../../api/js/jsapi/egw_app';
import type {EgwFrameworkApp, FilterInfo} from "../../kdots/js/EgwFrameworkApp";
import type {Et2ButtonToggle} from "../../api/js/etemplate/Et2Button/Et2ButtonToggle";
// egw/app are ambient globals (declare global {} in egw_global.d.ts, unconditionally included
// via tsconfig's "**/*.d.ts") - no import needed or possible.

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
	 * Translating into the user's own language is the default, it is not a filter
	 *
	 * @param filterValues
	 * @param fwApp
	 */
	getFilterInfo(filterValues : { [id : string] : any }, fwApp : EgwFrameworkApp) : FilterInfo
	{
		const values = {...(filterValues ?? {})};
		values.col_filter = {...(values.col_filter ?? {})};
		if(values.filter == this.egw.preference('lang', 'common'))
		{
			delete values.filter;
		}
		return fwApp.filterInfo(values);
	}

	/**
	 * Show only untranslated has been clicked
	 */
	toggleUntranslated(_ev : Event, _widget : Et2ButtonToggle)
	{
		this.nm && this.nm.applyFilters({filter2: _widget.value ? 'untranslated' : ''});
	}

	/**
	 * Check if any NM filter or search in app-toolbar needs to be updated to reflect NM internal state
	 *
	 * @param app_toolbar
	 * @param id
	 * @param value
	 */
	checkNmFilterChanged(app_toolbar, id : string, value : string)
	{
		super.checkNmFilterChanged(app_toolbar, id, value);

		if (id === 'filter2')
		{
			const untranslated_toggle = this.et2.getWidgetById('untranslated');
			if (untranslated_toggle && untranslated_toggle.value != (value === 'untranslated')) {
				untranslated_toggle.value = value === 'untranslated';
			}
		}
	}
}

app.classes.developer = DeveloperApp;