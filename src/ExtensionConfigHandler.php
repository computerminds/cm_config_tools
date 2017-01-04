<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ExtensionConfigHandler.
 */

namespace Drupal\cm_config_tools;

use Drupal\cm_config_tools\Exception\ExtensionConfigConflictException;
use Drupal\cm_config_tools\Exception\ExtensionConfigException;
use Drupal\cm_config_tools\Exception\ExtensionConfigLockedException;
use Drupal\config_update\ConfigDiffInterface;
use Drupal\config_update\ConfigListInterface;
use Drupal\config_update\ConfigRevertInterface;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Config\StorageException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\InfoParserInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a service to help with importing and exporting config from extensions.
 */
class ExtensionConfigHandler implements ExtensionConfigHandlerInterface {

  use StringTranslationTrait;

  /**
   * The indentation used, per level, for each section in an .info.yml file.
   */
  const MANIFEST_INDENT = '  ';

  /**
   * The characters to consider whitespace in an extension's .info.yml file.
   */
  const MANIFEST_WHITESPACE_CHARS = " \t\n\r\0\x0B";

  /**
   * The active config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeConfigStorage;

  /**
   * The info parser to parse the extensions' .info.yml files.
   *
   * @var \Drupal\cm_config_tools\ResettableInfoParser
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
   * The Key/Value Store to use for state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

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
   * The config listing service.
   *
   * @var \Drupal\config_update\ConfigListInterface
   */
  protected $configLister;

