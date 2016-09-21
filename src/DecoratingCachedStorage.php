<?php

namespace Drupal\cm_config_tools;

use Drupal\Core\Config\CachedStorage;

/**
 * Defines the DecoratingCachedStorage storage controller which passes
 * unknown methods onto the wrapped configuration storage class.
 *
 * @see \Drupal\webprofiler\Decorator
 */
class DecoratingCachedStorage extends CachedStorage {

  /**
   * Returns true if $method is a PHP callable.
   *
   * @param string $method
   *   The method name.
   *
   * @return bool|mixed
   */
  protected function isCallable($method) {
    $object = $this->storage;
    if (is_callable([$object, $method])) {
      return $object;
    }
    return FALSE;
  }

  /**
   * @param $method
   * @param $args
   *
   * @return mixed
   *
   * @throws \Exception
   */
  public function __call($method, $args) {
    if ($object = $this->isCallable($method)) {
      return call_user_func_array([$object, $method], $args);
    }
    throw new \Exception('Undefined method - ' . get_class($this->storage) . '::' . $method);
  }

}
