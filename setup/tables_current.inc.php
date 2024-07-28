<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package developer
 * @subpackage setup
 */


$phpgw_baseline = array(
	'egw_translations' => array(
		'fd' => array(
			'trans_id' => array('type' => 'auto','nullable' => False),
			'trans_phrase_id' => array('type' => 'int','precision' => '4','comment' => 'NULL for phrase or trans_id of phrase'),
			'trans_app' => array('type' => 'ascii','precision' => '64','comment' => 'app containing the translation'),
			'trans_lang' => array('type' => 'ascii','precision' => '5','comment' => 'lang-code'),
			'trans_app_for' => array('type' => 'ascii','precision' => '64','comment' => 'app translation is for'),
			'trans_text' => array('type' => 'varchar','precision' => '1024','nullable' => False,'comment' => 'translation or phrase'),
			'trans_remark' => array('type' => 'varchar','precision' => '1024','comment' => 'remark to phrase or translation'),
			'trans_modified' => array('type' => 'timestamp','nullable' => False,'default'=>'current_timestamp','comment' => 'modification time')
		),
		'pk' => array('trans_id'),
		'fk' => array(),
		'ix' => array('trans_phrase_id'),
		'uc' => array(array('trans_app','trans_lang','trans_phrase_id'))
	)
);