  /**
   * The service for reverting and importing config.
   *
   * @var \Drupal\config_update\ConfigRevertInterface
   */
  protected $configReverter;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ExtensionConfigHandler.
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
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue store.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed configuration manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   String translation service.
   * @param \Drupal\config_update\ConfigListInterface $config_lister
   *   Config listing service.
   * @param \Drupal\config_update\ConfigRevertInterface $config_reverter
   *   Service for reverting and importing config.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(StorageInterface $active_config_storage, InfoParserInterface $info_parser, ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, ThemeHandlerInterface $theme_handler, ConfigManagerInterface $config_manager, ConfigDiffInterface $config_diff, LockBackendInterface $lock, StateInterface $state, TypedConfigManagerInterface $typed_config, EventDispatcherInterface $dispatcher, TranslationInterface $translation, ConfigListInterface $config_lister, ConfigRevertInterface $config_reverter, EntityTypeManagerInterface $entity_type_manager) {
    $this->activeConfigStorage = $active_config_storage;
    $this->infoParser = $info_parser;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->themeHandler = $theme_handler;
    $this->configManager = $config_manager;
    $this->configDiff = $config_diff;
    $this->lock = $lock;
    $this->state = $state;
    $this->typedConfigManager = $typed_config;
    $this->dispatcher = $dispatcher;
    $this->stringTranslation = $translation;
    $this->configLister = $config_lister;
    $this->configReverter = $config_reverter;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function import($subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE) {
    if ($extension_dirs = $this->getExtensionDirectories()) {
      return $this->importExtensionDirectories($extension_dirs, $subdir, $force_unmanaged);
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
   * @param bool $force_unmanaged
   *   Without this option, any config listed as 'unmanaged' is only considered
   *   when it has not previously been created. Set this option to overwrite any
   *   such config even if it has been previously created.
   *
   * @return array
   *   Return any error messages logged during the import.
   */
  protected function importExtensionDirectories($source_dirs, $subdir, $force_unmanaged) {
    if ($storage_comparer = $this->getStorageComparer($source_dirs, $subdir, $force_unmanaged)) {
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
  public function getExtensionDirectories($disabled = FALSE) {
    $source_dirs = array();
    $extensions = array(
      'module' => array(),
      'theme' => array(),
    );
    if ($disabled) {
      foreach (array_keys($extensions) as $type) {
        $extensions[$type] = $this->state->get('system.' . $type . '.files', array());
      }
    }
    else {
      $extensions['module'] = $this->moduleHandler->getModuleList();
      $extensions['theme'] = $this->themeHandler->listInfo();
    }

    $extension_data['module'] = system_rebuild_module_data();
    $extension_data['theme'] = $this->themeHandler->rebuildThemeData();

    foreach ($extensions as $type => $type_extensions) {
      // Sort the extensions list by their weights (reverse), as their
      // installers would do.
      // @see \Drupal\Core\Extension\ModuleInstaller::install()
      // @see \Drupal\Core\Extension\ThemeInstaller::install()
      $names = array_keys($type_extensions);
      $names = array_map(function ($extension_name) use ($extension_data, $type) {
        return $extension_data[$type][$extension_name]->sort;
      }, array_combine($names, $names));
      arsort($names);

      foreach ($names as $extension_name => $extension_weight) {
        if ($this->getExtensionInfo($type, $extension_name)) {
          $extension = $type_extensions[$extension_name];
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
  public function getStorageComparer(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE) {
    $storage_comparer = new ConfigDiffStorageComparer(
      $this->getSourceStorageWrapper($source_dirs, $subdir, $force_unmanaged),
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
   * @param bool $force_unmanaged
   *   Optional. Without this option, any config listed as 'unmanaged' is only
   *   considered when it has not previously been created. Set this option to
   *   overwrite any such config even if it has been previously created.
   *
   * @return StorageReplaceDataMappedWrapper
   *   The source storage, wrapped to allow replacing specific configuration.
   *
   * @throws ExtensionConfigConflictException
   */
  protected function getSourceStorageWrapper(array $source_dirs, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE) {
    $source_storage = new StorageReplaceDataMappedWrapper($this->activeConfigStorage);
    $profile = drupal_get_profile();

    foreach ($source_dirs as $type => $type_source_dirs) {
      foreach ($type_source_dirs as $source_dir => $extension_name) {
        $info = $this->getExtensionInfo($type, $extension_name);
        $importable = $info['managed'] + $info['unmanaged'] + $info['implicit'];

        $file_storage = new FileStorage($source_dir . '/' . $subdir);
        // Only actually import config listed as managed, implicit, or
        // unmanaged. Anything else is only there to be installed as per normal
        // core behavior.
        foreach (array_intersect($importable, $file_storage->listAll()) as $name) {
          // Replace data if it is not listed as unmanaged (i.e. should only be
          // installed once), or does not yet exist.
          if (!isset($info['unmanaged'][$name]) || !$source_storage->exists($name)) {
            $mapped = $source_storage->getMapping($name);
            // Install profiles are allowed to override config from other
            // extensions.
            if ($mapped) {
              if ($mapped == $profile) {
                // Config was already written from install profile, or is about
                // to be, which is allowed.
                continue;
              }
              elseif ($extension_name != $profile) {
                throw new ExtensionConfigConflictException("Could not import configuration because the configuration item '$name' is found in both the '$extension_name' and '$mapped' extensions.");
              }
            }

            // Note: Any config marked as unmanaged that already exists will get
            // skipped and will not even get mapped. This does mean another
            // extension might also list it as managed or deleted.
            $source_storage->map($name, $extension_name);
            $data = $file_storage->read($name);
            $source_storage->replaceData($name, $data);
          }
        }

        foreach ($info['delete'] as $name) {
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
   * Sort and filter dependency lists.
   *
   * Also changes input array to a simple indexed array list. This means the
   * output can then be directly copied for use in an .info.yml file if the
   * output format is YAML.
   *
   * @param array $dependencies
   *   Array of config item dependencies, keyed by dependency type.
   * @param array $exclude
   *   Array of config items to filter out, keyed by dependency type.
   *
   * @return array
   *   Sorted and filtered array of dependencies, keyed by dependency type.
   */
  protected function sortAndFilterOutput($dependencies, $exclude = []) {
    foreach (array_keys($dependencies) as $dependency_type) {
      if ($dependencies[$dependency_type]) {
        if (isset($exclude[$dependency_type])) {
          $dependencies[$dependency_type] = array_diff($dependencies[$dependency_type], $exclude[$dependency_type]);
        }

        sort($dependencies[$dependency_type]);
        $dependencies[$dependency_type] = array_values(array_filter($dependencies[$dependency_type]));
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
  public function getExtensionConfigDependencies($extension, $type = NULL, $exclude_provided_dependencies = FALSE, $with_unmanaged = TRUE, $recursion_limit = NULL) {
    if (!isset($type)) {
      $type = $this->getExtensionType($extension, TRUE);
    }

    $dependencies = array();
    $exclude = array(
      $type => array(
        $extension => $extension,
      ),
    );

    $info = $this->getExtensionInfo($type, $extension);
    // When working out dependencies, we are replacing the existing implicit
    // dependencies so ignore those here.
    $importable = $info['managed'] + $info['unmanaged'];

    if ($importable) {
      $exclude['config'] = $importable;

      if ($exclude_provided_dependencies) {
        if ($type === 'theme') {
          $extension_data = $this->themeHandler->rebuildThemeData();
        }
        else {
          $extension_data = system_rebuild_module_data();
        }

        // Exclude any managed config in extensions using cm_config_tools from
        // the dependencies list, since those will already be exported. Also
        // exclude any extensions already listed as dependencies, and all their
        // dependencies (direct or indirect).
        if (isset($extension_data[$extension]->requires)) {
          $extension_requirements = array_keys($extension_data[$extension]->requires);
          $exclude[$type] += array_combine($extension_requirements, $extension_requirements);
          foreach ($extension_requirements as $extension_dependency) {
            $dependency_type = $this->getExtensionType($extension_dependency, TRUE);
            if ($extension_dependency_info = $this->getExtensionInfo($dependency_type, $extension_dependency)) {
              $dependency_importable = $extension_dependency_info['managed'] + $extension_dependency_info['unmanaged'] + $extension_dependency_info['implicit'];

              // Unmanaged items could be changed, so we have to export those.
              if ($with_unmanaged && $extension_dependency_info['unmanaged']) {
                $dependency_importable = array_diff_key($dependency_importable, $extension_dependency_info['unmanaged']);
              }

              $exclude['config'] += $dependency_importable;
            }
          }
        }
      }

      foreach ($importable as $config_name) {
        $config_dependencies = $this->getConfigDependencies($config_name, $recursion_limit);
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
    }
    return $this->sortAndFilterOutput($dependencies, $exclude);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigDependencies($config_name, $recursion_limit = NULL) {
    static $recursive_iterations = 0;
    static $checked = array();

    if (!isset($checked[$config_name])) {
      $config_dependencies = $this->configManager->getConfigFactory()->get($config_name)->get('dependencies');
      if ($config_dependencies && is_array($config_dependencies)) {
        // Handle any 'enforced' dependencies.
        if (isset($config_dependencies['enforced'])) {
          foreach ($config_dependencies['enforced'] as $dependency_type => $type_enforced) {
            foreach ($type_enforced as $enforced) {
              $config_dependencies[$dependency_type][] = $enforced;
            }
          }
        }

        foreach ($config_dependencies as $dependency_type => $type_dependencies) {
          // Use associative array to avoid duplicates.
          $config_dependencies[$dependency_type] = array_combine($type_dependencies, $type_dependencies);
        }

        // Recurse to find sub-dependencies.
        if (isset($config_dependencies['config'])) {
          $recursive_iterations++;
          if (!$recursion_limit || $recursive_iterations < $recursion_limit) {
            foreach ($config_dependencies['config'] as $dependency) {
              $sub_dependencies = $this->getConfigDependencies($dependency, $recursion_limit);

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

      // Config provider is an implied dependency. It can either be on core,
      // which we do not need to list, a theme, or a module/profile (which are
      // both treated as a 'module' dependency).
      $config_provider = substr($config_name, 0, strpos($config_name, '.'));
      if ($config_provider != 'core') {
        $provider_type = $this->getExtensionType($config_provider, TRUE);
        if ($provider_type !== 'theme') {
          $provider_type = 'module';
        }
        $config_dependencies[$provider_type][$config_provider] = $config_provider;
      }

      $checked[$config_name] = $config_dependencies;
    }

    return $checked[$config_name];
  }


  /**
   * Suggest config to manage, based on an extension's currently managed config.
   *
   * For the config listed in a projects .info.yml, find other config that is
   * dependant upon it, but which is not:
   *  - Itself a dependency of the config listed in cm_config_tools.managed
   *  - Already included explicitly in cm_config_tools.managed
   *  - Explicitly ignored in the cm_config_tools.unmanaged
   *
   * @TODO Implement $all == FALSE
   *
   * @param string $extension
   *   The machine name of the project to find suggested config for.
   * @param bool $all
   *   By default, get all dependencies. Optionally set to FALSE to exclude
   *   those that are already provided by listed dependencies of the extension
   *   (whether they are directly or indirectly dependent).
   * @param int $recursion_limit
   *   Optionally limit the levels of recursion.
   *
   * @return array
   *   An array of config suggestions.
   */
  public function getExtensionConfigSuggestions($extension, $all = TRUE, $recursion_limit = NULL) {
    $dependants = array();
    $exclude = array();
    if (!$type = $this->getExtensionType($extension)) {
      throw new ExtensionConfigException('Extension could not be found.');
    }

    $info = $this->getExtensionInfo($type, $extension);
    $importable = $info['managed'] + $info['unmanaged'];
    if ($importable) {
      $exclude['config'] = $importable;

      $dependency_manager = $this->configManager->getConfigDependencyManager();
      foreach ($importable as $config_name) {
        // Recursively fetch configuration entities that are dependants of this
        // configuration entity (i.e. reverse dependencies).
        $dependants += $this->getConfigSuggestions($config_name, $dependency_manager, $recursion_limit);
      }
    }

    return $this->sortAndFilterOutput(array('config' => $dependants), $exclude);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigSuggestions($config_name, ConfigDependencyManager $dependency_manager, $recursion_limit = NULL) {
    static $recursive_iterations = 0;
    static $checked = array();

    if (!isset($checked[$config_name])) {
      if ($dependants = array_keys($dependency_manager->getDependentEntities('config', $config_name))) {
        // Use associative array to avoid duplicates.
        $dependants = array_combine($dependants, $dependants);

        $recursive_iterations++;
        if (!$recursion_limit || $recursive_iterations < $recursion_limit) {
          $base_dependants = $dependants;
          foreach ($base_dependants as $dependant) {
            if ($sub_dependants = $this->getConfigSuggestions($dependant, $dependency_manager, $recursion_limit)) {
              $dependants += $sub_dependants;
            }
          }
        }
        $recursive_iterations--;
      }

      $checked[$config_name] = $dependants;
    }

    return $checked[$config_name];
  }

  /**
   * Add items to an extension's .info.yml file, such as dependencies.
   *
   * This does not just parse and encode YAML, because we want to preserve
   * comments and empty lines. Instead it supports the expected 'normal' format
   * of a Drupal extension's .info.yml file, finding the target block and
   * inserting the new items. Items can be specified to remove from that block
   * too. If the block has no comments or empty lines, and the existing items
   * are sorted, then the new items will be merged and sorted in amongst them.
   *
   * @param string $info_filename
   *   The info filename.
   * @param string|string[] $key
   *   An array of keys that the items should be placed within, or a single
   *   string for a top-level key.
   * @param string[] $items
   *   An array of items to add.
   * @param string[] $remove
   *   An array of items to remove.
   */
  protected function updateManifest($info_filename, $key, $items, $remove = array()) {
    $contents = file_get_contents($info_filename);

    // Pad sub-key names appropriately.
    if (!is_array($key)) {
      $key = array($key);
    }
    $key = array_values($key);
    foreach ($key as $level => $key_name) {
      $key[$level] = str_repeat(self::MANIFEST_INDENT, $level) . trim($key_name);
    }
    $item_indentation = str_repeat(self::MANIFEST_INDENT, max(array_keys($key)) + 1);

    $contents = str_replace(array("\r\n", "\r"), "\n", $contents);
    $lines = explode("\n", $contents);
    $block_start = NULL;
    $block_end = max(array_keys($lines)) + 1;
    $block_lines = $lines;

    $level = 0;
    while ($subkey = array_shift($key)) {
      if (strpos(implode("\n", $block_lines), $subkey . ':') !== FALSE) {
        $block_info = $this->findManifestBlock($block_lines, $subkey, $level++);
        $plain_block = $block_info['plain'];
        $block_start = $block_info['start'];
        $block_end = $block_info['end'];

        $block_lines = array_slice($lines, $block_start, ($block_end - $block_start), TRUE);
      }
      else {
        // Append subkey, but retaining any trailing whitespace.
        $insert_at = $block_end;
        for ($i = ($insert_at - 1); $i > 0; $i--) {
          if ($row = $lines[$i]) {
            $row = trim($row);
            if ($row) {
              $insert_at = $i + 1;
              break;
            }
          }
        }
        $lines = array_merge(array_slice($lines, 0, $insert_at), array($subkey . ':'), array_slice($lines, $insert_at));
        $block_lines = array();
        $plain_block = TRUE;
        $block_start = $insert_at;
        $block_end = $insert_at + 1;
      }
    }

    // If there were no empty lines or comments within this block then the
    // items within it can be merged in nicely for sorting, except when the
    // existing items themslves are unsorted.
    if (isset($block_start) && !empty($plain_block)) {
      $existing_items = array();
      $insert_before = $block_end;
      for ($i = ($block_start + 1); $i < $block_end; $i++) {
        $row = ltrim($lines[$i], self::MANIFEST_WHITESPACE_CHARS . '-');
        $prev_row = $i - $block_start - 2;
        if (empty($existing_items) || ($row > $existing_items[$prev_row])) {
          $existing_items[] = $row;
        }
        else {
          unset($insert_before);
        }
      }

      if (isset($insert_before)) {
        if ($remove) {
          $trimmed_existing_items = $existing_items;
          foreach ($existing_items as $i => $existing_item) {
            $comment_pos = strpos($existing_item, '#');
            if ($comment_pos !== FALSE) {
              $existing_item = substr($existing_item, 0, $comment_pos);
            }
            $trimmed_existing_items[$i] = rtrim($existing_item, self::MANIFEST_WHITESPACE_CHARS);
          }

          while ($item_to_remove = array_pop($remove)) {
            $i = array_search($item_to_remove, $trimmed_existing_items, TRUE);
            if ($i !== FALSE) {
              unset($existing_items[$i]);
            }
          }
        }

        $items = array_merge($items, $existing_items);
      }
    }

    if ($items) {
      sort($items);
      $items = $item_indentation . '- ' . implode("\n" . $item_indentation . '- ', $items);
    }

    if (isset($block_start)) {
      $insert_at = $block_start + 1;
      if (!isset($insert_before)) {
        $insert_before = $insert_at;
      }
    }
    else {
      // Append to the file, but retaining any trailing whitespace.
      // @TODO This block of logic should never be reached, and would not cope
      // with multiple levels of keys anyway either.
      $insert_at = count($lines);
      foreach (array_reverse($lines, TRUE) as $i => $row) {
        if ($row) {
          $row = trim($row);
          if ($row) {
            $insert_at = $i + 1;
            break;
          }
        }
      }
      $insert_before = $insert_at;
      $items = $key[0] . ":\n" . $items;
    }

    if ($items) {
      $lines = array_merge(array_slice($lines, 0, $insert_at), array($items), array_slice($lines, $insert_before));
    }

    // Remove unused items from the appropriate block.
    for ($i = ($block_start + 1); $remove && $i < $block_end; $i++) {
      $row = ltrim($lines[$i], self::MANIFEST_WHITESPACE_CHARS . '-');
      $comment_pos = strpos($row, '#');
      if ($comment_pos !== FALSE) {
        $row = substr($row, 0, $comment_pos);
      }
      $row = rtrim($row, self::MANIFEST_WHITESPACE_CHARS);
      if (isset($remove[$row])) {
        unset($lines[$i]);
        unset($remove[$row]);
      }
    }

    file_put_contents($info_filename, implode("\n", $lines));

    $this->infoParser->reset($info_filename);
  }

  /**
   * Detect the block for the supplied key amongst the lines.
   *
   * @param string[] $lines
   *   Lines to hunt amongst.
   * @param string $keys
   *   The key to look for.
   * @param int $indent_level
   *   The level of indentation that the current lines are at.
   *
   * @return array
   *   An array of information, with the following keys: 'start', 'end' and
   *   'plain'.
   */
  protected function findManifestBlock($lines, $key, $indent_level = 0) {
    $block_start = NULL;
    $content_found = FALSE;
    $plain_block = TRUE;
    // Process in reverse, in case of duplicate keys (since the last value
    // would 'win').
    $reverse_lines = array_reverse($lines, TRUE);
    $block_end = max(array_keys($reverse_lines)) + 1;
    foreach ($reverse_lines as $i => $row) {
      if ($row) {
        // Trim any comment from the row.
        $comment = strpos($row, '#');
        if ($comment) {
          $row = substr($row, 0, $comment-1);
        }
        elseif ($comment === 0) {
          // This row is just a comment.
          $plain_block = FALSE;
          continue;
        }

        // Trim whitespace and braces to the right of the row.
        $row = rtrim($row, self::MANIFEST_WHITESPACE_CHARS . '{}');
        if ($row) {
          $content_found = TRUE;
          $total_indent = $indent_level * strlen(self::MANIFEST_INDENT);
          $whitespace_matches_indent = ((strlen($row) - strlen(ltrim($row, self::MANIFEST_WHITESPACE_CHARS))) === $total_indent);
          if ($whitespace_matches_indent) {
            if ($row == $key . ':') {
              // Row for this key found.
              $block_start = $i;
              break;
            }
            else {
              // This is a new value for the indent level, so reset the 'plain
              // block' variable.
              $plain_block = TRUE;
              $block_end = $i;
            }
          }
          // Else case: row contains a value, just not one for the current
          // indent level.
        }
        elseif ($content_found) {
          $plain_block = FALSE;
        }
        else {
          $block_end--;
        }
      }
      elseif ($content_found) {
        $plain_block = FALSE;
      }
      else {
        $block_end--;
      }
    }

    return array(
      'start' => $block_start,
      'end' => $block_end,
      'plain' => $plain_block,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function addToManagedConfig($extension, $config_names) {
    // @TODO
  }

  /**
   * {@inheritdoc}
   */
  public function export($with_dependencies = TRUE, $subdir = InstallStorage::CONFIG_INSTALL_DIRECTORY, $force_unmanaged = FALSE, $fully_normalize = FALSE) {
    if ($extension_dirs = $this->getExtensionDirectories(TRUE)) {
      return $this->exportExtensionDirectories($extension_dirs, $with_dependencies, $subdir, $force_unmanaged, $fully_normalize);
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
   * @param bool|int $with_dependencies
   *   Export configuration together with its dependencies.
   * @param string $subdir
   *   The sub-directory of configuration to import.
   * @param bool $force_unmanaged
   *   When set to FALSE, any config listed as 'unmanaged' is only exported
   *   when it has not previously been exported. Set to TRUE to overwrite any
   *   such config even if it has been previously exported.
   * @param bool $fully_normalize
   *   Set to TRUE to sort configuration names when exporting, and strip any
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
  protected function exportExtensionDirectories($extension_dirs, $with_dependencies, $subdir, $force_unmanaged, $fully_normalize) {
    $errors = [];
    $files_changed = [];
    $config_factory = $this->configManager->getConfigFactory();

    foreach ($extension_dirs as $type => $type_source_dirs) {
      foreach ($type_source_dirs as $source_dir => $extension_name) {
        $info_filename = NULL;

        // Get the configuration.
        $info = $this->getExtensionInfo($type, $extension_name);
        // Implicit dependencies will get re-calculated and added to this array.
        $exportable = $info['managed'] + $info['unmanaged'];

        if ($exportable) {
          // Include any config dependencies, which will get added to the array
          // of config to export, if $with_dependencies is not empty.
          if ($with_dependencies) {
            $dependencies = $this->getExtensionConfigDependencies($extension_name, $type, ($with_dependencies === ExtensionConfigHandlerInterface::WITH_DEPENDENCIES_NOT_PROVIDED), FALSE);

            // Write module dependencies to .info.yml file. Themes cannot depend
            // on modules, so skip this for them.
            if (!empty($dependencies['module']) && $type !== 'theme') {
              $module_data = system_rebuild_module_data();
              if (isset($module_data[$extension_name])) {
                if ($missing_dependencies = array_diff($dependencies['module'], array_keys($module_data[$extension_name]->requires))) {
                  // Minimise the missing dependencies to remove any that
                  // already depend on each other anyway.
                  $minimised = $missing_dependencies;
                  foreach ($missing_dependencies as $missing_dependency) {
                    if (isset($module_data[$missing_dependency])) {
                      $minimised = array_diff($minimised, array_keys($module_data[$missing_dependency]->requires));
                    }
                  }

                  $info_filename = $module_data[$extension_name]->getPathname();
                  $this->updateManifest($info_filename, 'dependencies', $minimised);
                  // Having changed dependencies, we need to reset the module
                  // data. This is not ideal, but if we have other modules that
                  // will be processed in later iterations, they need the latest
                  // dependency data.
                  drupal_static_reset('system_rebuild_module_data');
                  $files_changed[$extension_name][] = $info_filename;
                }
              }
            }

            if (!empty($dependencies['config'])) {
              $dependencies['config'] = array_combine($dependencies['config'], $dependencies['config']);
              $exportable = $exportable + $dependencies['config'];

              // Delete files of previously-implicit dependencies and replace
              // the list in the info file (removing lines and adding lines
              // where necessary).
              $original_implicit = $info['implicit'];
              if ($dependencies['config'] != $original_implicit) {
                $no_longer_implicit = array_diff($original_implicit, $dependencies['config']);
                $newly_implicit = array_diff($dependencies['config'], $original_implicit);

                if (!isset($info_filename)) {
                  $info_filename = drupal_get_path($type, $extension_name) . '/' . $extension_name . '.info.yml';
                }
                $this->updateManifest($info_filename, array('cm_config_tools', 'implicit'), $newly_implicit, $no_longer_implicit);
              }
            }
          }

          try {
            $source_dir_storage = new FileStorage($source_dir . '/' . $subdir);
            foreach ($exportable as $name) {
              $config = $config_factory->get($name);
              if ($data = $config->get()) {
                $existing_export = $source_dir_storage->read($name);

                // Skip existing config that is only listed as 'unmanaged'
                // unless the 'force unmanaged' option was passed.
                if ($existing_export && isset($info['unmanaged'][$name]) && !isset($info['managed'][$name]) && !$force_unmanaged) {
                  continue;
                }

                if (!$existing_export || !$this->configDiff->same($data, $existing_export)) {
                  // @TODO Config to export could be differently sorted to
                  // an existing export, which is just unnecessary change.
                  // ... oh, except views which actually relies on keys' order.
                  $data = static::normalizeConfig($name, $data, $fully_normalize);
                  $source_dir_storage->write($name, $data);
                  $files_changed[$extension_name][] = $name;
                }
              }
              else {
                $errors[$extension_name][] = 'Config ' . $name . ' not found in active storage.';
              }
            }

            if (!empty($no_longer_implicit)) {
              foreach ($no_longer_implicit as $name) {
                $source_dir_storage->delete($name);
                $files_changed[$extension_name][] = $name;
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

    if (empty($files_changed)) {
      return FALSE;
    }

    return $errors ? $errors : TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionInfo($type, $extension_name, $key = NULL, $default = NULL) {
    $array_info = array('managed', 'unmanaged', 'implicit', 'delete');

    if ($extension_path = drupal_get_path($type, $extension_name)) {
      // Info parser service statically caches info files, so it does not
      // matter that file may already have been parsed by this class.
      $info_filename = $extension_path . '/' . $extension_name . '.info.yml';
      $info = $this->infoParser->parse($info_filename);

      if ($key) {
        if (in_array($key, $array_info, TRUE)) {
          if (isset($info['cm_config_tools'][$key]) && $info['cm_config_tools'][$key] && is_array($info['cm_config_tools'][$key])) {
            $info['cm_config_tools'][$key] = array_values($info['cm_config_tools'][$key]);
            return array_combine($info['cm_config_tools'][$key], $info['cm_config_tools'][$key]);
          }
          else {
            return $default;
          }
        }
        else {
          return isset($info['cm_config_tools'][$key]) ? $info['cm_config_tools'][$key] : $default;
        }
      }
      else {
        if (array_key_exists('cm_config_tools', $info)) {
          // Massage info to be in a valid and useful format.
          foreach ($array_info as $info_key) {
            if (isset($info['cm_config_tools'][$info_key]) && $info['cm_config_tools'][$info_key] && is_array($info['cm_config_tools'][$info_key])) {
              $info['cm_config_tools'][$info_key] = array_values($info['cm_config_tools'][$info_key]);
              $info['cm_config_tools'][$info_key] = array_combine($info['cm_config_tools'][$info_key], $info['cm_config_tools'][$info_key]);
            }
            else {
              $info['cm_config_tools'][$info_key] = array();
            }
          }
          return $info['cm_config_tools'];
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
      foreach (['module', 'theme'] as $candidate) {
        $extension_data = $this->state->get('system.' . $candidate . '.files', array());

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
  public static function normalizeConfig($config_name, $config, $sort_and_filter = TRUE, $ignore = array('uuid', '_core')) {
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
        if (isset($value['uuid']) && $value['uuid'] === $key && in_array('uuid', $ignore) && preg_match('#^image\.style\.[^.]+\.effects#', $config_name)) {
          $new = static::normalizeConfig($config_name . '.' . $key, $value, $sort_and_filter, array_diff($ignore, array('uuid')));
        }
        else {
          $new = static::normalizeConfig($config_name . '.' . $key, $value, $sort_and_filter, $ignore);
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

  /**
   * {@inheritdoc}
   */
  public function revert($names) {
    if (is_string($names)) {
      $names = explode(',', $names);
    }

    $names = array_map('trim', $names);
    $errors = [];
    if ($names) {
      list($active_list, , ) = $this->configLister->listConfig('type', 'system.all');

      foreach ($names as $full_name) {
        // ConfigReverter methods require the 'short' name for typed config,
        // otherwise 'system.simple'.
        if ($type = $this->configLister->getTypeNameByConfigName($full_name)) {
          /** @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $definition */
          $definition = $this->entityTypeManager->getDefinition($type);
          $prefix = $definition->getConfigPrefix() . '.';
          if (strpos($full_name, $prefix) === 0) {
            $name = substr($full_name, strlen($prefix));
          }
          else {
            $errors[$full_name] = t('Config @name does not match expected config prefix.', array('@name' => $full_name));
            continue;
          }
        }
        else {
          $type = 'system.simple';
          $name = $full_name;
        }

        $method = in_array($full_name, $active_list) ? 'revert' : 'import';
        if (!$this->configReverter->{$method}($type, $name)) {
          $errors[$full_name] = t('Config @name could not be reverted.', array('@name' => $full_name));
        }
      }
    }
    else {
      $errors[] = t('No configuration specified to revert.');
    }
  }

}
