<?php

namespace Drupal\px_calendar_download;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\px_calendar_download\Normalizer\UrlNormalizerInterface;
use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;
use Eluceo\iCal\Component\Timezone;
use Html2Text\Html2Text;
use Symfony\Component\HttpFoundation\Request;

/**
 * Utility class for generating calendars.
 */
class ICalFactory {

  /**
   * The calendar array of properties.
   *
   * @var array
   */
  protected $calendarProperties;

  /**
   * The request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The user's timezone.
   *
   * @var \DateTimeZone
   */
  protected $userDatetimezone;

  /**
   * @var \Drupal\px_calendar_download\Normalizer\UrlNormalizerInterface
   */
  protected $urlNormalizer;

  /**
   * Constructs a new CalendarDownloadUtil.
   *
   * @param \Drupal\px_calendar_download\Normalizer\UrlNormalizerInterface $normalizer
   *
   * @internal param \string[] $calendarProperties An array of calendar
   *           properties.*   An array of calendar properties.
   * @internal param \Symfony\Component\HttpFoundation\Request $request The
   *           request stack used to retrieve the current request.*   The
   *           request stack used to retrieve the current request.
   * @codeCoverageIgnore
   */
  public function __construct(UrlNormalizerInterface $normalizer) {

    $this->urlNormalizer = $normalizer;
  }

  /**
   * Returns a named property from the calendarProperties array.
   *
   * @param string $propertyName
   *   The key of a property in the calendarProperties array.
   *
   * @return string|array|null
   *   The value of that property or NULL if not found.
   */
  protected function getCalendarProperty($propertyName) {
    return isset($this->calendarProperties[$propertyName]) ?
      $this->calendarProperties[$propertyName] : NULL;
  }

  /**
   * Generates an .ics file as a string.
   *
   * @return string The generated ical file as a string.
   * @throws \Drupal\px_calendar_download\Exception\IcalTimezoneInvalidTimestampException
   *
   * @throws \InvalidArgumentException
   * @throws \UnexpectedValueException
   */
  public function generate(array $calendarProperties,
                           Request $request) {

    $this->calendarProperties = $calendarProperties;
    $this->request = $request;
    $this->userDatetimezone = new \DateTimeZone($this->getCalendarProperty('timezone'));

    // The provided 'product_identifier' will be used for iCal's PRODID.
    $iCalendar = new Calendar($this->getCalendarProperty('product_identifier'));
    $iCalendarTimezone = new Timezone($this->getCalendarProperty('timezone'));

    /** @var Timezone $trs */
//    $trs = $this->applyTimezoneTransitions($iCalendarTimezone);
    $tg = new ICalTimezoneGenerator();

    /** @var Timezone $trans */
    $trans = $tg->applyTimezoneTransitions($iCalendarTimezone,
                                           $this->getCalendarProperty('dates_list'));

    $iCalendar->setTimezone($trans);

//    if ($trs !== $trans) {
//      $a = 0;
//    }
    $iCalendar = $this->addEvents($this->getCalendarProperty('dates_list'),
                                  $iCalendar);

    return $iCalendar->render();
  }

  /**
   * Adds an event for each date in the provided datesList.
   *
   * @param string[] $datesList
   *   An array of date strings, i.e. 1970-01-01 01:00:00 Europe/Zurich.
   * @param Calendar $iCalendar
   *   The iCal object to which event components will be added.
   *
   * @return Calendar
   *   The modified calendar object.
   */
  private function addEvents(array $datesList, Calendar $iCalendar) {
    // Using html2text to convert markup into reasonable ASCII text.
    $html2Text = new Html2Text($this->getCalendarProperty('description'));
    $eventUrl = $this->getCalendarProperty('url') ?
      $this->urlNormalizer->normalize($this->getCalendarProperty('url'),
                                      $this->request->getScheme(),
                                      $this->request->getSchemeAndHttpHost()) :
      '';
    foreach ($datesList as $dateIdx => $date) {
      // We need this eventUniqId to be the same on
      // following versions of the generated file, in
      // order to be able to update existing events,
      // e.g. by reimporting the ics file into our calendar.
      $eventUniqId = md5($this->getCalendarProperty('uuid') . $dateIdx);
      $iCalendarEvent = new Event($eventUniqId);
      // Create a datetime object from the stored date value
      // using UTC as timezone.
      $datetime = new \DateTime($date, new \DateTimeZone('UTC'));
      // Set the datetime object using user's timezone.
      // This way the correct time offset will be applied.
      $datetime->setTimezone($this->userDatetimezone);
      $iCalendarEvent
        ->setDtStart($datetime)
        ->setSummary($this->getCalendarProperty('summary'))
        ->setDescription($html2Text->getText())
        ->setDescriptionHTML($this->getCalendarProperty('description'));
      if (!empty($eventUrl)) {
        $iCalendarEvent->setUrl($eventUrl);
      }
      $iCalendarEvent->setUseTimezone(TRUE);
      $iCalendar->addComponent($iCalendarEvent);
    }
    return $iCalendar;
  }

