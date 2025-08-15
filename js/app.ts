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
import type {Et2ButtonToggle} from "../../api/js/etemplate/Et2Button/Et2ButtonToggle";
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {et2_nextmatch} from "../../api/js/etemplate/et2_extension_nextmatch";

class DeveloperApp extends EgwApp
{
	// app name
	readonly appname = 'developer';

	protected nm : et2_nextmatch;
	/**
	 * app js initialization stage
	 */
	constructor(appname: string)
	{
		super(appname);

		this.nmFilterChange = this.nmFilterChange.bind(this);
	}

	destroy(_app)
	{
		super.destroy(_app);

		if (this.nm && this.nm.getDOMNode())
		{
			this.nm.getDOMNode().removeEventListener('et2-filter', this.nmFilterChange);
		}
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

		switch(name)
		{
			case "developer.translations.index":
				this.nm = this.et2.getWidgetById('nm');
				this.nm.getDOMNode().addEventListener('et2-filter', this.nmFilterChange);
				const untranslated_toggle = this.et2.getWidgetById('nm[untranslated]');
				if (untranslated_toggle) {
					window.setTimeout(() => {
						untranslated_toggle.value = this.et2.getArrayMgr('content').getEntry('nm[filter2]') === 'untranslated';
					}, 100);
				}
				break;
		}
	}

	/**
	 * Keep untranslated toggle and app-selector in sync with NM / filter thingy
	 *
	 * @param _ev : Event
	 */
	nmFilterChange(_ev : Event)
	{
		const app_change = this.et2.getWidgetById('nm[cat_id]');
		if (app_change && app_change.value != _ev.detail.activeFilters.cat_id)
		{
			app_change.value = _ev.detail.activeFilters.cat_id;
		}
		const untranslated_toggle = this.et2.getWidgetById('nm[untranslated]');
		if (untranslated_toggle && (untranslated_toggle.value === 'untranslated') != (_ev.detail.activeFilters.filter2 === 'untranslated')) {
			untranslated_toggle.value = _ev.detail.activeFilters.filter2 === 'untranslated';
		}
	}

	/**
	 * Show only untranslated has been clicked
	 */
	toggleUntranslated(_ev : Event, _widget : Et2ButtonToggle)
	{
		this.nm && this.nm.applyFilters({filter2: _widget.value ? 'untranslated' : ''});
	}

	/**
	 * Propagate app-selection to NM and filter thingy
	 *
	 * @param _ev
	 * @param _widget
	 */
	changeApp(_ev : Event, _widget : Et2Select)
	{
		this.nm && this.nm.applyFilters({cat_id: _widget.value});
	}
}

app.classes.developer = DeveloperApp;