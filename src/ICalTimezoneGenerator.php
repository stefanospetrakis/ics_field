<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 18.01.17
 * Time: 00:35
 */

namespace Drupal\px_calendar_download;

use Drupal\px_calendar_download\Exception\IcalTimezoneInvalidTimestampException;
use Eluceo\iCal\Component\Timezone;
use Eluceo\iCal\Component\TimezoneRule;

/**
 * Class IcalTimezoneGenerator
 *
 * @package Drupal\px_calendar_download
 */
class ICalTimezoneGenerator {

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
   * @throws \Drupal\px_calendar_download\Exception\IcalTimezoneInvalidTimestampException
   *
   * @throws \InvalidArgumentException
   */
  public function applyTimezoneTransitions(Timezone $iCalendarTimezone,
                                           $dateList) {

    list($from, $to) = $this->getMinMaxTimestamps($dateList);
    list($from, $to) = $this->adjustTimestampsByAYear($from, $to);

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
   * @param array $datesList
   *
   * @return \DateTime[]
   * @throws \Drupal\px_calendar_download\Exception\IcalTimezoneInvalidTimestampException
   */
  public function getMinMaxTimestamps(array $datesList) {

    $min = \DateTime::createFromFormat($this->timestampFormat,
                                       array_pop($datesList));
    if (!$min) {
      throw new IcalTimezoneInvalidTimestampException('timestap format does not match ' .
                                                      $this->timestampFormat);
    }
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
    return [$min, $max];
  }

  /**
   * Add and subtract a year from min/max timestamps
   *
   * @param \DateTime $from
   * @param \DateTime $to
   *
   * @return array
   */
  private function adjustTimestampsByAYear(\DateTime $from, \DateTime $to) {
    return [
      $from->sub(new \DateInterval('P1Y')),
      $to->add(new \DateInterval('P1Y')),
    ];
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

}