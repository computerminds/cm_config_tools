<?php

/**
 * @file
 * Extensions to configuration development module drush integration.
 */

use Computerminds\ConfigDiffStorageComparer;
use Drupal\config\StorageReplaceDataWrapper;
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
    'drush dependencies' => array('config_devel'),
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
      'drush cm-config-tools-import computerminds_profile --preview=0' => 'Import configuration into the active storage, from the specified project, without any preview.',
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
  /** @var \Drupal\Core\Config\StorageInterface $active_storage */
  $active_storage = \Drupal::service('config.storage');
  $source_storage = new StorageReplaceDataWrapper($active_storage);
  $info_parser = \Drupal::service('info_parser');

  $source_dirs = array();
  if ($extensions) {
    $extensions = array_map('trim', explode(',', $extensions));
    foreach ($extensions as $extension_name) {
      // Determine the type of extension we're dealing with.
      if ($type = drush_config_devel_get_type($extension_name)) {
        if ($extension_path = drupal_get_path($type, $extension_name)) {
          $source_dirs[$extension_path] = $extension_name;
        }
      }
    }
  }
  else {
    // Import configuration from any enabled extensions with the cm_config_tools
    // key in their .info.yml file.
    $module_dirs = \Drupal::moduleHandler()->getModuleList();
    $theme_dirs = \Drupal::service('theme_handler')->listInfo();
    foreach ([$module_dirs, $theme_dirs] as $extension_dirs) {
      /** @var \Drupal\Core\Extension\Extension[] $extension_dirs */
      foreach ($extension_dirs as $extension_name => $extension) {
        $info_filename = $extension->getPath() . '/' . $extension_name .'.info.yml';
        $info = $info_parser->parse($info_filename);
        if (isset($info['cm_config_tools'])) {
          $source_dirs[$extension->getPath()] = $extension_name;
        }
      }
    }
  }

  $config_extension_map = array();
  $subdir = drush_get_option('subdir', InstallStorage::CONFIG_INSTALL_DIRECTORY);
  foreach ($source_dirs as $source_dir => $extension_name) {
    // Info parser service statically caches info files, so it does not matter
    // if file has previously been parsed above.
    $info_filename = $source_dir . '/' . $extension_name .'.info.yml';
    $info = $info_parser->parse($info_filename);
    $create_only = isset($info['cm_config_tools']['create_only']) ? array_flip($info['cm_config_tools']['create_only']) : array();

    $file_storage = new FileStorage($source_dir . '/' . $subdir);
    foreach ($file_storage->listAll() as $name) {
      if (isset($config_extension_map[$name])) {
        drush_set_error("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '{$config_extension_map[$name]}' extensions.");
        return FALSE;
      }

      // Replace data if it is not listed as create_only (i.e. should only be
      // installed once), or does not yet exist.
      $config_extension_map[$name] = $extension_name;
      if (!isset($create_only[$name]) || !$source_storage->exists($name)) {
        $data = $file_storage->read($name);
        $source_storage->replaceData($name, $data);
      }
    }

    $deletes = isset($info['cm_config_tools']['delete']) ? array_values($info['cm_config_tools']['delete']) : array();
    foreach ($deletes as $name) {
      if (isset($config_extension_map[$name])) {
        if ($config_extension_map[$name] == $extension_name) {
          drush_set_error("Could not import configuration because the configuration item '$name' is provided by the '$extension_name' extension but also listed for deletion.");
        }
        else {
          drush_set_error("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '{$config_extension_map[$name]}' extensions.");
        }
        return FALSE;
      }

      // Replacing as an empty array simulates an item being deleted.
      $source_storage->replaceData($name, array());
      $config_extension_map[$name] = $extension_name;
    }
  }

  /** @var \Drupal\Core\Config\ConfigManagerInterface $config_manager */
  $config_manager = \Drupal::service('config.manager');
  // Use a custom storage comparer, based on one from an old version of
  // config_sync, that uses a more useful differ in config_update that ignores
  // changes to UUIDs and the '_core' property.
  $storage_comparer = new ConfigDiffStorageComparer($source_storage, $active_storage, $config_manager, \Drupal::service('config_update.config_diff'));
  if (!$storage_comparer->createChangelist()->hasChanges()) {
    return drush_log(dt('There are no changes to import.'), LogLevel::OK);
  }

  if ($preview = drush_get_option('preview', 'list')) {
    if ($preview == 'list') {
      $change_list = $storage_comparer->getChangelist();
      _drush_cm_config_tools_print_config_changes_table($change_list, $config_extension_map);
    }
    else {
      // Copy active storage to the temporary directory.
      $temp_dir = drush_tempdir();
      $temp_storage = new FileStorage($temp_dir);
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
  }

  if (drush_confirm(dt('Import the listed configuration changes?'))) {
    return drush_op('_drush_config_import', $storage_comparer);
  }
}

/**
 * Normalize configuration to get helpful diffs.
 *
 * @see \Drupal\config_update\ConfigDiffer::normalize().
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
 *
 * @see _drush_print_config_changes_table().
 */
function _drush_cm_config_tools_print_config_changes_table(array $config_changes, array $config_extension_map) {
  $tbl =  _drush_cm_config_tools_format_config_changes_table($config_changes, $config_extension_map, !drush_get_context('DRUSH_NOCOLOR'));

  $output = $tbl->getTable();
  if (!stristr(PHP_OS, 'WIN')) {
    $output = str_replace("\r\n", PHP_EOL, $output);
  }

  drush_print(rtrim($output));
  return $tbl;
}

/**
 * Print a table of config changes.
 *
 * @param array $config_changes
 *   An array of changes.
 * @param array $config_extension_map
 *   An array mapping config names to the extension that provides them.
 *
 * @see _drush_format_config_changes_table().
 */
function _drush_cm_config_tools_format_config_changes_table(array $config_changes, array $config_extension_map, $use_color = FALSE) {
  if (!$use_color) {
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
    foreach($configs as $config) {
      $rows[] = array(
        isset($config_extension_map[$config]) ? $config_extension_map[$config] : '',
        $config,
        sprintf($colour, $change)
      );
    }
  }
  $tbl = _drush_format_table($rows);
  return $tbl;
}