  /**
   * Getting the daylight-saving and standard timezones.
   *
   * Shamelessly copied over from http://stackoverflow.com/a/25971680/5875098.
   *
   * @param Timezone $iCalendarTimezone
   *   An incoming timezone that we may modify by adding component rules,
   *   depending on the user's timezone.
   *
   * @return Timezone
   * @throws \InvalidArgumentException
   *   The modified timezone object.
   */
//  protected function applyTimezoneTransitions(Timezone $iCalendarTimezone) {
//    // First: find the oldest and newest event dates.
//    list($from, $to) = $this->getMinMaxTimestamps($this->getCalendarProperty('dates_list'));
//
//    // Get all transitions for one year back/ahead.
//    $year = 360 * 86400;
//    $now = time();
//    $from = $from ?: $now;
//    $to = $to ?: $now;
//    $datetimezone = new \DateTimeZone($iCalendarTimezone->getZoneIdentifier());
//    $transitions = $datetimezone->getTransitions($from - $year, $to + $year);
//
//    $standardComponent = NULL;
//    $daylightComponent = NULL;
//    $timezoneOffsetFrom = 0;
//    foreach ($transitions as $transitionIdx => $transition) {
//      $component = NULL;
//
//      // Skip the first entry ...
//      if ($transitionIdx === 0) {
//        // ... but remember the offset for the next TZOFFSETFROM value.
//        $timezoneOffsetFrom = $transition['offset'] / 3600;
//        continue;
//      }
//
//      // Daylight saving time definition.
//      if ($transition['isdst']) {
//        $timezoneDaylightComponent = $transition['ts'];
//        $component = $daylightComponent = new TimezoneRule(TimezoneRule::TYPE_DAYLIGHT);
//      } // Standard time definition.
//      else {
//        $timezoneStandardComponent = $transition['ts'];
//        $component = $standardComponent = new TimezoneRule(TimezoneRule::TYPE_STANDARD);
//      }
//
//      if ($component) {
//        $datetime = new \DateTime($transition['time'], $datetimezone);
//        $offset = $transition['offset'] / 3600;
//
//        $component->setDtStart($datetime);
//        $component->setTzOffsetFrom(sprintf('%s%02d%02d',
//                                            $timezoneOffsetFrom >= 0 ? '+' : '',
//                                            floor($timezoneOffsetFrom),
//                                            ($timezoneOffsetFrom -
//                                             floor($timezoneOffsetFrom)) * 60
//                                    ));
//        $component->setTzOffsetTo(sprintf('%s%02d%02d',
//                                          $offset >= 0 ? '+' : '',
//                                          floor($offset),
//                                          ($offset - floor($offset)) * 60
//                                  ));
//        // Add abbreviated timezone name if available.
//        if (!empty($transition['abbr'])) {
//          $component->setTzName($transition['abbr']);
//        }
//
//        $timezoneOffsetFrom = $offset;
//        $iCalendarTimezone->addComponent($component);
//      }
//
//      // We covered the entire date range.
//      if ($standardComponent &&
//          $daylightComponent &&
//          min($timezoneStandardComponent, $timezoneDaylightComponent) < $from &&
//          max($timezoneStandardComponent, $timezoneDaylightComponent) > $to
//      ) {
//        break;
//      }
//    }
//    return $iCalendarTimezone;
//  }

  /**
   * Sort and return the oldest and newest event dates as timestamps.
   *
   * @param string[] $datesList
   *   An array of date strings, i.e. 1970-01-01 01:00:00 Europe/Zurich.
   *
   * @return integer[]
   *   The found pair of min and max timestamps.
   */
//  protected function getMinMaxTimestamps(array $datesList) {
//    $min = $max = strtotime(array_pop($datesList));
//    foreach ($datesList as $date) {
//      $timestamp = strtotime($date);
//      if ($timestamp > $max) {
//        $max = $timestamp;
//      }
//      if ($timestamp < $min) {
//        $min = $timestamp;
//      }
//    }
//    return [$min, $max];
//  }

}
