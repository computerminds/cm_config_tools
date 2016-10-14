<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandler.
 */

namespace Drupal\cm_config_tools;

use Drupal\cm_config_tools\Exception\ExtensionConfigConflictException;
use Drupal\cm_config_tools\Exception\ExtensionConfigLockedException;
use Drupal\config_update\ConfigDiffInterface;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Config\StorageException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a service to help with importing and exporting config from extensions.
 */
class ExtensionConfigHandler implements ExtensionConfigHandlerInterface {

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
   * {@inheritdoc}
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
   * {@inheritdoc}
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
   *   Array of extension types mapped to arrays of source directories mapped to
   *   their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import.
   *
   * @return array
   *   Return any error messages logged during the import.
   */
  protected function importExtensionDirectories($source_dirs, $subdir) {
    if ($storage_comparer = $this->getStorageComparer($source_dirs, $subdir)) {
      return $this->importFromComparer($storage_comparer);
    }
    else {
      return array($this->t('No configuration changes could not be found to import'));
    }
  }

  /**
   * Perform import of configuration from the supplied comparer.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return array
   *   Return any error messages logged during the import.
   *
   * @throws ExtensionConfigLockedException
   *
   * @see _drush_config_import()
   */
  public function importFromComparer(StorageComparerInterface $storage_comparer) {
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
      try {
        $config_importer->import();
        return $config_importer->getErrors();
      }
      catch (ConfigException $e) {
        $errors = $config_importer->getErrors();
        $errors[] = $e->getMessage();
        return $errors;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionDirectories($extension, $disabled = FALSE) {
    $source_dirs = array();
    if (!is_array($extension)) {
      $extension = array_map('trim', explode(',', $extension));
    }
    foreach ($extension as $extension_name) {
      // Determine the type of extension we're dealing with.
      if ($type = $this->getExtensionType($extension_name, $disabled)) {
        if ($extension_path = drupal_get_path($type, $extension_name)) {
          $source_dirs[$type][$extension_path] = $extension_name;
        }
      }
    }
    return $source_dirs;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllExtensionDirectories($disabled = FALSE) {
    $source_dirs = array();
    $extensions = array(
      'module' => array(),
      'theme' => array(),
    );
    if ($disabled) {
      $state = \Drupal::state();
      foreach (array_keys($extensions) as $type) {
        $extensions[$type] = $state->get('system.' . $type . '.files', array());
      }
    }
    else {
      $extensions['module'] = $this->moduleHandler->getModuleList();
      $extensions['theme'] = $this->themeHandler->listInfo();
    }

    foreach ($extensions as $type => $type_extensions) {
      foreach ($type_extensions as $extension_name => $extension) {
        if ($this->getExtensionInfo($type, $extension_name)) {
          if ($extension instanceof Extension) {
            $source_dirs[$type][$extension->getPath()] = $extension_name;
          }
          elseif (is_string($extension)) {
            $extension_path = substr($extension, 0, -(strlen('/' . $extension_name . '.info.yml')));
            $source_dirs[$type][$extension_path] = $extension_name;
          }
        }
      }
    }
    return $source_dirs;
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageComparer(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY) {
    $storage_comparer = new ConfigDiffStorageComparer(
      $this->getSourceStorageWrapper($source_dirs, $subdir),
      $this->activeConfigStorage,
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
   *   Array of extension types mapped to arrays of source directories mapped to
   *   their project names.
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

    foreach ($source_dirs as $type => $type_source_dirs) {
      foreach ($type_source_dirs as $source_dir => $extension_name) {
        $create_only = $this->getExtensionInfo($type, $extension_name, 'create_only', array());
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

        $deletes = $this->getExtensionInfo($type, $extension_name, 'delete', array());
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
    }

    return $source_storage;
  }

  /**
   * {@inheritdoc}
   */
  public function exportExtension($extension, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $all = FALSE, $fully_normalize = FALSE) {
    if ($extension_dirs = $this->getExtensionDirectories($extension, TRUE)) {
      return $this->exportExtensionDirectories($extension_dirs, $subdir, $all, $fully_normalize);
    }
    else {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exportAll($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $all = FALSE, $fully_normalize = FALSE) {
    if ($extension_dirs = $this->getAllExtensionDirectories(TRUE)) {
      return $this->exportExtensionDirectories($extension_dirs, $subdir, $all, $fully_normalize);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Export configuration to extension directories.
   *
   * @param array $extension_dirs
   *   Array of extension types mapped to arrays of target directories mapped to
   *   their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import.
   * @param bool $all
   *   When set to FALSE, any config listed as 'create_only' is only exported
   *   when it has not previously been exported. Set to TRUE to overwrite any
   *   such config even if it has been previously exported.
   * @param bool $fully_normalize
   *   Set to TRUE to sort configuration keys when exporting, and strip any
   *   empty arrays. This ensures more reliability when comparing between source
   *   and target config but usually means unnecessary changes.
   *
   * @return array|bool
   *   Array of any errors, keyed by extension names, FALSE if configuration
   *   changes could not be found to import, or TRUE on successful export.
   *
   * @see drush_config_devel_export()
   * @see drush_config_devel_get_config()
   * @see drush_config_devel_process_config()
   */
  protected function exportExtensionDirectories($extension_dirs, $subdir, $all, $fully_normalize) {
    $errors = [];
    $files_written = [];

    foreach ($extension_dirs as $type => $type_source_dirs) {
      foreach ($type_source_dirs as $source_dir => $extension_name) {
        if (strpos($subdir, 'config/') === 0) {
          $subdir_type = substr($subdir, 7);
          // Get the configuration.
          $info = $this->getExtensionInfo($type, $extension_name, 'config_devel', array(), NULL);
          // Keep backwards compatibility for the old format that config_devel
          // supports.
          if (!isset($info['install'])) {
            $info['install'] = $info;
          }

          if (isset($info[$subdir_type]) && is_array($info[$subdir_type])) {
            // Exclude any create_only items.
            $create_only = $this->getExtensionInfo($type, $extension_name, 'create_only', array());
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

                    // Skip existing config that is listed as 'create only'
                    // unless the 'all' option was passed.
                    if ($existing_export && isset($create_only[$name]) && !$all) {
                      continue;
                    }

                    if (!$existing_export || !$this->configDiff->same($data, $existing_export)) {
                      // @TODO Config to export could be differently sorted to
                      // an existing export, which is just unnecessary change.
                      $data = static::normalizeConfig($name, $data, $fully_normalize);
                      $source_dir_storage->write($name, $data);
                      $files_written[$extension_name][] = $name;
                    }
                  }
                  else {
                    $errors[$extension_name][] = 'Config ' . $name . ' not found in active storage.';
                  }
                }
              }
              catch (StorageException $e) {
                $errors[$extension_name][] = $e->getMessage();
                continue;
              }
            }
          }
        }
      }
    }

    if (empty($files_written)) {
      return FALSE;
    }

    return $errors ? $errors : TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionInfo($type, $extension_name, $key = NULL, $default = NULL, $parent = 'cm_config_tools') {
    if ($extension_path = drupal_get_path($type, $extension_name)) {
      // Info parser service statically caches info files, so it does not
      // matter that file may already have been parsed by this class.
      $info_filename = $extension_path . '/' . $extension_name .'.info.yml';
      $info = $this->infoParser->parse($info_filename);

      if ($key) {
        if ($parent) {
          return isset($info[$parent][$key]) ? $info[$parent][$key] : $default;
        }
        else {
          return isset($info[$key]) ? $info[$key] : $default;
        }
      }
      else {
        if ($parent) {
          return array_key_exists($parent, $info);
        }
        else {
          return FALSE;
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
   * @param bool $disabled
   *   Optionally check for disabled modules and themes too.
   *
   * @return string
   *   Either 'module', 'theme', 'profile', or FALSE if no valid (enabled)
   *   extension provided.
   *
   * @see drush_config_devel_get_type()
   * @see drupal_get_filename()
   */
  public function getExtensionType($extension, $disabled = FALSE) {
    $type = NULL;
    if ($disabled) {
      // Retrieve the full file lists prepared in state by
      // system_rebuild_module_data() and
      // \Drupal\Core\Extension\ThemeHandlerInterface::rebuildThemeData(). These
      // are cached by \Drupal\Core\State\State so repeatedly fetching is fine.
      $state = \Drupal::state();
      foreach (['module', 'theme'] as $candidate) {
        $extension_data = $state->get('system.' . $candidate . '.files', array());

        if (isset($extension_data[$extension])) {
          $type = $candidate;
          break;
        }
      }
    }
    else {
      if ($this->moduleHandler->moduleExists($extension)) {
        $type = 'module';
      }
      elseif ($this->themeHandler->themeExists($extension)) {
        $type = 'theme';
      }
      elseif (drupal_get_profile() === $extension) {
        $type = 'profile';
      }
    }


    return $type;
  }

  /**
   * {@inheritdoc}
   */
  public static function normalizeConfig($name, $config, $sort_and_filter = TRUE, $ignore = array('uuid', '_core')) {
    // Remove "ignore" elements.
    foreach ($ignore as $element) {
      unset($config[$element]);
    }

    // Recursively normalize remaining elements, if they are arrays.
    foreach ($config as $key => $value) {
      if (is_array($value)) {
        // Image style effects are a special case, since they have to use UUIDs
        // as their keys, so remove that from the ignore list. Remove this once
        // core issue https://www.drupal.org/node/2247257 is fixed.
        if (isset($value['uuid']) && $value['uuid'] === $key && in_array('uuid', $ignore) && preg_match('#^image\.style\.[^.]+\.effects#', $name)) {
          $new = static::normalizeConfig($name . '.' . $key, $value, $sort_and_filter, array_diff($ignore, array('uuid')));
        }
        else {
          $new = static::normalizeConfig($name . '.' . $key, $value, $sort_and_filter, $ignore);
        }

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

}
