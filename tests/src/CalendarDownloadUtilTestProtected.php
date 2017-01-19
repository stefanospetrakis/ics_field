<?php

namespace Drupal\Tests\px_calendar_download;

use Drupal\px_calendar_download\ICalFactory;

/**
 * Unit tests for px_calendar_download functions.
 *
 * @group px_calendar_download
 */
class ICalFactoryTestProtected extends ICalFactory {

  /**
   * Exposes private function for testing.
   */
  public static function normalizeUrlExposed($url) {
    return parent::normalizeUrl($url);
  }

  /**
   * Exposes private function for testing.
   */
  public static function checkParamsExposed(array $cal_params) {
    return parent::checkParams($cal_params);
  }

}
