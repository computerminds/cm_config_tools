<?php

/**
 * @file
 * ComputerMinds Config tools drush integration.
 */

use Drupal\cm_config_tools\ConfigDiffStorageComparer;
use Drupal\cm_config_tools\Exception\ExtensionConfigLockedException;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function cm_config_tools_drush_command() {
  $items = array();

  $dependencies = array(
    'drupal dependencies' => array('config', 'config_update', 'cm_config_tools'),
    'core' => array('8+'),
  );

  $description = '';
  $description .= "Write back configuration to module's config/install directory." . "\n";
  $description .= "List which configuration settings you want to export in the" . "\n";
  $description .= "module's info file by listing them under 'config_devel', as shown below:"  . "\n";
  $description .= "\n";
  $description .= "config_devel:"  . "\n";
  $description .= "  - entity.view_display.node.article.default"  . "\n";
  $description .= "  - entity.view_display.node.article.teaser"  . "\n";
  $description .= "  - field.instance.node.article.body"  . "\n";

  $items['cm-config-tools-export'] = $dependencies + array(
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
        'description' => 'Sort configuration keys when exporting, and strip any empty arrays. This ensures more reliability when comparing between source and target config but usually means unnecessary changes.',
      ),
    ),
    'required-arguments' => FALSE,
    'examples' => array(
      'drush cm-config-tools-export' => 'Write back configuration to the specified modules from the active storage, from any projects containing a \'cm_config_tools\' key in their .info.yml files.',
      'drush cm-config-tools-export mymodule,othermodule --fully-normalize' => 'Write back normalized (sorted and unfiltered) configuration to the specified modules, based on .info file.',
    ),
    'aliases' => array('cmce'),
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

  $items['cm-config-tools-import'] = $dependencies + array(
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
  );

  $items['cm-config-tools-revert'] = $dependencies + array(
    'description' => $description,
    'arguments' => array(
      'full-name' => 'Full configuration name. Separate multiple config names with commas.',
    ),
    'required-arguments' => TRUE,
    'examples' => array(
      'drush cm-config-tools-revert migrate_plus.migration.hotels' => 'Revert the hotels migration.',
    ),
    'aliases' => array('cmcr'),
  );

  return $items;
}

/**
 * Drush command callback.
 */
function drush_cm_config_tools_export($extensions = NULL) {
  $subdir = drush_get_option('subdir', InstallStorage::CONFIG_INSTALL_DIRECTORY);
  $all = drush_get_option('all', FALSE);
  $fully_normalize = drush_get_option('fully-normalize', FALSE);

  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  if ($extensions) {
    $result = $helper->exportExtension($extensions, $subdir, $all, $fully_normalize);
  }
  else {
    $result = $helper->exportAll($subdir, $all, $fully_normalize);
  }

  if ($result === TRUE) {
    return drush_log(dt('Configuration successfully exported.'), LogLevel::SUCCESS);
  }
  else {
    if (is_array($result)) {
      foreach ($result as $extension_name => $extension_errors) {
        if (is_array($extension_errors)) {
          foreach ($extension_errors as $error) {
            drush_set_error($error);
          }
        }
      }
    }
    else {
      return drush_log(dt('There is no config to export.'), LogLevel::WARNING);
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
      return drush_op('_drush_cm_config_tools_import', $storage_comparer);
    }
  }
  else {
    return drush_log(dt('There are no changes to import.'), LogLevel::OK);
  }
}

/**
 * Run the config import once confirmed and handle any errors.
 */
function _drush_cm_config_tools_import(ConfigDiffStorageComparer $storage_comparer) {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  try {
    if ($errors = $helper->importFromComparer($storage_comparer)) {
      // Return a negative result for UI purposes. We do not differentiate
      // between an actual synchronization error and a failed lock, because
      // concurrent synchronizations are an edge-case happening only when
      // multiple developers or site builders attempt to do it without
      // coordinating.
      $message = 'The import failed due for the following reasons:' . "\n";
      $message .= implode("\n", $errors);

      return drush_set_error('config_import_fail', $message);
    }
    else {
      drush_log('The configuration was imported successfully.', LogLevel::SUCCESS);
    }
  }
  catch (ExtensionConfigLockedException $e) {
    drush_log('Another request may be synchronizing configuration already.', LogLevel::WARNING);
  }
}

/**
 * Run a full filesystem diff on the configuration to import.
 *
 * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
 * @param array $extension_dirs
 */
