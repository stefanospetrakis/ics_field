<?php

namespace Drupal\ics_field\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;

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

  use StringTranslationTrait;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $tokenService;

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition,
                              $name = NULL,
                              TypedDataInterface $parent = NULL,
                              $tokenService) {
    parent::__construct($definition, $name, $parent);
    $this->tokenService = $tokenService;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance($definition,
                                        $name = NULL,
                                        TraversableTypedDataInterface $parent = NULL) {
    return new static(
      $definition,
      $name,
      $parent,
      \Drupal::token()
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
             'is_ascii'             => FALSE,
             'uri_scheme'           => 'public',
             'file_directory'       => 'icsfiles',
             'date_field_reference' => NULL,
           ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $fieldDefinition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['summary'] = DataDefinition::create('string')
                                           ->setLabel(new TranslatableMarkup('Summary'))
                                           ->setRequired(TRUE);
    $properties['description'] = DataDefinition::create('string')
                                               ->setLabel(new TranslatableMarkup('Description'))
                                               ->setRequired(TRUE);
    $properties['url'] = DataDefinition::create('string')
                                       ->setLabel(new TranslatableMarkup('URL'));
    $properties['fileref'] = DataDefinition::create('string')
                                           ->setComputed(TRUE)
                                           ->setLabel(new TranslatableMarkup('ics File reference'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function schema(FieldStorageDefinitionInterface $fieldDefinition) {
    $targetTypeInfo = \Drupal::entityTypeManager()->getDefinition('file');
    $schema = [
      'columns' => [
        'summary'     => [
          'description' => 'The SUMMARY field of a VEVENT.',
          'type'        => 'varchar',
          'length'      => 255,
          'not null'    => FALSE,
        ],
        'description' => [
          'description' => 'The DESCRIPTION field of a VEVENT.',
          'type'        => 'text',
          'size'        => 'big',
          'not null'    => FALSE,
        ],
        'url'         => [
          'description' => 'The URL field of a VEVENT.',
          'type'        => 'varchar',
          'length'      => 255,
          'not null'    => FALSE,
        ],
        'fileref'     => [
          'description' => 'The ID of the target ics file entity.',
          'type'        => 'varchar_ascii',
          // If the target entities act as bundles for another entity type,
          // their IDs should not exceed the maximum length for bundles.
          'length'      => $targetTypeInfo->getBundleOf() ?
            EntityTypeInterface::BUNDLE_MAX_LENGTH : 255,
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form,
                                      FormStateInterface $formState,
                                      $hasData) {
    $elements = [];
    $fieldDefinitions = $this->getEntity()->getFieldDefinitions();
    $dateFields = [];
    foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getType() === 'datetime') {
        $dateFields[$fieldName] = $fieldDefinition->getLabel();
      }
    }
    $elements['date_field_reference'] = [
      '#type'          => 'select',
      '#options'       => $dateFields,
      '#title'         => $this->t('Date field'),
      '#required'      => TRUE,
      '#empty_option'  => $this->t('- Select -'),
      '#default_value' => $this->getSetting('date_field_reference') ?: '',
      '#description'   => $this->t('Select the date field that will define when the calendar\'s events take place.'),
    ];
    $form['#validate'][] = [$this, 'checkWriteableDirectory'];

    return $elements;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \InvalidArgumentException
   */
  public function isEmpty() {
    $value = $this->get('summary')->getValue();
    return $value === NULL || $value === '';
  }

  /**
   * A function that checks if the default directory for ics files is writeable.
   *
   * @param array                                $element
   * @param \Drupal\Core\Form\FormStateInterface $formState
   */
  public function checkWriteableDirectory(array $element,
                                          FormStateInterface $formState) {
    $uriScheme = $this->getSetting('uri_scheme');
    $fileDirectory = $this->getSetting('file_directory');
    $uploadLocation = $this->tokenService->replace($uriScheme . '://' .
                                                   $fileDirectory);
    if (!file_prepare_directory($uploadLocation, FILE_CREATE_DIRECTORY)) {
      $formState->setError($element,
                           $this->t('Cannot create folder for ics files [@upload_location]',
                                    ['@upload_location' => $uploadLocation]));
    }
  }

}
