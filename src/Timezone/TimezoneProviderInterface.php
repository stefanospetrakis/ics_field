<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 18.01.17
 * Time: 22:10
 */

namespace Drupal\px_calendar_download\Timezone;

interface TimezoneProviderInterface {

  /**
   * This returns a timezone in the format of
   *
   * @return string The timezone to use for the calendar
   */
  public function getTimezoneString();

}