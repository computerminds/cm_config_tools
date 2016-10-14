<?php

namespace Drupal\cm_config_tools;

use Drupal\cm_config_tools\Exception\UnmetDependenciesException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\PreExistingConfigException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;

/**
 * Class ConfigInstaller, decorating core's ConfigInstaller where possible.
 *
 * Allows modules that use cm_config_tools to override configuration from other
 * modules. Also lists what dependencies are missing when checking for missing
 * dependencies.
 */
class ConfigInstaller implements ConfigInstallerInterface {

  /**
   * The decorated config installer.
   *
   * @var \Drupal\Core\Config\ConfigInstallerInterface
   */
  protected $original_installer;

  /**
   * The extension config handler.
   *
   * @var \Drupal\cm_config_tools\ExtensionConfigHandler
   */
  protected $helper;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The active configuration storages, keyed by collection.
   *
   * @var \Drupal\Core\Config\StorageInterface[]
   */
  protected $activeStorages;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * Constructs a ProxyClass Drupal proxy object.
   *
   * @param \Drupal\Core\Config\ConfigInstallerInterface $original_installer
   *   The decorated config installer.
   * @param \Drupal\cm_config_tools\ExtensionConfigHandler $helper
   *   The extension config handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\StorageInterface $active_storage
   *   The active configuration storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The configuration manager.
   */
  public function __construct(ConfigInstallerInterface $original_installer, ExtensionConfigHandler $helper, ConfigFactoryInterface $config_factory, StorageInterface $active_storage, ConfigManagerInterface $config_manager) {
    $this->original_installer = $original_installer;
    $this->helper = $helper;
    $this->configFactory = $config_factory;
    $this->activeStorages[$active_storage->getCollectionName()] = $active_storage;
    $this->configManager = $config_manager;
  }

  /**
   * Allows any module using cm_config_tools to override existing configuration.
   *
   * @see \Drupal\Core\Config\ConfigInstaller::checkConfigurationToInstall()
   * @see https://www.drupal.org/node/2655104
   */
  public function checkConfigurationToInstall($type, $name) {
    if ($this->isSyncing()) {
      // Configuration is assumed to already be checked by the config importer
      // validation events.
      return;
    }
    $config_install_path = drupal_get_path($type, $name) . '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY;
    if (!is_dir($config_install_path)) {
      return;
    }

    $storage = new FileStorage($config_install_path, StorageInterface::DEFAULT_COLLECTION);

    $enabled_extensions = $this->getEnabledExtensions();
    // Add the extension that will be enabled to the list of enabled extensions.
    $enabled_extensions[] = $name;
    // Gets profile storages to search for overrides if necessary.
    $profile_storages = $this->getProfileStorages($name);

    // Check the dependencies of configuration provided by the module.
    list($invalid_default_config, $missing_dependencies) = $this->findDefaultConfigWithUnmetDependencies($storage, $enabled_extensions, $profile_storages);
    if (!empty($invalid_default_config)) {
      throw UnmetDependenciesException::create($name, array_unique($missing_dependencies));
    }

    // Install profiles and extensions using cm_config_tools can have config
    // clashes. Configuration that has the same name as a module's configuration
    // will be used instead.
    if ($name != $this->drupalGetProfile() && !$this->helper->getExtensionInfo($type, $name, NULL, NULL, 'cm_config_tools')) {
      // Throw an exception if the module being installed contains configuration
      // that already exists. Additionally, can not continue installing more
      // modules because those may depend on the current module being installed.
      $existing_configuration = $this->findPreExistingConfiguration($storage);
      if (!empty($existing_configuration)) {
        throw PreExistingConfigException::create($name, $existing_configuration);
      }
    }
  }

