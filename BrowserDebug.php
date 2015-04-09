<?php
/**
 * @file
 * Browser Debug Class.
 *
 * @todo
 * Add hover display option.
 * @todo
 * Cater for truncated watchdog table.
 * @todo
 * Add ajax repsonses to panels.
 * @todo
 * Installer script?
 * @todo
 * Seperate session?
 */

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

class BrowserDebug {

  private $settings;
  private $stream;
  private $cloner;
  private $dumper;

  public function __construct() {
    $this->stream = fopen('php://memory', 'r+');
    $this->cloner = new VarCloner();
    $this->dumper = new HtmlDumper($this->stream);
    $this->getSettings();
    $this->updateLogPositions();
  }

  private function updateLogPositions() {
    if ($this->settings['watchdog'] === 0) {
      $this->settings['watchdog'] = $this->getWatchdogPosition();
    }
    foreach ($this->settings['logs'] as $log => &$pos) {
      $size = (int) filesize($log);
      if ($pos === 0) {
        // No position in settings so start from current position.
        $pos = $size;
      }
      elseif ($pos > $size) {
        // File may have been recreated or rotated.
        $pos = 0;
      }
    }
  }

  public function dump($var) {
    $that = $this;
    $this->dumper->dump($this->cloner->cloneVar($var));
  }

  private function getDump() {
    rewind($this->stream);
    $s = stream_get_contents($this->stream);
    return $s;
  }

  private function getSettings() {
    $logs = variable_get('browser_debug_logs', '');
    if (empty($logs)) {
      $logs = array();
    }
    else {
      $logs = explode(',', $logs);
      $logs = array_combine($logs, array_pad(array(), count($logs), 0));
    }
    // Make array of with log path as key and 0 as value;
    $settings = variable_get('browser_debug_settings', array());
    // Create default array structure.
    $default = array('watchdog' => 0, 'logs' => $logs);
    // Add missing.
    $settings = array_replace_recursive($default, $settings);
    // Remove extra.
    $settings['logs'] = array_intersect_key($settings['logs'], $logs);
    // $this->log(print_r($settings, TRUE), 'settings (get)');
    $this->settings = $settings;
  }

  private function saveSettings() {
    // $this->log(print_r($this->settings, TRUE), 'settings (set)');
    variable_set('browser_debug_settings', $this->settings);
  }

  public function log($item, $label) {
    switch (TRUE) {
      case empty($label):
        $this->log[] = $item;
        break;

      case is_array($item) || is_object($item):
        $this->log[] = $label . ':';
        $this->log[] = $item;
        break;

      default:
        $this->log[] = $label . ': ' . $item;
        break;
    }
  }

  public function getAllData() {
    $this->dump(array(
      'session' => $this->getSession(),
      'cookie' => $_COOKIE,
      'server' => $_SERVER,
    ));
    $data = array(
      'logs' => array_merge($this->getWatchdogLog(), $this->getLogs()),
      'html' => file_get_contents(drupal_get_path('module', 'browser_debug') . '/browser_debug.html'),
    );
    $this->saveSettings();
    // Add log, done as last step to enable internal logging until the last moment!
    $data['dump'] = $this->getDump();
    return $data;
  }

  public function getWatchdogPosition() {
    $wid = (int) db_query('select max(wid) from watchdog;')->fetchField();
    return $wid;
  }

  private function getWatchdogLog() {
    $wid = $this->getWatchdogPosition();
    $last_wid = $this->settings['watchdog'];
    $this->settings['watchdog'] = $wid;

    $query = db_select('watchdog', 'w')->extend('PagerDefault')->extend('TableSort');
    $query->leftJoin('users', 'u', 'w.uid = u.uid');
    $query
      ->fields('w', array('wid', 'uid', 'severity', 'type', 'timestamp', 'message', 'variables', 'link'))
      ->addField('u', 'name');

    $result = $query
      ->condition('wid', array($last_wid, $wid), 'BETWEEN')
      ->limit(500)
      ->orderBy('wid', 'desc')
      ->execute();

    $rows = array();
    foreach ($result as $dblog) {

      $serialized_false = serialize(FALSE);
      @$vars = unserialize($dblog->variables);
      if (!isset($vars) || ($vars === FALSE && $value !== $serialized_false)) {
        $message = strip_tags(decode_entities($dblog->message));
      }
      else {
        $message = strip_tags(decode_entities(t($dblog->message, $vars)));
      }

      $row = array(
        format_date($dblog->timestamp, 'short'),
        $dblog->type,
        $message,
        $dblog->name,
      );

      $rows[] = implode(' : ', $row);

    }
    return array('watchdog' => $rows);
  }

  private function getSession() {
    if (!isset($_SESSION)) {
      return array();
    }
    $session  = array();
    $serialized_false = serialize(FALSE);
    foreach ($_SESSION as $key => $value) {
      @$unserialized = unserialize(is_string($value) ? $value : $serialized_false);
      if ($unserialized === FALSE && $value !== $serialized_false) {
        $session[$key] = $value;
      }
      else {
        $session[$key] = $unserialized;
      }
    }
    return $session;
  }

  private function getLogs() {
    $return = array();
    foreach ($this->settings['logs'] as $log => &$pos) {
      if (!file_exists($log)) {
        $this->log('The file ' . $log . ' does not exist', 'Error');
        $return[basename($log)] = array();
        continue;
      }
      $new_pos = filesize($log);
      if ($new_pos <= $pos) {
        $return[basename($log)] = array();
        continue;
      }
      $contents = file_get_contents($log, FALSE, NULL, $pos, $new_pos - $pos);
      $array = explode("\n", $contents);
      array_pop($array);
      $return[basename($log)] = $array;
      $pos = $new_pos;
    }
    return $return;
  }

  private function convertArrayToObject($array) {
    $object = new stdClass();
    foreach ($array as $key => $value) {
      $object->key = $value;
    }
    return $object;
  }

}
