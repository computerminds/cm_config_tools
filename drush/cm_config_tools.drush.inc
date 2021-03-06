<?php

/**
 * @file
 * ComputerMinds Config tools drush integration.
 */

use Drupal\cm_config_tools\ConfigDiffStorageComparer;
use Drupal\cm_config_tools\Exception\ExtensionConfigLockedException;
use Drupal\cm_config_tools\ExtensionConfigHandlerInterface;
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
  $description .= "module's info file by listing them under 'cm_config_tools', as shown below:"  . "\n";
  $description .= "\n";
  $description .= "cm_config_tools:"  . "\n";
  $description .= "  managed:"  . "\n";
  $description .= "    - field.field.node.page.field_image"  . "\n";

  $items['cm-config-tools-export'] = $dependencies + array(
    'description' => $description,
    'arguments' => array(),
    'options' => array(
      'force-unmanaged' => array(
        'description' => 'Without this option, any config listed as \'unmanaged\' is only exported when it has not previously been exported. Set this option to overwrite any such config even if it has been previously exported.',
      ),
      'subdir' => array(
        'description' => 'Sub-directory of configuration to import. Defaults to "config/install".',
        'example-value' => 'config/optional',
      ),
      'fully-normalize' => array(
        'description' => 'Sort keys within configuration items when exporting, and strip any empty arrays. This ensures more reliability when comparing between source and target config but usually means unnecessary changes.',
      ),
      'include-dependencies' => array(
        'description' => 'By default, config dependencies of the config to export are also exported (in order to ensure all dependencies can never be missing). But set this parameter to 0 to avoid doing that, in which case you will need to ensure dependencies are met before imports/installs. Set it to 1 in order to force config dependencies to be exported regardless of whether config is provided by other extensions working with cm_config_tools.',
      ),
    ),
    'required-arguments' => FALSE,
    'examples' => array(
      'drush cm-config-tools-export --include-dependencies=0' => 'Write back configuration from the active storage, to any projects containing a \'cm_config_tools\' key in their .info.yml files, without any of that configuration\'s dependencies.',
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
  $description .= "  unmanaged:"  . "\n";
  $description .= "    - image.style.full_width"  . "\n";

  $items['cm-config-tools-import'] = $dependencies + array(
    'description' => $description,
    'arguments' => array(),
    'options' => array(
      'preview' => array(
        'description' => 'Format for displaying proposed changes. Recognized values: list, diff. Defaults to list. Set to 0 to disable.',
        'example-value' => 'list',
      ),
      'force-unmanaged' => array(
        'description' => 'Without this option, any config listed as \'unmanaged\' is only imported when it has not previously been created. Set this option to overwrite any such config even if it has been previously created.',
      ),
      'subdir' => array(
        'description' => 'Sub-directory of configuration to import. Defaults to "config/install".',
        'example-value' => 'config/optional',
      ),
    ),
    'required-arguments' => FALSE,
    'examples' => array(
      'drush cm-config-tools-import --preview=0' => 'Import configuration into the active storage, from any projects containing a \'cm_config_tools\' key in their .info.yml files, without any preview.',
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

  $items['cm-config-tools-suggest'] = array(
    'description' => "List the dependencies of an extension's default configuration.",
    'arguments' => array(
      'module' => 'Module machine name.',
    ),
    'examples' => array(
      'drush cm-config-tools-suggest MODULE_NAME' => 'List the dependencies of the configuration',
    ),
    'aliases' => array('cmcs'),
    'outputformat' => array(
      'default' => 'yaml',
    ),
  );

  return $items;
}

/**
 * Drush command callback.
 */
function drush_cm_config_tools_export() {
  $subdir = drush_get_option('subdir', InstallStorage::CONFIG_INSTALL_DIRECTORY);
  $force_unmanaged = drush_get_option('force-unmanaged', FALSE);
  $fully_normalize = drush_get_option('fully-normalize', FALSE);
  $include_dependencies = drush_get_option('include-dependencies', ExtensionConfigHandlerInterface::WITH_DEPENDENCIES_NOT_PROVIDED);

  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  $result = $helper->export($include_dependencies, $subdir, $force_unmanaged, $fully_normalize);

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
function drush_cm_config_tools_import() {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  $subdir = drush_get_option('subdir', InstallStorage::CONFIG_INSTALL_DIRECTORY);
  $force_unmanaged = drush_get_option('force-unmanaged', FALSE);
  $extension_dirs = $helper->getExtensionDirectories();

  if ($extension_dirs && ($storage_comparer = $helper->getStorageComparer($extension_dirs, $subdir, $force_unmanaged))) {
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
function drush_cm_config_tools_preview_diff($storage_comparer, $extension_dirs, $subdir = '') {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');

  // Copy active storage to the temporary directory.
  $temp_dir = drush_cm_config_tools_tempdir('target');
  $temp_storage = new FileStorage($temp_dir);
  $active_storage = $storage_comparer->getTargetStorage();
  foreach ($extension_dirs as $type => $type_extension_dirs) {
    foreach ($type_extension_dirs as $source_dir => $extension_name) {
      $info = $helper->getExtensionInfo($type, $extension_name);
      $importable = $info['managed'] + $info['unmanaged'] + $info['implicit'];

      foreach ($importable as $name) {
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
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, key($type_extension_dirs) . '/' . $subdir);
  }
  else {
    // Copy source storage to another temporary directory.
    $temp_source_dir = drush_cm_config_tools_tempdir('source');
    $temp_storage = new FileStorage($temp_source_dir);
    $source_storage = $storage_comparer->getSourceStorage();
    foreach ($extension_dirs as $type => $type_extension_dirs) {
      foreach ($type_extension_dirs as $source_dir => $extension_name) {
        $info = $helper->getExtensionInfo($type, $extension_name, NULL);
        $importable = $info['managed'] + $info['unmanaged'] + $info['implicit'];

        foreach ($importable as $name) {
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
  $rows[] = array('Provided by', 'Config', 'Operation');
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
 */
function drush_cm_config_tools_revert($names) {
  /** @var \Drupal\cm_config_tools\ExtensionConfigHandler $helper */
  $helper = \Drupal::service('cm_config_tools');
  $errors = $helper->revert($names);

  if ($errors) {
    foreach ($errors as $error) {
      drush_set_error($error);
    }
  }
  else {
    return drush_log(dt('Configuration items successfully reverted.'), LogLevel::SUCCESS);
  }
}

/**
 * Drush command callback.
 */
function drush_cm_config_tools_suggest($extension) {
  $dependencies = \Drupal::service('cm_config_tools')->getExtensionConfigSuggestions($extension);
  return $dependencies;
}
