<?php

/**
 * @file
 * Contains \Drupal\cm_config_tools\ConfigDiffStorageComparer.
 */

namespace Drupal\cm_config_tools;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\config_update\ConfigDiffInterface;

/**
 * Defines a config storage comparer.
 *
 * @see http://cgit.drupalcode.org/config_sync/tree/src/ConfigSyncStorageComparer.php?id=8.x-1.0-alpha1
 */
class ConfigDiffStorageComparer extends StorageComparer {

  /**
   * The config differ.
   *
   * @var \Drupal\config_update\ConfigDiffInterface
   */
  protected $configDiff;

  /**
   * Constructs a ConfigDiffStorageComparer.
   *
   * Wrap the storages in a static cache so that multiple reads of the same raw
   * configuration object are not costly, but also with a wrapper that passes
   * unknown methods onto the wrapped storage classes.
   *
   * @param \Drupal\Core\Config\StorageInterface $source_storage
   *   Storage object used to read configuration.
   * @param \Drupal\Core\Config\StorageInterface $target_storage
   *   Storage object used to write configuration.
   * @param \Drupal\config_update\ConfigDiffInterface $config_diff
   *   The config differ.
   *
   * @see \Drupal\Core\Config\StorageComparer::__construct()
   */
  public function __construct(StorageInterface $source_storage, StorageInterface $target_storage, ConfigDiffInterface $config_diff) {
    $this->sourceCacheStorage = new MemoryBackend(__CLASS__ . '::source');
    $this->sourceStorage = new DecoratingCachedStorage(
      $source_storage,
      $this->sourceCacheStorage
    );
    $this->targetCacheStorage = new MemoryBackend(__CLASS__ . '::target');
    $this->targetStorage = new DecoratingCachedStorage(
      $target_storage,
      $this->targetCacheStorage
    );
    $this->changelist[StorageInterface::DEFAULT_COLLECTION] = $this->getEmptyChangelist();

    $this->configDiff = $config_diff;
  }

  /**
   * Overrides \Drupal\Core\Config\StorageComparer::addChangelistUpdate() to
   * use the comparison provided by \Drupal\config_update\ConfigDiffInterface
   * to determine available updates.
   *
   * \Drupal\config_update\ConfigDiffInterface::same() includes normalization
   * that may reduce false positives resulting from either expected differences
   * between provided and installed configuration (for example, the presence or
   * absence of a UUID value) or incidental ordering differences. This does mean
   * that recreates (deletes followed by creation under an identical name) are
   * not supported.
   *
   * @param string $collection
   *   The storage collection to operate on.
   */
  protected function addChangelistUpdate($collection) {
    foreach (array_intersect($this->sourceNames[$collection], $this->targetNames[$collection]) as $name) {
      $source_data = $this->getSourceStorage($collection)->read($name);
      $target_data = $this->getTargetStorage($collection)->read($name);
      if (!$this->configDiff->same($source_data, $target_data)) {
        $this->addChangeList($collection, 'update', array($name));
      }
    }
  }

  /**
   * Always ignores the site UUID.
   */
  public function validateSiteUuid() {
    return TRUE;
  }

}
