<?php

/**
 * @file
 * ComputerMinds Config tools drush integration.
 */

use Drupal\cm_config_tools\ConfigDiffStorageComparer;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function cm_config_tools_drush_command() {
  $items = array();

  $description = '';
  $description .= "Write back configuration to module's config/install directory." . "\n";
  $description .= "List which configuration settings you want to export in the" . "\n";
  $description .= "modules info file by listing them under 'cm_config_tools', as shown below:"  . "\n";
  $description .= "\n";
  $description .= "cm_config_tools:"  . "\n";
  $description .= "  managed:"  . "\n";
  $description .= "    - field.field.node.page.field_image"  . "\n";

  $items['cm-config-tools-export'] = array(
    'drupal dependencies' => array('config', 'config_update'),
    'description' => $description,
    'arguments' => array(
      'projects' => 'Module/theme machine names, separated with commas. If left blank, export to any enabled projects containing a \'cm_config_tools\' key in their .info.yml files.',
    ),
    'options' => array(
      'all' => array(
        'description' => 'Without this option, any config listed as \'unmanaged\' is only exported when it has not previously been exported. Set this option to overwrite any such config even if it has been previously exported.',
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
  $description .= "  unmanaged:"  . "\n";
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
  $helper = \Drupal::service('cm_config_tools');

  // Copy active storage to the temporary directory.
  $temp_dir = drush_cm_config_tools_tempdir('target');
  $temp_storage = new FileStorage($temp_dir);
  $active_storage = $storage_comparer->getTargetStorage();
  foreach ($extension_dirs as $source_dir => $extension_name) {
    $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
    foreach ($source_dir_storage->listAll() as $name) {
      if ($data = $active_storage->read($name)) {
        $data = $helper::normalize($data);
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
          $data = $helper::normalize($data);
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
  $tbl = _drush_format_table($rows);

  $output = $tbl->getTable();
  if (!stristr(PHP_OS, 'WIN')) {
    $output = str_replace("\r\n", PHP_EOL, $output);
  }

  drush_print(rtrim($output));
  return $tbl;
}
