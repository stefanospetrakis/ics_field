<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 17.01.17
 * Time: 23:34
 */

namespace Drupal\px_calendar_download;

//use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\Utility\Token;
use Drupal\px_calendar_download\Exception\CalendarDownloadInvalidPropertiesException;
use Drupal\px_calendar_download\Timezone\TimezoneProviderInterface;

/**
 * Class CalendarPropertyProcessor
 *
 * @package Drupal\px_calendar_download
 */
class CalendarPropertyProcessor {

  /**
   * @var \Drupal\Core\Utility\Token
   */
  protected $tokenService;

  /**
   * @var string
   */
  protected $dateFieldReference;

  /**
   * @var string
   */
  protected $dateFieldUuid;

  /**
   * @var \Drupal\px_calendar_download\Timezone\TimezoneProviderInterface
   */
  protected $timezoneProvider;

  /**
   * @var array
   */
  protected $essentialProperties = [
    'timezone',
    'product_identifier',
    'summary',
    'dates_list',
    'uuid',
  ];

  /**
   * @var TranslationManager
   */
  protected $stringTranslation;

  /**
   * @return array
   */
  public function getEssentialProperties() {
    return $this->essentialProperties;
  }

  /**
   * @param array $essentialProperties
   */
  public function setEssentialProperties(array $essentialProperties) {
    $this->essentialProperties = $essentialProperties;
  }

  /**
   * CalendarPropertyProcessor constructor.
   *
   * @param \Drupal\Core\Utility\Token                        $tokenService
   * @param TimezoneProviderInterface                         $timezoneProvider
   * @param string                                            $dateFieldReference
   * @param string                                            $dateFieldUuid
   * @param \Drupal\Core\StringTranslation\TranslationManager $stringTranslation
   */
  public function __construct(Token $tokenService,
                              TimezoneProviderInterface $timezoneProvider,
                              $dateFieldReference,
                              $dateFieldUuid,
                              TranslationManager $stringTranslation) {
    $this->tokenService = $tokenService;
    $this->timezoneProvider = $timezoneProvider;
    $this->dateFieldReference = $dateFieldReference;
    $this->dateFieldUuid = $dateFieldUuid;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * @param array  $tokens
   * @param string $host
   *
   * @return array
   * @throws \Drupal\px_calendar_download\Exception\CalendarDownloadInvalidPropertiesException
   * @throws \InvalidArgumentException
   */
  public function getCalendarProperties(array $tokens,
                                        ContentEntityInterface $contentEntity,
                                        $host = 'http') {
    $calendarProperties = [];
    // Set default timezone
    // Note: Use the following if we want to use the site's timezone.
    // $calendarProperties['timezone'] = \Drupal::config('system.date')->get('timezone.default');
    $calendarProperties['timezone'] = $this->timezoneProvider->getTimezoneString();
    // Use the hostname to set the 'product_identifier' value.
    $calendarProperties['product_identifier'] = $host;
    //TODO - should uuid contain a separator ie :
    $calendarProperties['uuid'] = $contentEntity->uuid() . $this->dateFieldUuid;

    // Uses token replacement to interpolate tokens in the field's fields that support them.
    $data = [$contentEntity->getEntityTypeId() => $contentEntity];
    $calendarProperties = array_merge($calendarProperties,
                                      $this->tokenService->replace($tokens,$data));

    $calendarProperties['dates_list'] = $this->processDateList($contentEntity);

    $this->validate($calendarProperties);

    return $calendarProperties;
  }

  /**
   * @return array
   * @throws \Drupal\px_calendar_download\Exception\CalendarDownloadInvalidPropertiesException
   * @throws \InvalidArgumentException
   */
  private function processDateList(ContentEntityInterface $contentEntity) {

    $calendarProperties = [];
    if (!empty($this->dateFieldReference)) {
      foreach ($contentEntity->get($this->dateFieldReference)
                             ->getValue() as $dateVal) {
        if (!$dateVal['value'] instanceof DrupalDateTime) {
          continue;
        }
        $calendarProperties[] = $dateVal['value']->render();
      }
    }
    return $calendarProperties;
  }

  /**
   * Check that the calendar properties are valid.
   *
   * @param string[] $calendarProperties
   *   An array of calendar properties.
   *
   * @return bool
   *
   * @throws \Drupal\px_calendar_download\Exception\CalendarDownloadInvalidPropertiesException
   *   True if the check was successful, otherwise false.
   *
   * @throws CalendarDownloadInvalidPropertiesException
   *   An invalid (empty) calendar property exception.
   */
  protected function validate(array $calendarProperties) {
    // N.B.: There could be more complex validation taking place here.
    foreach ($this->essentialProperties as $essentialProperty) {
      if (!array_key_exists($essentialProperty, $calendarProperties) ||
          empty($calendarProperties[$essentialProperty])
      ) {

        throw new CalendarDownloadInvalidPropertiesException($this->stringTranslation->translate('Missing needed property @propertyName.',
                                                                                                 ['@propertyName' => $essentialProperty]));
      }
    }
    return TRUE;
  }

}