<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandler.
 */

namespace Drupal\cm_config_tools;

use Drupal\config\StorageReplaceDataWrapper;
use Drupal\config_update\ConfigDiffInterface;
use Drupal\Core\Config\ConfigException;
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
   *
   * @see _drush_config_import()
   */
  public function import($extension, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    if ($source_dirs = $this->getSourceDirectories($extension)) {
      return $this->importSourceDirectories($source_dirs, $subdir);
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
   *
   * @see _drush_config_import()
   */
  public function importAll($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    if ($source_dirs = $this->getAllSourceDirectories()) {
      return $this->importSourceDirectories($source_dirs, $subdir);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Import configuration from source directories.
   *
   * @TODO Trigger an event if the configuration could be imported?
   *
   * @param array $source_dirs
   *   Array of source directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return bool
   *   TRUE if the operation succeeded; FALSE if the configuration changes could
   *   not be found to import. May also throw exceptions if there is a problem
   *   during saving the configuration.
   *
   * @throws \Exception
   * @TODO Custom exception type please.
   *
   * @see _drush_config_import()
   */
  protected function importSourceDirectories($source_dirs, $subdir) {
    if ($config_importer = $this->getImporter($source_dirs, $subdir)) {
      if ($config_importer->alreadyImporting()) {
        throw new \Exception('Another request may be synchronizing configuration already.');
      }
      else {
        try {
          $config_importer->import();
          return TRUE;
        }
        catch (ConfigException $e) {
          // Return a negative result for UI purposes. We do not differentiate
          // between an actual synchronization error and a failed lock,
          // because concurrent synchronizations are an edge-case happening
          // only when multiple developers or site builders attempt to do it
          // without coordinating.
          $message = 'The import failed due for the following reasons:' . "\n";
          $message .= implode("\n", $config_importer->getErrors());

          watchdog_exception('config_import', $e);
          throw new \Exception($message);
        }
      }
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get configuration source directories from extensions.
   *
   * @param string|array $extension
   *   The machine name of the project to import configuration from. Multiple
   *   projects can be specified, separated with commas, or as an array.
   *
   * @return array
   *   Array of source directories to import from, mapped to project names.
   */
  public function getSourceDirectories($extension) {
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
   * Get configuration source directories from all extensions for this workflow.
   *
   * Configuration will be imported from any enabled projects that contain a
   * 'cm_config_tools' key in their .info.yml files (even if it is empty).
   *
   * @return array
   *   Array of source directories to import from, mapped to project names.
   */
  public function getAllSourceDirectories() {
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
   * Get the actual config importer that will do the importing.
   *
   * @param array $source_dirs
   *   Array of source directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import. Defaults to
   *   "config/install".
   *
   * @return bool|\Drupal\Core\Config\ConfigImporter
   *   TRUE if the operation succeeded; FALSE if the configuration changes could
   *   not be found to import. May also throw exceptions if there is a problem
   *   during saving the configuration.
   *
   * @throws \Exception
   * @TODO Custom exception types please.
   */
  public function getImporter(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    $source_storage = new StorageReplaceDataWrapper($this->activeConfigStorage);
    $config_extension_map = array();

    foreach ($source_dirs as $source_dir => $extension_name) {
      // Info parser service statically caches info files, so it does not matter
      // if file has previously been parsed above.
      $info_filename = $source_dir . '/' . $extension_name .'.info.yml';
      $info = $this->infoParser->parse($info_filename);
      $create_only = isset($info['cm_config_tools']['create_only']) ? array_flip($info['cm_config_tools']['create_only']) : array();

      $file_storage = new FileStorage($source_dir . '/' . $subdir);
      foreach ($file_storage->listAll() as $name) {
        if (isset($config_extension_map[$name])) {
          throw new \Exception("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '{$config_extension_map[$name]}' extensions.");
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
            throw new \Exception("Could not import configuration because the configuration item '$name' is provided by the '$extension_name' extension but also listed for deletion.");
          }
          else {
            throw new \Exception("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '{$config_extension_map[$name]}' extensions.");
          }
        }

        // Replacing as an empty array simulates an item being deleted.
        $source_storage->replaceData($name, array());
        $config_extension_map[$name] = $extension_name;
      }
    }

    // Use a custom storage comparer, based on one from an old version of
    // config_sync, that uses a more useful differ in config_update that
    // ignores changes to UUIDs and the '_core' property.
    $storage_comparer = new ConfigDiffStorageComparer($source_storage, $this->activeConfigStorage, $this->configManager, $this->configDiff);
    if ($storage_comparer->createChangelist()->hasChanges()) {
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

      $this->setConfigExtensionMap($config_extension_map);
      return $config_importer;
    }
    else {
      return FALSE;
    }
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

  /**
   * Set the map of config names to extension names.
   *
   * @param array $config_extension_map
   *   The map to set.
   */
  protected function setConfigExtensionMap($config_extension_map) {
    $this->configExtensionMap = $config_extension_map;
  }

  /**
   * Get the map of config names to extension names.
   *
   * @return array
   *   Map of config names to extension names.
   */
  public function getConfigExtensionMap() {
    return $this->configExtensionMap;
  }


}
