<?php

namespace Drupal\px_calendar_download;

use Html2Text\Html2Text;
use Eluceo\iCal\Component as iCal;

/**
 * Utility class for generating calendars.
 */
class CalendarDownloadUtil {

  /**
   * Generate an .ics file as a string.
   */
  public static function generateCalFileAsString(array $cal_params) {
    static::checkParams($cal_params);
    $user_tz = $cal_params['timezone'];
    $user_dtz = new \DateTimeZone($user_tz);
    // Use the hostname to set the PRODID field.
    $v_calendar = new iCal\Calendar($cal_params['prodid']);
    // Create timezone definition.
    $v_timezone = new iCal\Timezone($user_tz);
    // Find the oldest and newest event dates.
    list($min, $max) = static::getMinMaxTimestamps($cal_params['dates']);
    // Add standard and daylight-saving components.
    static::applyTransitions($v_timezone, $min, $max);
    $v_calendar->setTimezone($v_timezone);
    // Using html2text to convert markup into reasonable
    // ASCII text.
    $html2text = new Html2Text();
    // Uses token replacement to interpolate tokens in the field's fields that support them.
    $summary = $cal_params['summary'];
    $event_url = empty($cal_params['url']) ? '' : static::normalizeUrl($cal_params['url']);
    // Here we are producing two versions of an event's description.
    // One is ascii and the other one may contain markup.
    $description_html = $cal_params['description'];
    $html2text->setHtml($description_html);
    $vdates = $cal_params['dates'];
    $vuuid = $cal_params['uuid'];

    foreach ($vdates as $didx => $date) {
      // We need this event_uid to be the same on 
      // following versions of the file, in order to
      // be able to update existing events, e.g. by
      // reimporting the ics file into our calendar. 
      $event_uid = md5($vuuid . $didx);
      $v_event = new iCal\Event($event_uid);
      // Create a datetime object from the stored date value
      // using UTC as timezone
      $datetime = new \DateTime($date, new \DateTimeZone('UTC'));
      // Set the datetime object using user's timezone.
      // This way the correct time offset will be applied.
      $datetime->setTimeZone($user_dtz);
      $v_event
        ->setDtStart($datetime)
        ->setSummary($summary)
        ->setDescription($html2text->getText())
        ->setDescriptionHTML($description_html);
      if (!empty($event_url)) {
        $v_event->setURL($event_url);
      }
      $v_event->setUseTimezone(TRUE);
      $v_calendar->addComponent($v_event);
    }
    return $v_calendar->render();
  }

  /**
   * Check that the needed params are in place.
   */
  protected static function checkParams(array $cal_params) {
    $validation_error = FALSE;
    // Setting these if blocks here as placeholders.
    // There could be more complex validation taking
    // place here.
    if (empty($cal_params['timezone'])) {
      $validation_error = TRUE;
    }
    if (empty($cal_params['prodid'])) {
      $validation_error = TRUE;
    }
    if (empty($cal_params['summary'])) {
      $validation_error = TRUE;
    }
    if (empty($cal_params['dates'])) {
      $validation_error = TRUE;
    }
    if (empty($cal_params['uuid'])) {
      $validation_error = TRUE;
    }
    if ($validation_error) {
      throw new CalendarDownloadInvalidParametersException('Calendar parameters validation error.');
    }
    return TRUE;
  }

  /**
   * Some rudimentary URL parsing.
   */
  protected static function normalizeUrl(string $url) {
    if (empty($url)) {
      return NULL;
    }
    $url = strip_tags($url);
    if (empty(parse_url($url, PHP_URL_SCHEME))) {
      if (preg_match('#(\w+\.)?\w+\.\w+#', $url)) {
        $url = 'http://' . $url;
      }
      // Internal path.
      else {
        $url = \Drupal::request()->getSchemeAndHttpHost() . '/' . preg_replace('#^/#', '', $url);
      }
    }
    return $url;
  }

  /**
   * Getting the daylight-saving and standard timezones.
   *
   * Shamelessly copied over from http://stackoverflow.com/a/25971680/5875098.
   */
  protected static function applyTransitions(iCal\Timezone &$icaltz, int $from = 0, int $to = 0) {
    // Get all transitions for one year back/ahead.
    $year = 86400 * 360;
    $now = time();
    $from = $to = $now;
    $dtz = new \DateTimeZone($icaltz->getZoneIdentifier());
    $transitions = $dtz->getTransitions($from - $year, $to + $year);

    $std = NULL;
    $dst = NULL;
    foreach ($transitions as $i => $trans) {
      $cmp = NULL;

      // Skip the first entry ...
      if ($i == 0) {
        // ... but remember the offset for the next TZOFFSETFROM value.
        $tzfrom = $trans['offset'] / 3600;
        continue;
      }

      // Daylight saving time definition.
      if ($trans['isdst']) {
        $t_dst = $trans['ts'];
        $dst = new iCal\TimezoneRule(iCal\TimezoneRule::TYPE_DAYLIGHT);
        $cmp = $dst;
      }
      // Standard time definition.
      else {
        $t_std = $trans['ts'];
        $std = new iCal\TimezoneRule(iCal\TimezoneRule::TYPE_STANDARD);
        $cmp = $std;
      }

      if ($cmp) {
        $dt = new \DateTime($trans['time'], $dtz);
        $offset = $trans['offset'] / 3600;

        $cmp->setDtStart($dt);
        $cmp->setTzOffsetFrom(sprintf('%s%02d%02d', $tzfrom >= 0 ? '+' : '', floor($tzfrom), ($tzfrom - floor($tzfrom)) * 60));
        $cmp->setTzOffsetTo(sprintf('%s%02d%02d', $offset >= 0 ? '+' : '', floor($offset), ($offset - floor($offset)) * 60));

        // Add abbreviated timezone name if available.
        if (!empty($trans['abbr'])) {
          $cmp->setTzName($trans['abbr']);
        }

        $tzfrom = $offset;
        $icaltz->addComponent($cmp);
      }

      // We covered the entire date range.
      if ($std && $dst && min($t_std, $t_dst) < $from && max($t_std, $t_dst) > $to) {
        break;
      }
    }
  }

  /**
   * Sort and return the oldest and newest event dates as timestamps.
   */
  protected static function getMinMaxTimestamps(array $dates_list) {
    $now = time();
    $min = $max = $now;
    foreach ($dates_list as $date_str) {
      $timestamp = strtotime($date_str);
      if ($timestamp > $max) {
        $max = $timestamp;
      }
      if ($timestamp < $min) {
        $min = $timestamp;
      }
    }
    return [$min, $max];
  }

}
