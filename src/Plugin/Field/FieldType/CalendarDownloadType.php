<?php

namespace Drupal\px_calendar_download\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'calendar_download_type' field type.
 *
 * @FieldType(
 *   id = "calendar_download_type",
 *   label = @Translation("Calendar download"),
 *   category = @Translation("Media"),
 *   description = @Translation("Provides a dynamically generated .ics file download"),
 *   default_widget = "calendar_download_default_widget",
 *   default_formatter = "calendar_download_default_formatter"
 * )
 */
class CalendarDownloadType extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
      'is_ascii' => FALSE,
      'uri_scheme' => 'public',
      'file_directory' => 'icsfiles',
      'vdate_field' => NULL,
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['vsummary'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Summary'))
      ->setRequired(TRUE);
    $properties['vdescription'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setRequired(TRUE);
    $properties['vurl'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('URL'));
    $properties['vfileref'] = DataDefinition::create('string')
      ->setComputed(TRUE)
      ->setLabel(new TranslatableMarkup('ics File reference'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $target_type_info = \Drupal::entityManager()->getDefinition('file');
    $schema = [
      'columns' => [
        'vsummary' => [
          'description' => 'The SUMMARY field of a VEVENT.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'vdescription' => [
          'description' => 'The DESCRIPTION field of a VEVENT.',
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
        'vurl' => [
          'description' => 'The URL field of a VEVENT.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'vfileref' => [
          'description' => 'The ID of the target ics file entity.',
          'type' => 'varchar_ascii',
          // If the target entities act as bundles for another entity type,
          // their IDs should not exceed the maximum length for bundles.
          'length' => $target_type_info->getBundleOf() ? EntityTypeInterface::BUNDLE_MAX_LENGTH : 255,
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $elements = [];
    $field_definitions = $this->getEntity()->getFieldDefinitions();
    $date_fields = [];
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->getType() == 'datetime') {
        $date_fields[$field_name] = $field_definition->getLabel();
      }
    }
    $elements['vdate_field'] = [
      '#type' => 'select',
      '#options' => $date_fields,
      '#title' => t('Date field'),
      '#required' => TRUE,
      '#empty_option' => t('- Select -'),
      '#default_value' => $this->getSetting('vdate_field') ?: '',
      '#description' => t("Select the date field that will define when the calendar\'s events take place."),
    ];
    $form['#validate'][] = [$this, 'checkWriteableDirectory'];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('vsummary')->getValue();
    return $value === NULL || $value === '';
  }

  /**
   * A function that checks if the default directory for ics files is writeable.
   */
  public function checkWriteableDirectory(&$element, FormStateInterface $form_state, &$complete_form) {
    $uri_scheme = $this->getSetting('uri_scheme');
    $file_directory = $this->getSetting('file_directory');
    $token_service = \Drupal::service('token');
    $upload_location = $token_service->replace($uri_scheme . '://' . $file_directory);
    if (!file_prepare_directory($upload_location, FILE_CREATE_DIRECTORY)) {
      $form_state->setError($element, t('Cannot create folder for ics files [@upload_location]', ['@upload_location' => $upload_location]));
    }
  }

}