function drush_cm_config_tools_preview_diff($storage_comparer, $extension_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');

  // Copy active storage to the temporary directory.
  $temp_dir = drush_cm_config_tools_tempdir('target');
  $temp_storage = new FileStorage($temp_dir);
  $active_storage = $storage_comparer->getTargetStorage();
  foreach ($extension_dirs as $type => $type_extension_dirs) {
    foreach ($type_extension_dirs as $source_dir => $extension_name) {
      $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
      foreach ($source_dir_storage->listAll() as $name) {
        if ($data = $active_storage->read($name)) {
          $data = $helper::normalizeConfig($name, $data);
          $temp_storage->write($name, $data);
        }
      }
    }
  }

  $single_dir = FALSE;
  if (count($extension_dirs) == 1) {
    $type_extension_dirs = reset($extension_dirs);
    if ($type_extension_dirs && count($type_extension_dirs) == 1) {
      $single_dir = TRUE;
    }
  }

  if ($single_dir) {
    $type_extension_dirs = reset($extension_dirs);
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, key($type_extension_dirs));
  }
  else {
    // Copy source storage to another temporary directory.
    $temp_source_dir = drush_cm_config_tools_tempdir('source');
    $temp_storage = new FileStorage($temp_source_dir);
    $source_storage = $storage_comparer->getSourceStorage();
    foreach ($extension_dirs as $type => $type_extension_dirs) {
      foreach ($type_extension_dirs as $source_dir => $extension_name) {
        $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
        foreach ($source_dir_storage->listAll() as $name) {
          if ($data = $source_storage->read($name)) {
            $data = $helper::normalizeConfig($name, $data);
            $temp_storage->write($name, $data);
          }
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
 *
 * The directories are specifically named to make it more obvious which is the
 * source & target directory in diffs, because the unique directory names that
 * drush_tempdir() produces are hard to understand.
 *
 * @see drush_tempdir()
 */
function drush_cm_config_tools_tempdir($suffix) {
  $tmp_dir = drush_trim_path(drush_find_tmp());
  $tmp_dir .= '/drush_tmp/cm_config_tools/' . $suffix;

  drush_mkdir($tmp_dir);
  drush_register_file_for_deletion($tmp_dir);

  return $tmp_dir;
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
  uasort($rows, 'drush_cm_config_tools_sort_preview_table');
  $tbl = _drush_format_table($rows);

  $output = $tbl->getTable();
  if (!stristr(PHP_OS, 'WIN')) {
    $output = str_replace("\r\n", PHP_EOL, $output);
  }

  drush_print(rtrim($output));
  return $tbl;
}

/**
 * Sort rows in table by the values in each column.
 */
function drush_cm_config_tools_sort_preview_table($a, $b) {
  foreach ($a as $i => $cell) {
    if ($cmp = strcmp($cell, $b[$i])) {
      return $cmp;
    }
  }
  return 0;
}

/**
 * Drush command callback.
 *
 * This could really just be part of config_update. The only 'magic' this
 * provides is to translate from a full config name (including prefix and
 * provider) to the 'short' name, which the methods on ConfigReverter use, and
 * to allow reverting multiple config items in one operation.
 *
 * @see \Drupal\config_update\ConfigReverter::import()
 * @see \Drupal\config_update\ConfigReverter::revert()
 */
function drush_cm_config_tools_revert($names) {
  $names = array_map('trim', explode(',', $names));
  $errors = [];
  if ($names) {
    $type_manager = \Drupal::entityTypeManager();
    /** @var \Drupal\config_update\ConfigLister $config_lister */
    $config_lister = \Drupal::service('config_update.config_list');
    /** @var \Drupal\config_update\ConfigReverter $reverter */
    $reverter = \Drupal::service('config_update.config_update');
    list($active_list, , ) = $config_lister->listConfig('type', 'system.all');

    foreach ($names as $full_name) {
      // ConfigReverter methods require the 'short' name for typed config,
      // otherwise 'system.simple'.
      if ($type = $config_lister->getTypeNameByConfigName($full_name)) {
        /** @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $definition */
        $definition = $type_manager->getDefinition($type);
        $prefix = $definition->getConfigPrefix() . '.';
        if (strpos($full_name, $prefix) === 0) {
          $name = substr($full_name, strlen($prefix));
        }
        else {
          $errors[$full_name] = dt('Config @name does not match expected config prefix.', array('@name' => $full_name));
          continue;
        }
      }
      else {
        $type = 'system.simple';
        $name = $full_name;
      }

      $method = in_array($full_name, $active_list) ? 'revert' : 'import';
      if (!$reverter->{$method}($type, $name)) {
        $errors[$name] = dt('Config @name could not be reverted.', array('@name' => $name));
      }
    }
  }
  else {
    $errors[] = dt('No configuration specified to revert.');
  }

  if ($errors) {
    foreach ($errors as $error) {
      drush_set_error($error);
    }
  }
  else {
    return drush_log(dt('Configuration items successfully reverted.'), LogLevel::SUCCESS);
  }
}
