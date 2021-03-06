<?php 
App::uses('Folder', 'Utility');
App::uses('File', 'Utility');
require_once 'AppShell.php';
class EventShell extends AppShell
{
	public $uses = array('Event', 'Post', 'Attribute', 'Job', 'User', 'Task', 'Whitelist', 'Server', 'Organisation');

	public function doPublish() {
		$id = $this->args[0];
		$this->Event->id = $id;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event'));
		}
		$this->Job->create();
		$data = array(
				'worker' => 'default',
				'job_type' => 'doPublish',
				'job_input' => $id,
				'status' => 0,
				'retries' => 0,
				//'org' => $jobOrg,
				'message' => 'Job created.',
		);
		$this->Job->save($data);
		// update the event and set the from field to the current instance's organisation from the bootstrap. We also need to save id and info for the logs.
		$this->Event->recursive = -1;
		$event = $this->Event->read(null, $id);
		$event['Event']['published'] = 1;
		$fieldList = array('published', 'id', 'info');
		$this->Event->save($event, array('fieldList' => $fieldList));
		// only allow form submit CSRF protection.
		$this->Job->saveField('status', 1);
		$this->Job->saveField('message', 'Job done.');
	}
	
	public function cachexml() {
		$userId = $this->args[0];
		$id = $this->args[1];
		$user = $this->User->getAuthUser($userId);
		$this->Job->id = $id;
		// TEMP: change to passing an options array with the user!!
		$eventIds = $this->Event->fetchEventIds($user);
		$eventCount = count($eventIds);
		$dir = new Folder(APP . 'tmp/cached_exports/xml', true, 0750);
		if ($user['Role']['perm_site_admin']) {
			$file = new File($dir->pwd() . DS . 'misp.xml' . '.ADMIN.xml');
		} else {
			$file = new File($dir->pwd() . DS . 'misp.xml' . '.' . $user['Organisation']['name'] . '.xml');
		}
		App::uses('XMLConverterTool', 'Tools');
		$converter = new XMLConverterTool();
		$file->write('<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<response>');
		if (!empty($eventIds)) {
			foreach ($eventIds as $k => $eventId) {
				$temp = $this->Event->fetchEvent($user, array('eventid' => $eventId['Event']['id']));
				$file->append($converter->event2XML($temp[0], $user['Role']['perm_site_admin']) . PHP_EOL);
				$this->Job->saveField('progress', ($k+1) / $eventCount *100);
			}
		}
		$this->Job->saveField('progress', 100);
		$this->Job->saveField('message', 'Job done.');
		$file->append('<xml_version>' . $this->Event->mispVersion . '</xml_version>');
		$file->append('</response>' . PHP_EOL);
		$file->close();
	}
	
	private function __recursiveEcho($array) {
		$text = "";
		foreach ($array as $k => $v) {
			if (is_array($v)) {
				if (empty($v)) $text .= '<' . $k . '/>';
				else {
					foreach ($v as $element) {
						$text .= '<' . $k . '>';
						$text .= $this->__recursiveEcho($element);
						$text .= '</' . $k . '>';
					}
				}
			} else {
				if ($v === false) $v = 0;
				if ($v === "" || $v === null) $text .= '<' . $k . '/>';
				else {
					$text .= '<' . $k . '>' . $v . '</' . $k . '>';
				}
			}
		}
		return $text;
	}
	
	public function cachehids() {
		$userId = $this->args[0];
		$user = $this->User->getAuthUser($userId);
		$id = $this->args[1];
		$this->Job->id = $id;
		$extra = $this->args[2];
		$this->Job->saveField('progress', 1);
		$rules = $this->Attribute->hids($user, $extra);
		$this->Job->saveField('progress', 80);
		$dir = new Folder(APP . DS . '/tmp/cached_exports/' . $extra, true, 0750);
		if ($user['Role']['perm_site_admin']) {
			$file = new File($dir->pwd() . DS . 'misp.' . $extra . '.ADMIN.txt');
		} else {
			$file = new File($dir->pwd() . DS . 'misp.' . $extra . '.' . $user['Organisation']['name'] . '.txt');
		}
		$file->write('');
		foreach ($rules as $rule) {
			$file->append($rule . PHP_EOL);
		}
		$file->close();
		$this->Job->saveField('progress', '100');
		$this->Job->saveField('message', 'Job done.');
	}
	
	public function cacherpz() {
		$userId = $this->args[0];
		$user = $this->User->getAuthUser($userId);
		$id = $this->args[1];
		$this->Job->id = $id;
		$extra = $this->args[2];
		$this->Job->saveField('progress', 1);
		$eventIds = $this->Attribute->Event->fetchEventIds($user, false, false, false, true);
		$values = array();
		$eventCount = count($eventIds);
		if ($eventCount) {
			foreach ($eventIds as $k => $eventId) {
				$values = array_merge_recursive($values, $this->Attribute->rpz($user, false, $eventId));
				if ($k % 10 == 0) $this->Job->saveField('progress', $k * 80 / $eventCount);
			}
		}
		$this->Job->saveField('progress', 80);
		$dir = new Folder(APP . DS . '/tmp/cached_exports/' . $extra, true, 0750);
		if ($user['Role']['perm_site_admin']) {
			$file = new File($dir->pwd() . DS . 'misp.rpz.ADMIN.txt');
		} else {
			$file = new File($dir->pwd() . DS . 'misp.rpz.' . $user['Organisation']['name'] . '.txt');
		}
		App::uses('RPZExport', 'Export');
		$rpzExport = new RPZExport();
		$rpzSettings = array();
		$lookupData = array('policy', 'walled_garden', 'ns', 'email', 'serial', 'refresh', 'retry', 'expiry', 'minimum_ttl', 'ttl');
		foreach ($lookupData as $v) {
			$tempSetting = Configure::read('Plugin.RPZ_' . $v);
			if (isset($tempSetting)) $rpzSettings[$v] = Configure::read('Plugin.RPZ_' . $v);
			else $rpzSettings[$v] = $this->Server->serverSettings['Plugin']['RPZ_' . $v]['value'];
		}
		$file->write($rpzExport->export($values, $rpzSettings));
		$file->close();
		$this->Job->saveField('progress', '100');
		$this->Job->saveField('message', 'Job done.');
	}
	
	public function cachecsv() {
		$userId = $this->args[0];
		$user = $this->User->getAuthUser($userId);
		$id = $this->args[1];
		$this->Job->id = $id;
		$extra = $this->args[2];
		if ($extra == 'csv_all') $ignore = 1;
		else $ignore = 0;
		// TEMP: change to passing an options array with the user!!
		$eventIds = $this->Event->fetchEventIds($user);
		$eventCount = count($eventIds);
		$attributes = array();
		$dir = new Folder(APP . 'tmp/cached_exports/' . $extra, true, 0750);
		if ($user['Role']['perm_site_admin']) {
			$file = new File($dir->pwd() . DS . 'misp.' . $extra . '.ADMIN.csv');
		} else {
			$file = new File($dir->pwd() . DS . 'misp.' . $extra . '.' . $user['Organisation']['name'] . '.csv');
		}
		$file->write('uuid,event_id,category,type,value,to_ids,date' . PHP_EOL);
		foreach ($eventIds as $k => $eventId) {
			$chunk = "";
			$attributes = $this->Event->csv($user, $eventId['Event']['id'], $ignore);
			$attributes = $this->Whitelist->removeWhitelistedFromArray($attributes, true);
			foreach ($attributes as $attribute) {
				$chunk .= $attribute['Attribute']['uuid'] . ',' . $attribute['Attribute']['event_id'] . ',' . $attribute['Attribute']['category'] . ',' . $attribute['Attribute']['type'] . ',' . $attribute['Attribute']['value'] . ',' . intval($attribute['Attribute']['to_ids']) . ',' . $attribute['Attribute']['timestamp'] . PHP_EOL;
			}
			$file->append($chunk);
			if ($k % 10 == 0) {
				$this->Job->saveField('progress', $k / $eventCount * 80);
			}
		}
		$file->close();
		$this->Job->saveField('progress', '100');
		$this->Job->saveField('message', 'Job done.');
	}
	
	public function cachetext() {
		$userId = $this->args[0];
		$user = $this->User->getAuthUser($userId);
		$id = $this->args[1];
		$this->Job->id = $id;
		$types = array_keys($this->Attribute->typeDefinitions);
		$typeCount = count($types);
		$dir = new Folder(APP . DS . '/tmp/cached_exports/text', true, 0750);
		foreach ($types as $k => $type) {
			$final = $this->Attribute->text($user, $type);
			if ($user['Role']['perm_site_admin']) {
				$file = new File($dir->pwd() . DS . 'misp.text_' . $type . '.ADMIN.txt');
			} else {
				$file = new File($dir->pwd() . DS . 'misp.text_' . $type . '.' . $user['Organisation']['name'] . '.txt');
			}
			$file->write('');
			foreach ($final as $attribute) {
				$file->append($attribute['Attribute']['value'] . PHP_EOL);
			}
			$file->close();
			$this->Job->saveField('progress', $k / $typeCount * 100);
		}
		$this->Job->saveField('progress', 100);
		$this->Job->saveField('message', 'Job done.');
	}
	
	public function cachenids() {
		$userId = $this->args[0];
		$user = $this->User->getAuthUser($userId);
		$id = $this->args[1];
		$this->Job->id = $id;
		$format = $this->args[2];
		$eventIds = array_values($this->Event->fetchEventIds($user, false, false, false, true));
		$eventCount = count($eventIds);
		$dir = new Folder(APP . DS . '/tmp/cached_exports/' . $format, true, 0750);
		if ($user['Role']['perm_site_admin']) {
			$file = new File($dir->pwd() . DS . 'misp.' . $format . '.ADMIN.rules');
		} else {
			$file = new File($dir->pwd() . DS . 'misp.' . $format . '.' . $user['Organisation']['name'] . '.rules');
		}
		$file->write('');
		foreach ($eventIds as $k => $eventId) {
			if ($k == 0) {
				$temp = $this->Attribute->nids($user, $format, $eventId);
			} else {
				$temp = $this->Attribute->nids($user, $format, $eventId, true);
			}
			foreach ($temp as $line) {
				$file->append($line . PHP_EOL);
			}
			if ($k % 10 == 0) {
				$this->Job->saveField('progress', $k / $eventCount * 80);
			}
		}
		$file->close();
		$this->Job->saveField('progress', '100');
		$this->Job->saveField('message', 'Job done.');
	}
	
	public function alertemail() {
		$userId = $this->args[0];
		$processId = $this->args[1];
		$job = $this->Job->read(null, $processId);
		$eventId = $this->args[2];
		$user = $this->User->getAuthUser($userId);
		$result = $this->Event->sendAlertEmail($eventId, $user, $processId);
		$job['Job']['progress'] = 100;
		$job['Job']['message'] = 'Emails sent.';
		$this->Job->save($job);
	}
	
	public function contactemail() {
		$id = $this->args[0];
		$message = $this->args[1];
		$all = $this->args[2];
		$userId = $this->args[3];
		$isSiteAdmin = $this->args[4];
		$processId = $this->args[5];
		$this->Job->id = $processId;
		$user = $this->User->getAuthUser($userId);
		$result = $this->Event->sendContactEmail($id, $message, $all, array('User' => $user), $isSiteAdmin);
		$this->Job->saveField('progress', '100');
		if ($result != true) $this->Job->saveField('message', 'Job done.');
	}

	public function postsemail() {
		$userId = $this->args[0];
		$postId = $this->args[1];
		$eventId = $this->args[2];
		$title = $this->args[3];
		$message = $this->args[4];
		$processId = $this->args[5];
		$this->Job->id = $processId;
		$result = $this->Post->sendPostsEmail($userId, $postId, $eventId, $title, $message);
		$job['Job']['progress'] = 100;
		$job['Job']['message'] = 'Emails sent.';
		$this->Job->save($job);
	}
	
	public function enqueueCaching() {
		$timestamp = $this->args[0];
		$task = $this->Task->findByType('cache_exports');
		
		// If the next execution time and the timestamp don't match, it means that this task is no longer valid as the time for the execution has since being scheduled
		// been updated. 
		if ($task['Task']['next_execution_time'] != $timestamp) return;

		$users = $this->User->find('all', array(
				'recursive' => -1,
				'conditions' => array(
						'Role.perm_site_admin' => 0,
						'User.disabled' => 0,
				),
				'contain' => array(
						'Organisation' => array('fields' => array('name')),
						'Role' => array('fields' => array('perm_site_admin'))	
				),
				'fields' => array('User.org_id', 'User.id'),
				'group' => array('User.org_id')
		));
		$site_admin = $this->User->find('first', array(
				'recursive' => -1,
				'conditions' => array(
						'Role.perm_site_admin' => 1,
						'User.disabled' => 0
				),
				'contain' => array(
						'Organisation' => array('fields' => array('name')),
						'Role' => array('fields' => array('perm_site_admin'))	
				),
				'fields' => array('User.org_id', 'User.id')
		));
		$users[] = $site_admin;
		
		if ($task['Task']['timer'] > 0)	$this->Task->reQueue($task, 'cache', 'EventShell', 'enqueueCaching', false, false);
		
		// Queue a set of exports for admins. This "ADMIN" organisation. The organisation of the admin users doesn't actually matter, it is only used to indentify
		// the special cache files containing all events
		$i = 0;
		foreach ($users as $user) {
			foreach($this->Event->export_types as $k => $type) {
				$this->Job->cache($k, $user['User'], 'Events visible to: ' . ($user['Role']['perm_site_admin'] ? 'ADMIN' : $user['Organisation']['name']));
				$i++;
			}
		}
		$this->Task->id = $task['Task']['id'];
		$this->Task->saveField('message', $i . ' job(s) started at ' . date('d/m/Y - H:i:s') . '.');
	}
	
	public function publish() {
		$id = $this->args[0];
		$passAlong = $this->args[1];
		$jobId = $this->args[2];
		$userId = $this->args[3];
		$user = $this->User->getAuthUser($userId);
		$job = $this->Job->read(null, $jobId);
		$this->Event->Behaviors->unload('SysLogLogable.SysLogLogable');
		$result = $this->Event->publish($id, $passAlong);
		$job['Job']['progress'] = 100;
		if ($result) {
			$job['Job']['message'] = 'Event published.';
		} else {
			$job['Job']['message'] = 'Event published, but the upload to other instances may have failed.';
		}
		$this->Job->save($job);
		$log = ClassRegistry::init('Log');
		$log->create();
		$log->createLogEntry($user, 'publish', 'Event (' . $id . '): published.', 'publised () => (1)');
	}

}

