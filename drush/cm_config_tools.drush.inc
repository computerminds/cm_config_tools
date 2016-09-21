<?php

/**
 * @file
 * ComputerMinds Config tools drush integration.
 */

use Drupal\cm_config_tools\StorageReplaceDataMappedWrapper;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function cm_config_tools_drush_command() {
  $items = array();

  $description = '';
  $description .= "Update configuration in active storage to what is supplied by modules" . "\n";
  $description .= "for installation. Specify config for deletion, or as create-only (i.e." . "\n";
  $description .= "installed if not yet present, otherwise ignored), in a module's info"  . "\n";
  $description .= "file by listing them under 'cm_config_tools', as shown below:"  . "\n";
  $description .= "\n";
  $description .= "cm_config_tools:"  . "\n";
  $description .= "  delete:"  . "\n";
  $description .= "    - field.field.node.article.body"  . "\n";
  $description .= "  create_only:"  . "\n";
  $description .= "    - image.style.full_width"  . "\n";

  $items['cm-config-tools-import'] = array(
    'drupal dependencies' => array('config', 'config_update'),
    'description' => $description,
    'arguments' => array(
      'project' => 'Module/theme machine name. Separate multiple projects with commas. If left blank, import from any enabled projects containing a \'cm_config_tools\' key in their .info.yml files.',
    ),
    'options' => array(
      'preview' => array(
        'description' => 'Format for displaying proposed changes. Recognized values: list, diff. Defaults to list. Set to 0 to disable.',
        'example-value' => 'list',
      ),
      'subdir' => array(
        'description' => 'Sub-directory of configuration to import. Defaults to "config/install".',
        'example-value' => 'config/optional',
      ),
    ),
    'required-arguments' => FALSE,
    'examples' => array(
      'drush cm-config-tools-import' => 'Import configuration into the active storage, from any projects containing a \'cm_config_tools\' key in their .info.yml files.',
      'drush cm-config-tools-import mymodule --preview=0' => 'Import configuration into the active storage, from the specified project, without any preview.',
    ),
    'aliases' => array('cmci'),
    'core' => array('8+'),
  );

  return $items;
}

/**
 * Drush command callback.
 */
function drush_cm_config_tools_import($extensions = NULL) {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  if ($extensions) {
    $extension_dirs = $helper->getExtensionDirectories($extensions);
  }
  else {
    $extension_dirs = $helper->getAllExtensionDirectories();
  }

  $subdir = drush_get_option('subdir', InstallStorage::CONFIG_INSTALL_DIRECTORY);
  if ($extension_dirs && ($storage_comparer = $helper->getStorageComparer($extension_dirs, $subdir))) {
    if ($preview = drush_get_option('preview', 'table')) {
      if ($preview == 'diff') {
        drush_cm_config_tools_preview_diff($storage_comparer, $extension_dirs);
      }
      else {
        drush_cm_config_tools_preview_table($storage_comparer->getChangelist(), $storage_comparer->getSourceStorage());
      }
    }

    // Use _drush_config_import() rather than import methods on $helper.
    if (drush_confirm(dt('Import the listed configuration changes?'))) {
      return drush_op('_drush_config_import', $storage_comparer);
    }
  }
  else {
    return drush_log(dt('There are no changes to import.'), LogLevel::OK);
  }
}

/**
 * Run a full filesystem diff on the configuration to import.
 *
 * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
 * @param array $source_dirs
 *
 * @TODO Isn't $source_dirs just extension directories? We want to be acting on
 *   config/install (or whichever other subdir) within that.
 */
function drush_cm_config_tools_preview_diff($storage_comparer, $source_dirs) {
  // Copy active storage to the temporary directory.
  $temp_dir = drush_tempdir();
  $temp_storage = new FileStorage($temp_dir);
  $active_storage = $storage_comparer->getTargetStorage();
  foreach ($source_dirs as $source_dir => $extension_name) {
    $source_dir_storage = new FileStorage($source_dir);
    foreach ($source_dir_storage->listAll() as $name) {
      if ($data = $active_storage->read($name)) {
        $data = drush_cm_config_tools_normalize_config($data);
        $temp_storage->write($name, $data);
      }
    }
  }

  if (count($source_dirs) == 1) {
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, key($source_dirs));
  }
  else {
    // Copy source storage to another temporary directory.
    $temp_source_dir = drush_tempdir();
    $temp_storage = new FileStorage($temp_source_dir);
    $source_storage = $storage_comparer->getSourceStorage();
    foreach ($source_dirs as $source_dir => $extension_name) {
      $source_dir_storage = new FileStorage($source_dir);
      foreach ($source_dir_storage->listAll() as $name) {
        if ($data = $source_storage->read($name)) {
          $data = drush_cm_config_tools_normalize_config($data);
          $temp_storage->write($name, $data);
        }
      }
    }

    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, $temp_source_dir);
  }

  $output = drush_shell_exec_output();
  drush_print(implode("\n", $output));
}

/**
 * Normalize configuration to get helpful diffs.
 *
 * Unfortunately \Drupal\config_update\ConfigDiffer::normalize() is a protected
 * method, so we cannot call it without wrapping that class, which isn't really
 * worth it as it only needs to be used directly here.
 */
function drush_cm_config_tools_normalize_config($config, $ignore = array('uuid', '_core')) {
  // Remove "ignore" elements.
  foreach ($ignore as $element) {
    unset($config[$element]);
  }

  // Recursively normalize remaining elements, if they are arrays.
  foreach ($config as $key => $value) {
    if (is_array($value)) {
      $new = drush_cm_config_tools_normalize_config($value, $ignore);
      if (count($new)) {
        $config[$key] = $new;
      }
      else {
        unset($config[$key]);
      }
    }
  }

  // Sort and return.
  ksort($config);
  return $config;
}

/**
 * Print a table of config changes.
 *
 * @param array $config_changes
 *   An array of changes.
 * @param StorageReplaceDataMappedWrapper $source_storage
 *   Source storage mapping config names to the extension that provides them.
 *
 * @see _drush_print_config_changes_table().
 * @see _drush_format_config_changes_table().
 */
function drush_cm_config_tools_preview_table(array $config_changes, StorageReplaceDataMappedWrapper $source_storage) {
  if (drush_get_context('DRUSH_NOCOLOR')) {
    $red = "%s";
    $yellow = "%s";
    $green = "%s";
  }
  else {
    $red = "\033[31;40m\033[1m%s\033[0m";
    $yellow = "\033[1;33;40m\033[1m%s\033[0m";
    $green = "\033[1;32;40m\033[1m%s\033[0m";
  }

  $rows = array();
  $rows[] = array('Provded by', 'Config', 'Operation');
  foreach ($config_changes as $change => $configs) {
    switch ($change) {
      case 'delete':
        $colour = $red;
        break;
      case 'update':
        $colour = $yellow;
        break;
      case 'create':
        $colour = $green;
        break;
      default:
        $colour = "%s";
        break;
    }
    foreach ($configs as $config) {
      $mapping = $source_storage->getMapping($config);
      $rows[] = array(
        $mapping ? $mapping : '',
        $config,
        sprintf($colour, $change)
      );
    }
  }
  $tbl = _drush_format_table($rows);

  $output = $tbl->getTable();
  if (!stristr(PHP_OS, 'WIN')) {
    $output = str_replace("\r\n", PHP_EOL, $output);
  }

  drush_print(rtrim($output));
  return $tbl;
}