  /**
   * Gets configuration data from the provided storage to create.
   *
   * @param StorageInterface $storage
   *   The configuration storage to read configuration from.
   * @param string $collection
   *   The configuration collection to use.
   * @param string $prefix
   *   (optional) Limit to configuration starting with the provided string.
   * @param \Drupal\Core\Config\StorageInterface[] $profile_storages
   *   An array of storage interfaces containing profile configuration to check
   *   for overrides.
   *
   * @return array
   *   An array of configuration data read from the source storage keyed by the
   *   configuration object name.
   */
  protected function getConfigToCreate(StorageInterface $storage, $collection, $prefix = '', array $profile_storages = []) {
    if ($storage->getCollectionName() != $collection) {
      $storage = $storage->createCollection($collection);
    }
    $data = $storage->readMultiple($storage->listAll($prefix));

    // Check to see if the corresponding override storage has any overrides.
    foreach ($profile_storages as $profile_storage) {
      if ($profile_storage->getCollectionName() != $collection) {
        $profile_storage = $profile_storage->createCollection($collection);
      }
      $data = $profile_storage->readMultiple(array_keys($data)) + $data;
    }
    return $data;
  }

  /**
   * Gets the configuration storage that provides the active configuration.
   *
   * @param string $collection
   *   (optional) The configuration collection. Defaults to the default
   *   collection.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The configuration storage that provides the default configuration.
   */
  protected function getActiveStorages($collection = StorageInterface::DEFAULT_COLLECTION) {
    if (!isset($this->activeStorages[$collection])) {
      $this->activeStorages[$collection] = reset($this->activeStorages)->createCollection($collection);
    }
    return $this->activeStorages[$collection];
  }

  /**
   * Finds pre-existing configuration objects for the provided extension.
   *
   * Extensions can not be installed if configuration objects exist in the
   * active storage with the same names. This can happen in a number of ways,
   * commonly:
   * - if a user has created configuration with the same name as that provided
   *   by the extension.
   * - if the extension provides default configuration that does not depend on
   *   it and the extension has been uninstalled and is about to the
   *   reinstalled.
   *
   * @return array
   *   Array of configuration object names that already exist keyed by
   *   collection.
   */
  protected function findPreExistingConfiguration(StorageInterface $storage) {
    $existing_configuration = array();
    // Gather information about all the supported collections.
    $collection_info = $this->configManager->getConfigCollectionInfo();

    foreach ($collection_info->getCollectionNames() as $collection) {
      $config_to_create = array_keys($this->getConfigToCreate($storage, $collection));
      $active_storage = $this->getActiveStorages($collection);
      foreach ($config_to_create as $config_name) {
        if ($active_storage->exists($config_name)) {
          $existing_configuration[$collection][] = $config_name;
        }
      }
    }
    return $existing_configuration;
  }

  /**
   * Finds default configuration with unmet dependencies.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The storage containing the default configuration.
   * @param array $enabled_extensions
   *   A list of all the currently enabled modules and themes.
   * @param \Drupal\Core\Config\StorageInterface[] $profile_storages
   *   An array of storage interfaces containing profile configuration to check
   *   for overrides.
   *
   * @return array
   *   An array containing:
   *     - A list of configuration that has unmet dependencies.
   *     - An array that will be filled with the missing dependency names, keyed
   *       by the dependents' names.
   */
  protected function findDefaultConfigWithUnmetDependencies(StorageInterface $storage, array $enabled_extensions, array $profile_storages = []) {
    $missing_dependencies = [];
    $config_to_create = $this->getConfigToCreate($storage, StorageInterface::DEFAULT_COLLECTION, '', $profile_storages);
    $all_config = array_merge($this->configFactory->listAll(), array_keys($config_to_create));
    foreach ($config_to_create as $config_name => $config) {
      if ($missing = $this->getMissingDependencies($config_name, $config, $enabled_extensions, $all_config)) {
        $missing_dependencies[$config_name] = $missing;
      }
    }
    return [
      array_intersect_key($config_to_create, $missing_dependencies),
      $missing_dependencies,
    ];
  }

  /**
   * Validates an array of config data that contains dependency information.
   *
   * @param string $config_name
   *   The name of the configuration object that is being validated.
   * @param array $data
   *   Configuration data.
   * @param array $enabled_extensions
   *   A list of all the currently enabled modules and themes.
   * @param array $all_config
   *   A list of all the active configuration names.
   *
   * @return bool
   *   TRUE if all dependencies are present, FALSE otherwise.
   */
  protected function validateDependencies($config_name, array $data, array $enabled_extensions, array $all_config) {
    if (!isset($data['dependencies'])) {
      // Simple config or a config entity without dependencies.
      list($provider) = explode('.', $config_name, 2);
      return in_array($provider, $enabled_extensions, TRUE);
    }

    $missing = $this->getMissingDependencies($config_name, $data, $enabled_extensions, $all_config);
    return empty($missing);
  }

