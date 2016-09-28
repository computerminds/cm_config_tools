<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandler.
 */

namespace Drupal\cm_config_tools;

use Drupal\cm_config_tools\Exception\ExtensionConfigConflictException;
use Drupal\cm_config_tools\Exception\ExtensionConfigLockedException;
use Drupal\config_update\ConfigDiffInterface;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Config\StorageException;
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
   *   Array of source directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import.
   *
   * @return bool
   *   TRUE if the operation succeeded; FALSE if the configuration changes could
   *   not be found to import.
   */
  protected function importExtensionDirectories($source_dirs, $subdir) {
    if ($storage_comparer = $this->getStorageComparer($source_dirs, $subdir)) {
      return $this->importFromComparer($storage_comparer);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Perform import of configuration from the supplied comparer.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return bool
   *   TRUE if the operation succeeded; May throw a
   *   \Drupal\Core\Config\ConfigException if there is a problem during saving
   *   the configuration.
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
      // Calling code should handle any ConfigException.
      $config_importer->import();
      return TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionDirectories($extension) {
    $source_dirs = array();
    if (!is_array($extension)) {
      $extension = array_map('trim', explode(',', $extension));
    }
    foreach ($extension as $extension_name) {
      // Determine the type of extension we're dealing with.
      if ($type = $this->getExtensionType($extension_name)) {
        if ($extension_path = drupal_get_path($type, $extension_name)) {
          $source_dirs[$extension_path] = $extension_name;
        }
      }
    }
    return $source_dirs;
  }

  /**
   * {@inheritdoc}
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
        if ($this->getExtensionInfo($extension_name)) {
          $source_dirs[$extension->getPath()] = $extension_name;
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
      $unmanaged = $this->getExtensionInfo($extension_name, 'unmanaged', array());
      if (is_array($unmanaged)) {
        $unmanaged = array_flip($unmanaged);
      }
      else {
        $unmanaged = array();
      }

      $file_storage = new FileStorage($source_dir . '/' . $subdir);
      foreach ($file_storage->listAll() as $name) {
        if ($mapped = $source_storage->getMapping($name)) {
          throw new ExtensionConfigConflictException("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '$mapped' extensions.");
        }

        // Replace data if it is not listed as unmanaged (i.e. should only be
        // installed once), or does not yet exist.
        $source_storage->map($name, $extension_name);
        if (!isset($unmanaged[$name]) || !$source_storage->exists($name)) {
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

  protected function sortAndFilterOutput($dependencies) {
    // Sort and filter dependency lists, and finally change to simple indexed
    // array lists. This means the output can then be directly copied for use
    // in a .info.yml file if the output format is YAML.
    foreach (array_keys($dependencies) as $dependency_type) {
      if ($dependencies[$dependency_type]) {
        sort($dependencies[$dependency_type]);
        $dependencies[$dependency_type] = array_values($dependencies[$dependency_type]);
      }
      else {
        unset($dependencies[$dependency_type]);
      }
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionConfigDependencies($extension, $limit = NULL) {
    $dependencies = array();
    $listed_config = $this->getExtensionInfo($extension, 'managed');
    foreach ($listed_config as $key) {
      $config_dependencies = $this->getIndividualConfigDependencies($key, $limit);
      foreach ($config_dependencies as $dependency_type => $type_dependencies) {
        if (!isset($dependencies[$dependency_type])) {
          $dependencies[$dependency_type] = array();
        }
        $dependencies[$dependency_type] += $type_dependencies;
      }
    }
    // Exclude 'core' and the specified extension from the full list.
    unset($dependencies['module']['core']);
    unset($dependencies['module'][$extension]);
    return $this->sortAndFilterOutput($dependencies);
  }

  /**
   * @param $name
   *   The config key to find the dependencies of.
   * @param null $recursion_limit
   *   Optionally limit the levels of recursion.
   * @return mixed
   */
  public function getIndividualConfigDependencies($name, $recursion_limit = NULL) {
    static $recursive_iterations = 0;
    static $checked = array();

    if (!isset($checked[$name])) {
      $config_dependencies = \Drupal::config($name)->get('dependencies');
      if ($config_dependencies && is_array($config_dependencies)) {
        foreach ($config_dependencies as $dependency_type => $type_dependencies) {
          // Use associative array to avoid duplicates.
          $config_dependencies[$dependency_type] = array_combine($type_dependencies, $type_dependencies);
        }

        // Recurse to find sub-dependencies.
        if (isset($config_dependencies['config'])) {
          $recursive_iterations++;
          if ($recursion_limit && $recursive_iterations < $recursion_limit) {
            foreach ($config_dependencies['config'] as $dependency) {
              $sub_dependencies = $this->getIndividualConfigDependencies($dependency, $recursion_limit);

              // Add this dependency's dependencies to the list to be returned.
              foreach ($sub_dependencies as $dependency_type => $type_dependencies) {
                if (!isset($config_dependencies[$dependency_type])) {
                  $config_dependencies[$dependency_type] = array();
                }
                $config_dependencies[$dependency_type] += $type_dependencies;
              }
            }
          }
          $recursive_iterations--;
        }
      }
      else {
        $config_dependencies = array();
      }

      // Config provider is an implied module dependency.
      $config_provider = substr($name, 0, strpos($name, '.'));
      $config_dependencies['module'][$config_provider] = $config_provider;

      $checked[$name] = $config_dependencies;
    }

    return $checked[$name];
  }


  /**
   * Suggest config to manage, based on currently managed config.
   *
   * For the config listed in a projects .info.yml, find other config that is
   * dependant upon it, but which is not:
   *  - Itself a dependency of the config listed in cm_config_tools.managed
   *  - Already included explicitly in cm_config_tools.managed
   *  - Explicitly ignored in the cm_config_tools.unmanaged
   *
   * @param string $extension
   *   The machine name of the project to find suggested config for.
   * @return array
   *   An array of config suggestions.
   */
  public function getExtensionConfigSuggestions($extension, $recursion_limit = NULL) {
    $dependants = array();
    $listed_config = $this->getExtensionInfo($extension, 'managed');
    $dependency_manager = \Drupal::service('config.manager')
      ->getConfigDependencyManager();
    foreach ($listed_config as $key) {
      // Recursively fetch configuration entities that are dependent on this
      // configuration entity (i.e. reverse dependencies).
      $dependants += $this->getIndividualConfigDependants($key, $dependency_manager, $recursion_limit);
    }
    return $this->sortAndFilterOutput(array('config' => $dependants));
  }

  /**
   * @param $key
   *   The config key to find the dependencies of.
   * @param $dependency_manager
   *
   * @param null $recursion_limit
   * @return mixed
   */
  public function getIndividualConfigDependants($key, $dependency_manager, $recursion_limit = NULL) {
    static $recursive_iterations = 0;
    static $checked = array();

    if (!isset($checked[$key])) {
      /** @var Drupal\Core\Config\Entity\ConfigDependencyManager $dependency_manager */
      if ($dependants = array_keys($dependency_manager->getDependentEntities('config', $key))) {
        // Use associative array to avoid duplicates.
        $dependants = array_combine($dependants, $dependants);

        $recursive_iterations++;
        if ($recursion_limit && $recursive_iterations < $recursion_limit) {
          $base_dependants = $dependants;
          foreach ($base_dependants as $dependant) {
            if ($sub_dependants = $this->getIndividualConfigDependants($dependant, $dependency_manager, $recursion_limit)) {
              $dependants += $sub_dependants;
            }
          }
        }
        $recursive_iterations--;
      }

      $checked[$key] = $dependants;
    }

    return $checked[$key];
  }

  /**
   * {@inheritdoc}
   */
  public function addToManagedConfig($extension, $config_keys) {

  }


  /**
   * {@inheritdoc}
   */
  public function exportExtension($extension, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $all = FALSE, $fully_normalize = FALSE) {
    if ($extension_dirs = $this->getExtensionDirectories($extension)) {
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
    if ($extension_dirs = $this->getAllExtensionDirectories()) {
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
   *   Array of target directories as keys, mapped to their project names.
   * @param string $subdir
   *   The sub-directory of configuration to import.
   * @param bool $all
   *   When set to FALSE, any config listed as 'unmanaged' is only exported
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

    foreach ($extension_dirs as $source_dir => $extension_name) {
      // Determine the type of extension we're dealing with.
      if ($type = $this->getExtensionType($extension_name, TRUE)) {
        // Get the configuration.
        $info = $this->getExtensionInfo($extension_name, 'managed', array(), TRUE);

        if ($info && is_array($info)) {
          // Exclude any unmanaged items.
          $unmanaged = $this->getExtensionInfo($extension_name, 'unmanaged', array(), TRUE);
          if ($unmanaged && is_array($unmanaged)) {
            $unmanaged = array_flip($unmanaged);
          }
          else {
            $unmanaged = array();
          }

          try {
            $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
            foreach ($info as $name) {
              $config = \Drupal::config($name);
              if ($data = $config->get()) {
                $existing_export = $source_dir_storage->read($name);

                // Skip existing config that is listed as 'unmanaged' unless the
                // 'all' option was passed.
                if ($existing_export && isset($unmanaged[$name]) && !$all) {
                  continue;
                }

                if (!$existing_export || !$this->configDiff->same($data, $existing_export)) {
                  // @TODO Config to export could be differently sorted to
                  // an existing export, which is just unnecessary change.
                  $data = static::normalizeConfig($name, $data, $fully_normalize);
                  $source_dir_storage->write($name, $data);
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
      else {
        $errors[$extension_name][] = "Couldn't export configuration. The '$extension_name' extension was not found.";
      }
    }

    return $errors ? $errors : TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionInfo($extension_name, $key = NULL, $default = NULL, $disabled = FALSE) {
    if ($type = $this->getExtensionType($extension_name, $disabled)) {
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
    if ($this->moduleHandler->moduleExists($extension)) {
      $type = 'module';
    }
    elseif ($this->themeHandler->themeExists($extension)) {
      $type = 'theme';
    }
    elseif (drupal_get_profile() === $extension) {
      $type = 'profile';
    }
    elseif ($disabled) {
      // If still unknown, retrieve the file list prepared in state by
      // system_rebuild_module_data() and
      // \Drupal\Core\Extension\ThemeHandlerInterface::rebuildThemeData().
      foreach (['module', 'theme'] as $candidate) {
        if (\Drupal::state()->get('system.' . $candidate . '.files', array())) {
          $type = $candidate;
          break;
        }
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
