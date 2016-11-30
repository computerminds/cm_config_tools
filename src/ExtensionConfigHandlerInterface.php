<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandlerInterface.
 */

namespace Drupal\cm_config_tools;

use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageComparerInterface;

/**
 * Defines a class to help with importing and exporting config from extensions.
 */
interface ExtensionConfigHandlerInterface {

  /**
   * Flag for exporting config with their dependencies, except those already
   * provided by any of the extension's dependencies.
   */
  const WITH_DEPENDENCIES_NOT_PROVIDED = -1;

  /**
   * Import configuration from all extensions for this workflow.
   *
   * Configuration should be imported from any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   * @param bool $force_unmanaged
   *   Optional. Without this option, any config listed as 'unmanaged' is only
   *   considered when it has not previously been created. Set this option to
   *   overwrite any such config even if it has been previously created.
   *
   * @return array
   *   Return any error messages logged during the import.
   */
  public function import($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE);

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
   *   Array of extension types ('module', 'theme'), mapped to arrays of
   *   directories of extensions to import from, mapped to their project names.
   *   Each array should be sorted with most dependent extensions last, to allow
   *   for easier use where extensions need processing in dependency order.
   */
  public function getExtensionDirectories($disabled = FALSE);

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
   * @param bool $force_unmanaged
   *   Optional. Without this option, any config listed as 'unmanaged' is only
   *   considered when it has not previously been created. Set this option to
   *   overwrite any such config even if it has been previously created.
   *
   * @return bool|ConfigDiffStorageComparer
   *   The storage comparer; FALSE if configuration changes could not be found
   *   to import.
   */
  public function getStorageComparer(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE);

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
   * Get the config dependencies for an extension.
   *
   * For the config currently exported to a project, find the config
   * dependencies required for it to work.
   *
   * @param string $extension
   *   The machine name of the project to get the config dependencies for.
   * @param string $type
   *   Optionally supply the type of extension.
   * @param bool $exclude_provided_dependencies
   *   By default, get all dependencies. Optionally set to TRUE to exclude
   *   those that are already provided by listed dependencies of the extension
   *   (whether they are directly or indirectly dependent).
   * @param int $recursion_limit
   *   Optionally limit the levels of recursion.
   *
   * @return array
   *   An array of things the extension depends on, keyed by dependency type.
   */
  public function getExtensionConfigDependencies($extension, $type = NULL, $exclude_provided_dependencies = FALSE, $with_unmanaged = TRUE, $recursion_limit = NULL);

  /**
   * Get the dependencies for a single config item.
   *
   * @param string $config_name
   *   The config name to find the dependencies of.
   * @param int $recursion_limit
   *   Optionally limit the levels of recursion.
   *
   * @return array
   *   An array of the config's dependencies, keyed by dependency type.
   */
  public function getConfigDependencies($config_name, $recursion_limit = NULL);

  /**
   * Suggest config to manage, based on currently managed config.
   *
   * @param string $extension
   *   The machine name of the project to find suggested config for.
   * @param int $recursion_limit
   *   Optional recursion limit.
   *
   * @return array
   *   An array of config suggestions.
   */
  public function getExtensionConfigSuggestions($extension, $recursion_limit = NULL);

  /**
   * Suggest config to manage, based on a config item.
   *
   * @param string $config_name
   *   The config entity name to find the dependencies of.
   * @param \Drupal\Core\Config\Entity\ConfigDependencyManager $dependency_manager
   *   Dependency manager class.
   * @param int $recursion_limit
   *   Optionally limit the levels of recursion.
   *
   * @return array
   *   Associative array of config names.
   */
  public function getConfigSuggestions($config_name, ConfigDependencyManager $dependency_manager, $recursion_limit = NULL);

  /**
   * Adds the provided config to an extension's managed config.
   *
   * Add the config names to an extension's .info.yml, under
   * cm_config_tools.managed.
   *
   * @param string $extension
   *   Extension name to export to.
   * @param array $config_names
   *
   * @return bool
   *   Returns TRUE when successful.
   */
  public function addToManagedConfig($extension, $config_names);

  /**
   * Export configuration to all extensions using this workflow.
   *
   * Configuration should be exported to any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @param bool|int $with_dependencies
   *   Export configuration together with its dependencies. Can take the special
   *   value -1 to smartly export dependencies, so those provided by other
   *   dependencies that use cm_config_tools will not be exported again.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   * @param bool $force_unmanaged
   *   Optional. Without this option, any config listed as 'unmanaged' is only
   *   exported when it has not previously been exported. Set this option to
   *   overwrite any such config even if it has been previously exported.
   * @param bool $fully_normalize
   *   Optional. Sort keys within configuration exports, and strip any empty
   *   arrays. This ensures more reliability when comparing between source and
   *   target config but usually means unnecessary changes.
   *
   * @return array|bool
   *   Array of any errors, keyed by extension names, FALSE if configuration
   *   changes could not be found to import, or TRUE on successful export.
   *
   * @TODO Optionally suggest config dependants to export to (allowing opt-out
   * of getting suggestions, and if possible allowing opt-in to export all
   * dependants).
   */
  public function export($with_dependencies = TRUE, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE, $fully_normalize = FALSE);

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
   *
   * @return bool|mixed
   *   If $key was not specified, just return all cm_config_tools info or FALSE.
   */
  public function getExtensionInfo($type, $extension_name, $key = NULL, $default = NULL);

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
   * @param string $config_name
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
  public static function normalizeConfig($config_name, $config, $sort_and_filter = TRUE, $ignore = array('uuid', '_core'));

}
