<?php

/**
 * @file
 * ComputerMinds Config tools drush integration.
 */

use Drupal\cm_config_tools\ConfigDiffStorageComparer;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageException;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function cm_config_tools_drush_command() {
  $items = array();

  $description = '';
  $description .= "Write back configuration to module's config/install directory." . "\n";
  $description .= "List which configuration settings you want to export in the" . "\n";
  $description .= "module's info file by listing them under 'config_devel', as shown below:"  . "\n";
  $description .= "\n";
  $description .= "config_devel:"  . "\n";
  $description .= "  - entity.view_display.node.article.default"  . "\n";
  $description .= "  - entity.view_display.node.article.teaser"  . "\n";
  $description .= "  - field.instance.node.article.body"  . "\n";

  $items['cm-config-tools-export'] = array(
    'drupal dependencies' => array('config', 'config_update'),
    'description' => $description,
    'arguments' => array(
      'projects' => 'Module/theme machine names, separated with commas. If left blank, export to any enabled projects containing a \'cm_config_tools\' key in their .info.yml files.',
    ),
    'options' => array(
      'all' => array(
        'description' => 'Without this option, any config listed as \'create_only\' is only exported when it has not previously been exported. Set this option to overwrite any such config even if it has been previously exported.',
      ),
      'subdir' => array(
        'description' => 'Sub-directory of configuration to import. Defaults to "config/install".',
        'example-value' => 'config/optional',
      ),
      'fully-normalize' => array(
        'description' => 'Sort configuration keys alphabetically when exporting, and strip any empty values. This ensures more reliability when comparing between source and target config but usually means unnecessary changes.',
      ),
    ),
    'required-arguments' => FALSE,
    'examples' => array(
      'drush cm-config-tools-export' => 'Write back configuration to the specified modules from the active storage, from any projects containing a \'cm_config_tools\' key in their .info.yml files.',
      'drush cm-config-tools-export mymodule,othermodule --fully-normalize' => 'Write back normalized (sorted and unfiltered) configuration to the specified modules, based on .info file.',
    ),
    'aliases' => array('cmce'),
    'core' => array('8+'),
  );

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
 *
 * @see drush_config_devel_export()
 * @see drush_config_devel_get_config()
 * @see drush_config_devel_process_config()
 */
function drush_cm_config_tools_export($extensions = NULL) {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  if ($extensions) {
    $extension_dirs = $helper->getExtensionDirectories($extensions);
  }
  else {
    $extension_dirs = $helper->getAllExtensionDirectories();
  }

  /** @var \Drupal\config_update\ConfigDiffer $differ */
  $differ = \Drupal::service('config_update.config_diff');
  $subdir = drush_get_option('subdir', InstallStorage::CONFIG_INSTALL_DIRECTORY);
  $fully_normalize = drush_get_option('fully-normalize', FALSE);
  $all = drush_get_option('all', FALSE);

  foreach ($extension_dirs as $source_dir => $extension_name) {
    // Determine the type of extension we're dealing with.
    if ($type = $helper->detectExtensionType($extension_name)) {
      if (strpos($subdir, 'config/') === 0) {
        $subdir_type = substr($subdir, 7);
        // Get the configuration.
        $info = $helper->getExtensionInfo($extension_name, 'config_devel', array(), NULL);
        // Keep backwards compatibility for the old format.
        if (!isset($info['install'])) {
          $info['install'] = $info;
        }

        if (isset($info[$subdir_type]) && is_array($info[$subdir_type])) {
          // Exclude any create_only items.
          $create_only = $helper->getExtensionInfo($extension_name, 'create_only', array());
          if ($create_only && is_array($create_only)) {
            $create_only = array_flip($create_only);
          }
          else {
            $create_only = array();
          }

          // Process the configuration.
          if ($info[$subdir_type]) {
            try {
              $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
              foreach ($info[$subdir_type] as $name) {
                $config = \Drupal::config($name);
                if ($data = $config->get()) {
                  $existing_export = $source_dir_storage->read($name);

                  // Skip existing config that is listed as 'create only' unless
                  // the '--all' option was passed.
                  if ($existing_export && isset($create_only[$name]) && !$all) {
                    continue;
                  }

                  if (!$existing_export || !$differ->same($data, $existing_export)) {
                    // @TODO Do better than just normalizing without sorting or
                    // filtering.
                    $data = drush_cm_config_tools_normalize_config($data, $fully_normalize);
                    $source_dir_storage->write($name, $data);
                  }
                }
                else {
                  drush_log('Config ' . $name . ' not found in active storage.', 'warning');
                }
              }
            }
            catch (StorageException $e) {
              drush_log($e->getMessage(), 'error');
              continue;
            }
          }
        }
      }
    }
    else {
      drush_set_error("Couldn't export configuration. The '$extension_name' extension is not enabled.");
    }
  }
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
        drush_cm_config_tools_preview_diff($storage_comparer, $extension_dirs, $subdir);
      }
      else {
        drush_cm_config_tools_preview_table($storage_comparer);
      }
    }

    // Use _drush_config_import() rather than import methods on $helper.
    if (drush_confirm(dt('Import configuration changes?'))) {
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
 * @param array $extension_dirs
 */
function drush_cm_config_tools_preview_diff($storage_comparer, $extension_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
  // Copy active storage to the temporary directory.
  $temp_dir = drush_cm_config_tools_tempdir('target');
  $temp_storage = new FileStorage($temp_dir);
  $active_storage = $storage_comparer->getTargetStorage();
  foreach ($extension_dirs as $source_dir => $extension_name) {
    $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
    foreach ($source_dir_storage->listAll() as $name) {
      if ($data = $active_storage->read($name)) {
        $data = drush_cm_config_tools_normalize_config($data);
        $temp_storage->write($name, $data);
      }
    }
  }

  if (count($extension_dirs) == 1) {
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, key($extension_dirs));
  }
  else {
    // Copy source storage to another temporary directory.
    $temp_source_dir = drush_cm_config_tools_tempdir('source');
    $temp_storage = new FileStorage($temp_source_dir);
    $source_storage = $storage_comparer->getSourceStorage();
    foreach ($extension_dirs as $source_dir => $extension_name) {
      $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
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
 * Creates a temporary directory and return its path.
 */
function drush_cm_config_tools_tempdir($prefix) {
  $tmp_dir = drush_trim_path(drush_find_tmp());
  $tmp_dir .= '/drush_tmp/cm_config_tools/' . $prefix;

  drush_mkdir($tmp_dir);
  drush_register_file_for_deletion($tmp_dir);

  return $tmp_dir;
}

/**
 * Normalize configuration to get helpful diffs.
 *
 * Unfortunately \Drupal\config_update\ConfigDiffer::normalize() is a protected
 * method, so we cannot call it without wrapping that class, which isn't really
 * worth it as it only needs to be used directly here.
 */
function drush_cm_config_tools_normalize_config($config, $sort_and_filter = TRUE, $ignore = array('uuid', '_core')) {
  // Remove "ignore" elements.
  foreach ($ignore as $element) {
    unset($config[$element]);
  }

  // Recursively normalize remaining elements, if they are arrays.
  foreach ($config as $key => $value) {
    if (is_array($value)) {
      $new = drush_cm_config_tools_normalize_config($value, $sort_and_filter, $ignore);
      if (count($new)) {
        $config[$key] = $new;
      }
      elseif ($sort_and_filter) {
        unset($config[$key]);
      }
    }
  }

  if ($sort_and_filter) {
    ksort($config);
  }
  return $config;
}

/**
 * Print a table of config changes.
 *
 * @param ConfigDiffStorageComparer $storage_comparer
 *   Storage comparer.
 *
 * @see _drush_print_config_changes_table().
 * @see _drush_format_config_changes_table().
 */
function drush_cm_config_tools_preview_table(ConfigDiffStorageComparer $storage_comparer) {
  // Note that although $source_storage is actually a
  // \Drupal\cm_config_tools\DecoratingCachedStorage, that just wraps a
  // StorageReplaceDataMappedWrapper so its methods are accessible.
  /** @var \Drupal\cm_config_tools\StorageReplaceDataMappedWrapper $source_storage */
  $source_storage = $storage_comparer->getSourceStorage();
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
  foreach ($storage_comparer->getChangelist() as $change => $configs) {
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
