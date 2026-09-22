<?php
/* Copyright (C) 2026  modMedRecord contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \defgroup   medrecord     Module MedicalRecord
 *  \brief      Outpatient medical records (TCM + western) for the clinic suite.
 *
 *  \file       htdocs/custom/medrecord/core/modules/modMedRecord.class.php
 *  \ingroup    medrecord
 *  \brief      Description and activation file for module MedicalRecord
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module MedicalRecord
 */
class modMedRecord extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Healthcare module family: 501600 + 10 per module (patient spec §7)
		$this->numero = 501610;

		$this->rights_class = 'medrecord';

		$this->family = "crm";
		$this->module_position = '92';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "ModuleMedRecordDesc";
		$this->descriptionlong = "ModuleMedRecordDescLong";

		$this->editor_name = 'modMedRecord';
		$this->editor_url = 'https://github.com/kongzong/dolibarr-modmedrecord';

		$this->version = '0.1.3';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'fa-notes-medical';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			// modPrescription (and later modules) inject buttons/summaries into the record card
			'hooks' => array('medrecordcard'),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array("/medrecord/temp");

		$this->config_page_url = array("setup.php@medrecord");

		$this->hidden = getDolGlobalInt('MODULE_MEDRECORD_DISABLED');
		// patient >= 0.1.1 provides the patient tab hook, picker and summary
		$this->depends = array('modPatient');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("medrecord@medrecord");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Retention years (regulation: >= 15 for institution-kept outpatient records)
		// and the post-signature edit window (0 = locked at signature). Spec §3.1.
		$this->const = array(
			0 => array('MEDRECORD_RETENTION_YEARS', 'chaine', '15', 'Minimum retention of medical records in years (display/validation only, never deletes)', 0, 'current', 1),
			1 => array('MEDRECORD_SIGN_LOCK_HOURS', 'chaine', '0', 'Hours after signature during which the signing doctor may still edit (0 = locked)', 0, 'current', 1),
		);

		if (!isModEnabled("medrecord")) {
			$conf->medrecord = new stdClass();
			$conf->medrecord->enabled = 0;
		}

		// "Medical records" tab on the patient card (patient 0.1.1 complete_head_from_modules('patient'))
		$this->tabs = array();
		$this->tabs[] = array('data' => 'patient:+medrecord:MedRecordTab:medrecord@medrecord:$user->hasRight(\'medrecord\', \'read\'):/medrecord/patient_tab.php?id=__ID__');

		// Dictionaries: ICD-10 (national clinical edition structure), TCM disease, TCM syndrome (spec §3.1)
		$langs->load('medrecord@medrecord');
		$this->dictionaries = array(
			'langs' => 'medrecord@medrecord',
			'tabname' => array('c_medrecord_icd10', 'c_medrecord_tcm_disease', 'c_medrecord_tcm_syndrome'),
			'tablib' => array('MedRecordDictIcd10', 'MedRecordDictTcmDisease', 'MedRecordDictTcmSyndrome'),
			'tabsql' => array(
				'SELECT f.rowid as rowid, f.code, f.label, f.extra_code, f.chapter, f.active FROM '.MAIN_DB_PREFIX.'c_medrecord_icd10 as f',
				'SELECT f.rowid as rowid, f.code, f.label, f.pos, f.active FROM '.MAIN_DB_PREFIX.'c_medrecord_tcm_disease as f',
				'SELECT f.rowid as rowid, f.code, f.label, f.pos, f.active FROM '.MAIN_DB_PREFIX.'c_medrecord_tcm_syndrome as f',
			),
			'tabsqlsort' => array('code ASC', 'pos ASC, label ASC', 'pos ASC, label ASC'),
			'tabfield' => array('code,label,extra_code,chapter', 'code,label,pos', 'code,label,pos'),
			'tabfieldvalue' => array('code,label,extra_code,chapter', 'code,label,pos', 'code,label,pos'),
			'tabfieldinsert' => array('code,label,extra_code,chapter', 'code,label,pos', 'code,label,pos'),
			'tabrowid' => array('rowid', 'rowid', 'rowid'),
			'tabcond' => array(isModEnabled('medrecord'), isModEnabled('medrecord'), isModEnabled('medrecord')),
			'tabhelp' => array(
				array('code' => $langs->trans('MedRecordIcd10CodeHelp'), 'extra_code' => $langs->trans('MedRecordIcd10ExtraHelp')),
				array('code' => $langs->trans('MedRecordDictCodeHelp')),
				array('code' => $langs->trans('MedRecordDictCodeHelp')),
			),
		);

		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions: one-level form, ids 50161011/21/31/41/51 (spec §9)
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero . 11;
		$this->rights[$r][1] = 'MedRecordPermRead';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . 21;
		$this->rights[$r][1] = 'MedRecordPermWrite';
		$this->rights[$r][4] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . 31;
		$this->rights[$r][1] = 'MedRecordPermSign';
		$this->rights[$r][4] = 'sign';
		$r++;
		$this->rights[$r][0] = $this->numero . 41;
		$this->rights[$r][1] = 'MedRecordPermVoid';
		$this->rights[$r][4] = 'void';
		$r++;
		$this->rights[$r][0] = $this->numero . 51;
		$this->rights[$r][1] = 'MedRecordPermAdmin';
		$this->rights[$r][4] = 'admin';
		$r++;

		// Left menu under the shared "Clinic" top menu owned by modPatient
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'MedRecordList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'medrecord_list',
			'url' => '/medrecord/list.php',
			'langs' => 'medrecord@medrecord',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("medrecord")',
			'perms' => '$user->hasRight("medrecord", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=medrecord_list',
			'type' => 'left',
			'titre' => 'MedRecordNew',
			'mainmenu' => 'clinic',
			'leftmenu' => 'medrecord_new',
			'url' => '/medrecord/card.php?action=create',
			'langs' => 'medrecord@medrecord',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("medrecord")',
			'perms' => '$user->hasRight("medrecord", "write")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *  Creates tables, dictionaries and seed rows (idempotent), registers
	 *  constants/permissions/menus/tabs/hooks.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/medrecord/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Removes constants, permissions, menus, tabs and hooks only. Medical
	 *	records, diagnoses, the sequence table and dictionaries are kept
	 *	(spec §5.1: records are never deleted).
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
