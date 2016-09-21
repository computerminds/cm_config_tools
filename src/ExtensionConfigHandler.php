<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandler.
 */

namespace Drupal\cm_config_tools;

use Drupal\config_update\ConfigDiffInterface;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a service to help with importing config from extensions.
 */
class ExtensionConfigHandler {

  use StringTranslationTrait;

  /**
   * The active config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeConfigStorage;

  /**
   * The info parser to parse the extensions' .info.yml files.
   *
   * @var \Drupal\Core\Extension\InfoParserInterface
   */
  protected $infoParser;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The config differ.
   *
   * @var \Drupal\config_update\ConfigDiffInterface
   */
  protected $configDiff;

  /**
   * The used lock backend instance.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $dispatcher;

  /**
   * Map of config names to source extension's name.
   *
   * @var array
   */
  protected $configExtensionMap;

  /**
   * Constructs a ConfigImporter.
   *
   * @param \Drupal\Core\Config\StorageInterface $active_config_storage
   *   The active config storage.
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info parser to parse the extensions' .info.yml files.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The configuration manager.
   * @param \Drupal\config_update\ConfigDiffInterface $config_diff
   *   The config differ.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend to ensure multiple imports do not occur at the same time.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed configuration manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   String translation service.
   */
  public function __construct(StorageInterface $active_config_storage, InfoParserInterface $info_parser, ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, ThemeHandlerInterface $theme_handler, ConfigManagerInterface $config_manager, ConfigDiffInterface $config_diff, LockBackendInterface $lock, TypedConfigManagerInterface $typed_config, EventDispatcherInterface $dispatcher, TranslationInterface $translation) {
    $this->activeConfigStorage = $active_config_storage;
    $this->infoParser = $info_parser;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->themeHandler = $theme_handler;
    $this->configManager = $config_manager;
    $this->configDiff = $config_diff;
    $this->lock = $lock;
    $this->typedConfigManager = $typed_config;
    $this->dispatcher = $dispatcher;
    $this->stringTranslation = $translation;
  }

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
   * @return bool
   *   TRUE if the operation succeeded; FALSE if the configuration changes could
   *   not be found to import. May also throw exceptions if there is a problem
   *   during saving the configuration.
   */
  public function importExtension($extension, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    if ($extension_dirs = $this->getExtensionDirectories($extension)) {
      return $this->importExtensionDirectories($extension_dirs, $subdir);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get configuration source directories from all extensions for this workflow.
   *
   * Configuration will be imported from any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return bool
   *   TRUE if the operation succeeded; FALSE if the configuration changes could
   *   not be found to import. May also throw exceptions if there is a problem
   *   during saving the configuration.
   */
  public function importAll($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    if ($extension_dirs = $this->getAllExtensionDirectories()) {
      return $this->importExtensionDirectories($extension_dirs, $subdir);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Import configuration from extension directories.
   *
   * @param array $source_dirs
   *   Array of source directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return bool
   *   TRUE if the operation succeeded; FALSE if the configuration changes could
   *   not be found to import. May throw a \Drupal\Core\Config\ConfigException
   *   if there is a problem during saving the configuration.
   *
   * @throws ExtensionConfigLockedException
   *
   * @see _drush_config_import()
   */
  protected function importExtensionDirectories($source_dirs, $subdir) {
    if ($storage_comparer = $this->getStorageComparer($source_dirs, $subdir)) {
      $config_importer = new ConfigImporter(
        $storage_comparer,
        $this->dispatcher,
        $this->configManager,
        $this->lock,
        $this->typedConfigManager,
        $this->moduleHandler,
        $this->moduleInstaller,
        $this->themeHandler,
        $this->stringTranslation
      );

      if ($config_importer->alreadyImporting()) {
        throw new ExtensionConfigLockedException('Another request may be synchronizing configuration already.');
      }
      else {
        // Calling code should handle any ConfigException.
        $config_importer->import();
        return TRUE;
      }
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get directories of supplied extensions.
   *
   * @param string|array $extension
   *   The machine name of the project to import configuration from. Multiple
   *   projects can be specified, separated with commas, or as an array.
   *
   * @return array
   *   Array of directories of extensions to import from, mapped to their
   *   project names.
   */
  public function getExtensionDirectories($extension) {
    $source_dirs = array();
    if (!is_array($extension)) {
      $extension = array_map('trim', explode(',', $extension));
    }
    foreach ($extension as $extension_name) {
      // Determine the type of extension we're dealing with.
      if ($type = $this->detectExtensionType($extension_name)) {
        if ($extension_path = drupal_get_path($type, $extension_name)) {
          $source_dirs[$extension_path] = $extension_name;
        }
      }
    }
    return $source_dirs;
  }

  /**
   * Get directories of all extensions for this workflow.
   *
   * Configuration will be imported from any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @return array
   *   Array of directories of extensions to import from, mapped to their
   *   project names.
   */
  public function getAllExtensionDirectories() {
    $source_dirs = array();
    // Import configuration from any enabled extensions with the cm_config_tools
    // key in their .info.yml file.
    $module_dirs = $this->moduleHandler->getModuleList();
    $theme_dirs = $this->themeHandler->listInfo();
    foreach ([$module_dirs, $theme_dirs] as $extension_dirs) {
      /** @var \Drupal\Core\Extension\Extension[] $extension_dirs */
      foreach ($extension_dirs as $extension_name => $extension) {
        $info_filename = $extension->getPath() . '/' . $extension_name .'.info.yml';
        $info = $this->infoParser->parse($info_filename);
        if (array_key_exists('cm_config_tools', $info)) {
          $source_dirs[$extension->getPath()] = $extension_name;
        }
      }
    }
    return $source_dirs;
  }

  /**
   * Get the config storage comparer that will be used for importing.
   *
   * A custom storage comparer is used, based on one from an old version of the
   * config_sync project, that uses a more useful differ in config_update that
   * ignores changes to UUIDs and the '_core' property.
   *
   * @param array $source_dirs
   *   Array of source directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return bool|ConfigDiffStorageComparer
   *   The storage comparer; FALSE if configuration changes could not be found
   *   to import.
   */
  public function getStorageComparer(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    $storage_comparer = new ConfigDiffStorageComparer(
      $this->getSourceStorageWrapper($source_dirs, $subdir),
      $this->activeConfigStorage,
      $this->configManager,
      $this->configDiff
    );

    if ($storage_comparer->createChangelist()->hasChanges()) {
      return $storage_comparer;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get the config storage comparer that will be used for importing.
   *
   * This will use a custom storage replacement wrapper that keeps a map of
   * configuration to providing extension. This means potential conflicts can be
   * checked and cm_config_tools info for each extension can be respected, as
   * well as allow for nicer reporting.
   *
   * @param array $source_dirs
   *   Array of source directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return StorageReplaceDataMappedWrapper
   *   The source storage, wrapped to allow replacing specific configuration.
   *
   * @throws ExtensionConfigConflictException
   */
  protected function getSourceStorageWrapper(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    $source_storage = new StorageReplaceDataMappedWrapper($this->activeConfigStorage);

    foreach ($source_dirs as $source_dir => $extension_name) {
      $create_only = $this->getExtensionInfo($extension_name, 'create_only', array());
      if (is_array($create_only)) {
        $create_only = array_flip($create_only);
      }
      else {
        $create_only = array();
      }

      $file_storage = new FileStorage($source_dir . '/' . $subdir);
      foreach ($file_storage->listAll() as $name) {
        if ($mapped = $source_storage->getMapping($name)) {
          throw new ExtensionConfigConflictException("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '$mapped' extensions.");
        }

        // Replace data if it is not listed as create_only (i.e. should only be
        // installed once), or does not yet exist.
        $source_storage->map($name, $extension_name);
        if (!isset($create_only[$name]) || !$source_storage->exists($name)) {
          $data = $file_storage->read($name);
          $source_storage->replaceData($name, $data);
        }
      }

      $deletes = $this->getExtensionInfo($extension_name, 'delete', array());
      if (is_array($deletes)) {
        foreach ($deletes as $name) {
          if ($mapped = $source_storage->getMapping($name)) {
            if ($mapped == $extension_name) {
              throw new ExtensionConfigConflictException("Could not import configuration because the configuration item '$name' is provided by the '$extension_name' extension but also listed for deletion.");
            }
            else {
              throw new ExtensionConfigConflictException("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '$mapped' extensions.");
            }
          }

          // Replacing as an empty array simulates an item being deleted.
          $source_storage->replaceData($name, array());
          $source_storage->map($name, $extension_name);
        }
      }
    }

    return $source_storage;
  }

  /**
   * Get cm_config_tools info from extension's .info.yml file.
   *
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
   *   If $key was not specified, just return TRUE or FALSE, depending on
   *   whether there is any cm_config_tools info for the extension at all.
   */
  protected function getExtensionInfo($extension_name, $key = NULL, $default = NULL) {
    if ($type = $this->detectExtensionType($extension_name)) {
      if ($extension_path = drupal_get_path($type, $extension_name)) {
        // Info parser service statically caches info files, so it does not
        // matter that file may already have been parsed by this class.
        $info_filename = $extension_path . '/' . $extension_name .'.info.yml';
        $info = $this->infoParser->parse($info_filename);

        if ($key) {
          return isset($info['cm_config_tools'][$key]) ? $info['cm_config_tools'][$key] : $default;
        }
        else {
          return array_key_exists('cm_config_tools', $info);
        }
      }
    }

    return FALSE;
  }

  /**
   * Gets the type for the given extension.
   *
   * @param string $extension
   *   Extension name
   * @return string
   *   Either 'module', 'theme', 'profile', or FALSE if no valid (enabled)
   *   extension provided.
   *
   * @see drush_config_devel_get_type()
   */
  protected function detectExtensionType($extension) {
    $type = NULL;
    if ($this->moduleHandler->moduleExists($extension)) {
      $type = 'module';
    }
    elseif ($this->themeHandler->themeExists($extension)) {
      $type = 'theme';
    }
    elseif (drupal_get_profile() === $extension) {
      $type = 'profile';
    }

    return $type;
  }


}
