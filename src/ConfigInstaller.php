<?php

namespace Drupal\cm_config_tools;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\PreExistingConfigException;
use Drupal\Core\Config\StorageInterface;

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
   * Constructs a ProxyClass Drupal proxy object.
   *
   * @param \Drupal\Core\Config\ConfigInstallerInterface $original_installer
   *   The decorated config installer.
   * @param \Drupal\cm_config_tools\ExtensionConfigHandler $helper
   *   The extension config handler.
   */
  public function __construct(ConfigInstallerInterface $original_installer, ExtensionConfigHandler $helper) {
    $this->original_installer = $original_installer;
    $this->helper = $helper;
  }

  /**
   * Allows any module using cm_config_tools to override existing configuration.
   *
   * @see \Drupal\Core\Config\ConfigInstaller::checkConfigurationToInstall()
   */
  public function checkConfigurationToInstall($type, $name) {
    try {
      $this->original_installer->checkConfigurationToInstall($type, $name);
    }
    catch (PreExistingConfigException $e) {
      // Only rethrow exception if the extension does not use cm_config_tools.
      if (!$this->helper->getExtensionInfo($name, NULL, NULL, 'cm_config_tools', TRUE)) {
        throw $e;
      }
    }
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
