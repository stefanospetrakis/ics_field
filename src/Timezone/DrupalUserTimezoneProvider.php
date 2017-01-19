<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 18.01.17
 * Time: 22:11
 */

namespace Drupal\px_calendar_download\Timezone;

class DrupalUserTimezoneProvider implements TimezoneProviderInterface {

  /**
   * Effectively duplicates drupal_get_user_timezone()
   *
   * @inheritDoc
   */
  public function getTimezoneString() {
    $user = \Drupal::currentUser();
    $config = \Drupal::config('system.date');

    if ($user && $config->get('timezone.user.configurable') &&
        $user->isAuthenticated() && $user->getTimezone()
    ) {
      return $user->getTimezone();
    } else {
      // Ignore PHP strict notice if time zone has not yet been set in the php.ini
      // configuration.
      $config_data_default_timezone = $config->get('timezone.default');
      return !empty($config_data_default_timezone) ?
        $config_data_default_timezone : @date_default_timezone_get();
    }
  }

}