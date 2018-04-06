<?php
/**
 * Copyright Sangoma Technologies, Inc 2018
 */
namespace FreePBX\modules\Backup\Handlers;
use FreePBX\modules\Backup\Modules as Module;

class Restore{
	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \InvalidArgumentException('Not given a BMO Object');
		}
		$this->FreePBX = $freepbx;
		$this->Backup = $freepbx->Backup;
		$webrootpath = $this->FreePBX->Config->get('AMPWEBROOT');
		$webrootpath = (isset($webrootpath) && !empty($webrootpath))?$webrootpath:'/var/www/html';
		define('WEBROOT', $webrootpath);
		define('BACKUPTMPDIR','/var/spool/asterisk/tmp');
	}
	public function process($backupFile, $jobid) {
		$this->Backup->fs->Remove(BACKUPTMPDIR);
		$phar = new \PharData($backupFile);
		$phar->extractTo(BACKUPTMPDIR);
		$errors = [];
		$warnings = [];
		$mods = $this->getModules();
		$this->Backup->log($jobid,_("Running pre restore hooks"));
		$this->preHooks($jobid);
		foreach($mods as $mod) {
			$modjson = BACKUPTMPDIR . '/modulejson/' . ucfirst($mod['rawname']) . '.json';
			if(!file_exists($modjson)){
				$errors[] = sprintf(_("Could not find a manifest for %s, skipping"),$mod['name']);
				continue;
			}
			$moddata = json_decode(file_get_contents($modjson), true);
			$restore = new Module\Restore($this->Backup->FreePBX, $moddata);
			$depsOk = $this->Backup->processDependencies($restore->getDependencies());
			if(!$depsOk){
				$errors[] = printf(_("Dependencies not resolved for %s Skipped"),$mod['name']);
				continue;
			}
			\modgettext::push_textdomain($mod['rawname']);
			$this->Backup->log($jobid,sprintf(_("Running restore process for %s"),$mod['name']));
			$this->Backup->log($jobid,sprintf(_("Resetting the data for %s, this may take a moment"),$mod['name']));
			$this->Backup->mf->uninstall($mod['rawname'],true);
			$this->Backup->mf->install($mod['rawname'],true);
			$class = sprintf('\\FreePBX\\modules\\%s\\Restore',ucfirst($mod['rawname']));
			$class = new $class($restore,$this->Backup->FreePBX);
			$class->runRestore($jobid);
			\modgettext::pop_textdomain();
		}
		$this->Backup->log($jobid,_("Running post restore hooks"));
		$this->postHooks($jobid);
		$this->Backup->fs->remove(BACKUPTMPDIR);
		return $errors;
	}
	/**
	 * Get a list of modules that implement the restore method
	 * @return array list of modules
	 */
	public function getModules($force = false){
		//Cache
		if(isset($this->restoreMods) && !empty($this->restoreMods) && !$force) {
			return $this->restoreMods;
		}
		//All modules impliment the "backup" method so it is a horrible way to know
		//which modules are valid. With the autploader we can do this magic :)
		$amodules = $this->FreePBX->Modules->getActiveModules();
		$validmods = [];
		foreach ($amodules as $module) {
			$bufile = WEBROOT . '/admin/modules/' . $module['rawname'].'/Restore.php';
			if(file_exists($bufile)){
				$validmods[] = $module;
			}
		}
		return $validmods;
	}
	public function preHooks($transactionId = ''){
		$this->FreePBX->Hooks->processHooks($transactionId);
	}
	public function postHooks($transactionId=''){
		$this->FreePBX->Hooks->processHooks($transactionId);
	}

}