  /**
   * Returns an array of missing dependencies for a config object.
   *
   * @param string $config_name
   *   The name of the configuration object that is being validated.
   * @param array $data
   *   Configuration data.
   * @param array $enabled_extensions
   *   A list of all the currently enabled modules and themes.
   * @param array $all_config
   *   A list of all the active configuration names.
   *
   * @return array
   *   A list of missing config dependencies.
   */
  protected function getMissingDependencies($config_name, array $data, array $enabled_extensions, array $all_config) {
    if (isset($data['dependencies'])) {
      list($provider) = explode('.', $config_name, 2);
      $all_dependencies = $data['dependencies'];

      // Ensure enforced dependencies are included.
      if (isset($all_dependencies['enforced'])) {
        $all_dependencies = array_merge($all_dependencies, $data['dependencies']['enforced']);
        unset($all_dependencies['enforced']);
      }
      // Ensure the configuration entity type provider is in the list of
      // dependencies.
      if (!isset($all_dependencies['module']) || !in_array($provider, $all_dependencies['module'])) {
        $all_dependencies['module'][] = $provider;
      }

      foreach ($all_dependencies as $type => $dependencies) {
        $list_to_check = [];
        switch ($type) {
          case 'module':
          case 'theme':
            $list_to_check = $enabled_extensions;
            break;
          case 'config':
            $list_to_check = $all_config;
            break;
        }
        if (!empty($list_to_check)) {
          return array_diff($dependencies, $list_to_check);
        }
      }
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEnabledExtensions() {
    // Read enabled extensions directly from configuration to avoid circular
    // dependencies on ModuleHandler and ThemeHandler.
    $extension_config = $this->configFactory->get('core.extension');
    $enabled_extensions = (array) $extension_config->get('module');
    $enabled_extensions += (array) $extension_config->get('theme');
    // Core can provide configuration.
    $enabled_extensions['core'] = 'core';
    return array_keys($enabled_extensions);
  }

  /**
   * {@inheritdoc}
   */
  protected function getProfileStorages($installing_name = '') {
    $profile = $this->drupalGetProfile();
    $profile_storages = [];
    if ($profile && $profile != $installing_name) {
      $profile_path = drupal_get_path('module', $profile);
      foreach ([InstallStorage::CONFIG_INSTALL_DIRECTORY, InstallStorage::CONFIG_OPTIONAL_DIRECTORY] as $directory) {
        if (is_dir($profile_path . '/' . $directory)) {
          $profile_storages[] = new FileStorage($profile_path . '/' . $directory, StorageInterface::DEFAULT_COLLECTION);
        }
      }
    }
    return $profile_storages;
  }

  /**
   * Gets the install profile from settings.
   *
   * @return string|null $profile
   *   The name of the installation profile or NULL if no installation profile
   *   is currently active. This is the case for example during the first steps
   *   of the installer or during unit tests.
   */
  protected function drupalGetProfile() {
    // Settings is safe to use because settings.php is written before any module
    // is installed.
    return Settings::get('install_profile');
  }

  /**
   * {@inheritdoc}
   */
  public function installDefaultConfig($type, $name) {
    return $this->original_installer->installDefaultConfig($type, $name);
  }

  /**
   * {@inheritdoc}
   */
  public function installOptionalConfig(StorageInterface $storage = NULL, $dependency = []) {
    return $this->original_installer->installOptionalConfig($storage, $dependency);
  }

  /**
   * {@inheritdoc}
   */
  public function installCollectionDefaultConfig($collection) {
    return $this->original_installer->installCollectionDefaultConfig($collection);
  }

  /**
   * {@inheritdoc}
   */
  public function setSourceStorage(StorageInterface $storage) {
    return $this->original_installer->setSourceStorage($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function setSyncing($status) {
    return $this->original_installer->setSyncing($status);
  }

  /**
   * {@inheritdoc}
   */
  public function isSyncing() {
    return $this->original_installer->isSyncing();
  }

}
