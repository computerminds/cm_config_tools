<?php

namespace Drupal\cm_config_tools;

use Drupal\Core\Extension\InfoParser;

/**
 * Parses extension .info.yml files.
 */
class ResettableInfoParser extends InfoParser {

  /**
   * All resetting of the cache of parsed info files.
   *
   * @param string $filename
   *   Optionally reset the cache for a single file.
   */
  public function reset($filename = NULL) {
    if ($filename) {
      unset(static::$parsedInfos[$filename]);
    }
    else {
      static::$parsedInfos = array();
    }
  }

}
