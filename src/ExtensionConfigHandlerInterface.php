<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandlerInterface.
 */

namespace Drupal\cm_config_tools;

use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageComparerInterface;

/**
 * Defines a class to help with importing and exporting config from extensions.
 */
interface ExtensionConfigHandlerInterface {

  /**
   * Import configuration from extensions.
   *
   * @param string|array $extension
   *   The machine name of the project to import configuration from. Multiple
   *   projects can be specified, separated with commas, or as an array.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return array
   *   Return any error messages logged during the import.
   */
  public function importExtension($extension, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY);

  /**
   * Import configuration from all extensions for this workflow.
   *
   * Configuration should be imported from any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return array
   *   Return any error messages logged during the import.
   */
  public function importAll($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY);

  /**
   * Get directories of supplied extensions.
   *
   * @param string|array $extension
   *   The machine name of the project to import configuration from. Multiple
   *   projects can be specified, separated with commas, or as an array.
   * @param bool $disabled
   *   Optionally check for disabled modules and themes too.
   *
   * @return array
   *   Array of extension types ('module', 'theme', 'profile'), mapped to arrays
   *   of directories of extensions to import from, mapped to their project
   *   names.
   */
  public function getExtensionDirectories($extension, $disabled = FALSE);

  /**
   * Get directories of all extensions that have a 'cm_config_tools' key.
   *
   * Configuration should be imported from any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @param bool $disabled
   *   Optionally check for disabled modules and themes too.
   *
   * @return array
   *   Array of extension types ('module', 'theme', 'profile'), mapped to arrays
   *   of directories of extensions to import from, mapped to their project
   *   names.
   */
  public function getAllExtensionDirectories($disabled = FALSE);

  /**
   * Get the config storage comparer that will be used for importing.
   *
   * A custom storage comparer is used, based on one from an old version of the
   * config_sync project, that uses a more useful differ in config_update that
   * ignores changes to UUIDs and the '_core' property. This method is public so
   * that calling code can do useful things with it before actually importing,
   * such as previewing changes.
   *
   * @param array $source_dirs
   *   Array of extension types mapped to arrays of source directories mapped to
   *   their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return bool|ConfigDiffStorageComparer
   *   The storage comparer; FALSE if configuration changes could not be found
   *   to import.
   */
  public function getStorageComparer(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY);

  /**
   * Perform import of configuration from the supplied comparer.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return array
   *   Return any error messages logged during the import.
   */
  public function importFromComparer(StorageComparerInterface $storage_comparer);

  /**
   * Export configuration to extensions.
   *
   * @param string|array $extension
   *   The machine name of the project to export configuration to. Multiple
   *   projects can be specified, separated with commas, or as an array.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   * @param bool $all
   *   Optional. Without this option, any config listed as 'create_only' is only
   *   exported when it has not previously been exported. Set this option to
   *   overwrite any such config even if it has been previously exported.
   * @param bool $fully_normalize
   *   Optional. Sort configuration keys when exporting, and strip any empty
   *   arrays. This ensures more reliability when comparing between source and
   *   target config but usually means unnecessary changes.
   *
   * @return array|bool
   *   Array of any errors, keyed by extension names, FALSE if configuration
   *   changes could not be found to import, or TRUE on successful export.
   */
  public function exportExtension($extension, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $all = FALSE, $fully_normalize = FALSE);

  /**
   * Export configuration to all extensions using this workflow.
   *
   * Configuration should be exported to any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   * @param bool $all
   *   Optional. Without this option, any config listed as 'create_only' is only
   *   exported when it has not previously been exported. Set this option to
   *   overwrite any such config even if it has been previously exported.
   * @param bool $fully_normalize
   *   Optional. Sort configuration keys when exporting, and strip any empty
   *   arrays. This ensures more reliability when comparing between source and
   *   target config but usually means unnecessary changes.
   *
   * @return array|bool
   *   Array of any errors, keyed by extension names, FALSE if configuration
   *   changes could not be found to import, or TRUE on successful export.
   */
  public function exportAll($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $all = FALSE, $fully_normalize = FALSE);

  /**
   * Get cm_config_tools info from extension's .info.yml file.
   *
   * @param string $type
   *   Type of extension; either 'module', 'theme' or 'profile'.
   * @param string $extension_name
   *   Extension name.
   * @param string $key
   *   If specified, return the value for the specific key within the
   *   cm_config_tools info.
   * @param mixed $default
   *   The default value to return when $key is specified and there is no value
   *   for it. Has no effect if $key is not specified.
   * @param string $parent
   *   Defaults to cm_config_tools, but specify to get a key within a different
   *   parent key in the .info.yml file. Specify as NULL to get a root key's
   *   values.
   * @param bool $disabled
   *   Optionally check for disabled modules and themes too.
   *
   * @return bool|mixed
   *   If $key was not specified, just return TRUE or FALSE, depending on
   *   whether there is any cm_config_tools info for the extension at all.
   */
  public function getExtensionInfo($type, $extension_name, $key = NULL, $default = NULL, $parent = 'cm_config_tools');

  /**
   * Gets the type for the given extension.
   *
   * @param string $extension
   *   Extension name
   * @param bool $disabled
   *   Optionally check for disabled modules and themes too.
   *
   * @return string
   *   Either 'module', 'theme', 'profile', or FALSE if no valid (enabled)
   *   extension provided.
   */
  public function getExtensionType($extension, $disabled = FALSE);

  /**
   * Normalize configuration to get helpful diffs.
   *
   * Unfortunately \Drupal\config_update\ConfigDiffer::normalize() is a
   * protected method, so it cannot be called without wrapping that class, which
   * isn't really worth it, especially as a couple of extra parameters can be
   * introduced here to behave differently for certain situations.
   *
   * @param string $name
   *   Configuration item name.
   * @param array $config
   *   Configuration array to normalize.
   * @param bool $sort_and_filter
   *   Fully normalize the configuration, by sorting keys and filtering empty
   *   arrays. Defaults to TRUE.
   * @param array $ignore
   *   Keys to ignore. Defaults to 'uuid' and '_core'.
   *
   * @return array
   *   Normalized configuration array.
   *
   * @see \Drupal\config_update\ConfigDiffer::normalize()
   */
  public static function normalizeConfig($name, $config, $sort_and_filter = TRUE, $ignore = array('uuid', '_core'));

}
