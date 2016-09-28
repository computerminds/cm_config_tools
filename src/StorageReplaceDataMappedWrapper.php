<?php

namespace Drupal\cm_config_tools;

use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Config\StorageInterface;

/**
 * Maps configuration data to a source as well as allowing data replacement.
 */
class StorageReplaceDataMappedWrapper extends StorageReplaceDataWrapper {

  /**
   * The map of configuration to source.
   *
   * @var array
   */
  protected $map = [];

  /**
   * Constructs a new StorageReplaceDataMappedWrapper.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   A configuration storage to be used to read and write configuration.
   * @param string $collection
   *   (optional) The collection to store configuration in. Defaults to the
   *   default collection.
   */
  public function __construct(StorageInterface $storage, $collection = StorageInterface::DEFAULT_COLLECTION) {
    parent::__construct($storage, $collection);
    $this->map[$collection] = [];
  }

  /**
   * Maps configuration name to the supplied source name.
   *
   * @param string $name
   *   The configuration object name to map.
   * @param string $source
   *   The source of the data.
   *
   * @return $this
   */
  public function map($name, $source) {
    $this->map[$this->collection][$name] = $source;
    return $this;
  }

  /**
   * Gets the mapped source name for the supplied configuration name.
   *
   * @param string $name
   *   The configuration object name.
   *
   * @return string|null
   */
  public function getMapping($name) {
    if (isset($this->map[$this->collection][$name])) {
      return $this->map[$this->collection][$name];
    }
    else {
      return NULL;
    }
  }

}
