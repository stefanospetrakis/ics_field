<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 19.01.17
 * Time: 12:00
 */

namespace Drupal\px_calendar_download\CalendarProperty;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Utility\Token;
use Drupal\px_calendar_download\Timezone\TimezoneProviderInterface;

/**
 * Class CalendarPropertyProcessorFactory
 *
 * @package Drupal\px_calendar_download\CalendarProperty
 */
class CalendarPropertyProcessorFactory {

  /**
   * @var TimezoneProviderInterface
   */
  private $timezoneProvider;

  /**
   * @var Token
   */
  private $token;

  /**
   * CalendarPropertyProcessorFactory constructor.
   *
   * @param \Drupal\px_calendar_download\Timezone\TimezoneProviderInterface $timezoneProvider
   * @param \Drupal\Core\Utility\Token                                      $token
   */
  public function __construct(TimezoneProviderInterface $timezoneProvider,
                              Token $token) {
    $this->timezoneProvider = $timezoneProvider;
    $this->token = $token;
  }

  /**
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *
   * @return \Drupal\px_calendar_download\CalendarProperty\CalendarPropertyProcessor
   */
  public function create(FieldDefinitionInterface $fieldDefinition) {

    return new CalendarPropertyProcessor($this->token,
                                         $this->timezoneProvider,
                                         $fieldDefinition->getSetting('date_field_reference'),
                                         $fieldDefinition->getConfig($fieldDefinition->getTargetBundle())
                                                         ->uuid());

  }

}