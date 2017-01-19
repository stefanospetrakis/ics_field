<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 18.01.17
 * Time: 00:35
 */

namespace Drupal\px_calendar_download;

use Eluceo\iCal\Component\Timezone;
use Eluceo\iCal\Component\TimezoneRule;

/**
 * Class IcalTimezoneGenerator
 *
 * @package Drupal\px_calendar_download
 */
class IcalTimezoneGenerator {

  /**
   * @var string
   */
  protected $timestampFormat = 'Y-m-d H:i:s T';

  /**
   * @var int
   */
  private static $secondsInHour = 3600;

  /**
   * Getting the daylight-saving and standard timezones.
   *
   * Shamelessly copied over from http://stackoverflow.com/a/25971680/5875098.
   *
   * @param Timezone $iCalendarTimezone
   *   An incoming timezone that we may modify by adding component rules,
   *   depending on the user's timezone.
   *
   * @return Timezone The modified timezone object.
   *
   * @throws \InvalidArgumentException
   */
  public function applyTimezoneTransitions(Timezone $iCalendarTimezone,
                                           $dateList) {

    list($from, $to) = $this->getMinMaxTimestamps($dateList);

    // Get all transitions for one year back/ahead.
    $dateTimeZone = new \DateTimeZone($iCalendarTimezone->getZoneIdentifier());
    $transitions = $dateTimeZone->getTransitions($from->getTimestamp(),
                                                 $to->getTimestamp());

    $first = array_shift($transitions);
    $timezoneOffsetFrom = $first['offset'] / self::$secondsInHour;

    foreach ($transitions as $transitionIdx => $transition) {
      $timezoneRule = $this->buildTimezoneRule($transition,
                                               $dateTimeZone,
                                               $transition['isdst'],
                                               $timezoneOffsetFrom);
      $iCalendarTimezone->addComponent($timezoneRule);
    }

    return $iCalendarTimezone;
  }

  /**
   * @param string $timestampFormat
   */
  public function setTimestampFormat(string $timestampFormat) {
    $this->timestampFormat = $timestampFormat;
  }

  /**
   * @return string
   */
  public function getTimestampFormat() {
    return $this->timestampFormat;
  }

  /**
   * @param $transition
   * @param $dateTimeZone
   * @param $daylightSavings
   * @param $timezoneOffsetFrom
   *
   * @return \Eluceo\iCal\Component\TimezoneRule
   * @throws \InvalidArgumentException
   */
  protected function buildTimezoneRule($transition,
                                       $dateTimeZone,
                                       $daylightSavings,
                                       &$timezoneOffsetFrom) {

    $timezoneType = $daylightSavings ? TimezoneRule::TYPE_DAYLIGHT :
      TimezoneRule::TYPE_STANDARD;
    $timezoneRule = new TimezoneRule($timezoneType);

    $datetime = new \DateTime($transition['time'], $dateTimeZone);
    $timezoneRule->setDtStart($datetime);

    $offset = $transition['offset'] / self::$secondsInHour;
    $from = $this->getFromTime($timezoneOffsetFrom);
    $to = $this->getToTime($offset);

    $timezoneRule->setTzOffsetFrom($from);
    $timezoneRule->setTzOffsetTo($to);
    // Add abbreviated timezone name if available.
    if (!empty($transition['abbr'])) {
      $timezoneRule->setTzName($transition['abbr']);
    }

    $timezoneOffsetFrom = $offset;
    return $timezoneRule;
  }

  /**
   * @param $timezoneOffsetFrom
   *
   * @return string
   */
  private function getFromTime($timezoneOffsetFrom) {

    return sprintf('%s%02d%02d',
                   $timezoneOffsetFrom >= 0 ? '+' : '',
                   floor($timezoneOffsetFrom),
                   ($timezoneOffsetFrom -
                    floor($timezoneOffsetFrom)) * 60);

  }

  /**
   * @param $offset
   *
   * @return string
   */
  private function getToTime($offset) {
    return sprintf('%s%02d%02d',
                   $offset >= 0 ? '+' : '',
                   floor($offset),
                   ($offset - floor($offset)) * 60
    );
  }

  /**
   * @param array $datesList
   *
   * @return \DateTime[]
   */
  private function getMinMaxTimestamps(array $datesList) {

    $min = \DateTime::createFromFormat($this->timestampFormat,
                                       array_pop($datesList));
    $max = clone $min;

    foreach ($datesList as $stamp) {
      $timestamp = \DateTime::createFromFormat($this->timestampFormat, $stamp);
      if ($timestamp > $max) {
        $max = $timestamp;
      }
      if ($timestamp < $min) {
        $min = $timestamp;
      }
    }
    $min->sub(new \DateInterval('P1Y'));
    $max->add(new \DateInterval('P1Y'));
    return [$min, $max];
  }

}