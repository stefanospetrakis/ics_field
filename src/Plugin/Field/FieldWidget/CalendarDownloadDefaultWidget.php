<?php

namespace Drupal\px_calendar_download\Plugin\Field\FieldWidget;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\Entity\File;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\px_calendar_download\CalendarDownloadUtil;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Plugin implementation of the 'calendar_download_default_widget' widget.
 *
 * @FieldWidget(
 *   id = "calendar_download_default_widget",
 *   label = @Translation("Calendar download default widget"),
 *   field_types = {
 *     "calendar_download_type"
 *   }
 * )
 */
class CalendarDownloadDefaultWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The entity_field.manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $tokenService;

  /**
   * {@inheritdoc}
   */
  public function __construct($pluginId,
                              $pluginDefinition,
                              FieldDefinitionInterface $fieldDefinition,
                              array $settings,
                              array $thirdPartySettings,
                              Request $request,
                              Token $tokenService,
                              EntityFieldManager $entityFieldManager,
                              LoggerChannelInterface $logger) {
    parent::__construct($pluginId,
                        $pluginDefinition,
                        $fieldDefinition,
                        $settings,
                        $thirdPartySettings);

    $this->request = $request;
    $this->tokenService = $tokenService;
    $this->entityFieldManager = $entityFieldManager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public static function create(ContainerInterface $container,
                                array $configuration,
                                $pluginId,
                                $pluginDefinition) {
    return new static(
      $pluginId,
      $pluginDefinition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('token'),
      $container->get('entity_field.manager'),
      $container->get('logger.factory')->get('px_calendar_download')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $formState) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * @throws \LogicException
   */
  public function formElement(FieldItemListInterface $items,
                              $delta,
                              array $element,
                              array &$form,
                              FormStateInterface $formState) {
    $fieldDefinitions = $this->getEntityFieldDefinitions();
    $element['summary'] = [
      '#type'          => 'textfield',
      '#placeholder'   => t('Summary'),
      '#title'         => t('Summary'),
      '#default_value' => isset($items[$delta]->summary) ?
        $items[$delta]->summary : NULL,
    ];
    $element['description'] = [
      '#type'          => 'textarea',
      '#placeholder'   => t('Description'),
      '#title'         => t('Description'),
      '#default_value' => isset($items[$delta]->description) ?
        $items[$delta]->description : NULL,
    ];
    $element['url'] = [
      '#type'          => 'textfield',
      '#placeholder'   => t('URL'),
      '#title'         => t('URL'),
      '#default_value' => isset($items[$delta]->url) ? $items[$delta]->url :
        NULL,
    ];
    $tokenTree = [];
    foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
      $tokenTree['[node:' . $fieldName . ']'] = [
        'name'   => $fieldDefinition->getLabel(),
        'tokens' => [],
      ];
    }
    $element['tokens'] = [
      '#type'     => 'details',
      '#title'    => t('Tokens'),
      'tokenlist' => [
        '#type'       => 'token_tree_table',
        '#columns'    => ['token', 'name'],
        '#token_tree' => $tokenTree,
      ],
    ];

    // If cardinality is 1, ensure a label is output for the field by wrapping
    // it in a details element.
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() === 1) {
      $element += ['#type' => 'fieldset'];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * @brief: Create/Update the referenced file.
   *
   * @throws \LogicException
   * @throws \UnexpectedValueException
   */
  public function massageFormValues(array $values,
                                    array $form,
                                    FormStateInterface $formState) {
    $contentEntity = $this->getContentEntityFromForm($formState);
    if ($contentEntity) {
      $fieldDefinitions = $this->getEntityFieldDefinitions();
      foreach ($formState->getValues() as $key => $value) {
        if (isset($fieldDefinitions[$key])) {
          try {
            $contentEntity->set($key, $value);
          }
          catch (\InvalidArgumentException $e) {
            $this->logger->error($e->getMessage());
          }
        }
      }
      foreach ($values as $key => &$value) {
        $this->updateManagedCalFile($value, $contentEntity);
      }
    }
    return $values;
  }

  /**
   * Generate and save an .ics file.
   *
   * @param mixed[]         $formValue
   *   Incoming array with the form values of the widget.
   * @param ContentEntityBase $contentEntity
   *   Incoming content entity with the rest of the entity's submitted values.
   *
   * @throws \UnexpectedValueException
   */
  private function updateManagedCalFile(array &$formValue,
                                        ContentEntityBase $contentEntity) {
    $calendarProperties = $this->instantiateCalendarProperties($formValue,
                                                               $contentEntity);
    if (!empty($calendarProperties['dates_list'])) {
      try {
        $calendarDownloadUtil = new CalendarDownloadUtil($calendarProperties,
                                                         $this->request);
        $icsFileStr = $calendarDownloadUtil->generate();
        $formValue['fileref'] = $this->saveManagedCalendarFile($contentEntity,
                                                               $icsFileStr,
                                                               isset($formValue['fileref']) ?
                                                                 $formValue['fileref'] :
                                                                 0);
      } catch (\Exception $e) {
        $this->logger->error($e->getMessage());
      }
    }
  }

  /**
   * Instantiate the calendar's properties.
   *
   * @param mixed[]         $formValue
   *   Incoming array with the form values of the widget.
   * @param ContentEntityBase $contentEntity
   *   Incoming content entity with the rest of the entity's submitted values.
   *
   * @return string[]
   *
   * @throws \UnexpectedValueException
   *   Returns an array of instantiated calendarProperties.
   */
  private function instantiateCalendarProperties(array $formValue,
                                                 ContentEntityBase $contentEntity) {
    $calendarProperties = [];
    // Set default timezone
    // Note: Use the following if we want to use the site's timezone.
    // $calendarProperties['timezone'] = \Drupal::config('system.date')->get('timezone.default');
    $calendarProperties['timezone'] = drupal_get_user_timezone();
    // Use the hostname to set the 'product_identifier' value.
    $calendarProperties['product_identifier'] = $this->request->getHost();
    // Uses token replacement to interpolate tokens in the field's fields that support them.
    $calendarProperties['summary'] = $this->tokenService->replace($formValue['summary'],
                                                                  [$contentEntity->getEntityTypeId() => $contentEntity]);
    $calendarProperties['url'] = $this->tokenService->replace($formValue['url'],
                                                              [$contentEntity->getEntityTypeId() => $contentEntity]);
    $calendarProperties['description'] = $this->tokenService->replace($formValue['description'],
                                                                      [$contentEntity->getEntityTypeId() => $contentEntity]);
    $calendarProperties['uuid'] = $contentEntity->uuid() .
                                  $this->fieldDefinition->getConfig($this->fieldDefinition->getTargetBundle())->uuid();

    $calendarProperties['dates_list'] = [];
    $dateFieldReference = $this->fieldDefinition->getSetting('date_field_reference');
    if (!empty($dateFieldReference)) {
      //TODO refactor this to not use $contentEntity->$dateFieldReference form to get value
      foreach ($contentEntity->$dateFieldReference->getValue() as $dateVal) {
        if (!$dateVal['value'] instanceof DrupalDateTime) {
          continue;
        }
        $calendarProperties['dates_list'][] = $dateVal['value']->render();
      }
    }
    return $calendarProperties;
  }

  /**
   * Create/Update managed ical file.
   *
   * @param ContentEntityBase $contentEntity
   *   Incoming content entity with the rest of the entity's submitted values.
   * @param string          $icsFileStr
   *   The ics file as a string.
   * @param int             $fileId
   *   The file id of the managed ical file.
   *
   * @return int
   *   Returns the file id of the created/updated file.
   */
  private function saveManagedCalendarFile(ContentEntityBase $contentEntity,
                                           string $icsFileStr,
                                           int $fileId = 0) {
    // Overwrite an existing managed file.
    if ($fileId > 0) {
      $file = File::load($fileId);
      $fileUri = $file->getFileUri();
      if (!file_save_data($icsFileStr, $fileUri, FILE_EXISTS_REPLACE)) {
        $this->handleFileSaveError($fileUri);
      }
      return $fileId;
    }
    // Create a new managed file, if there is no
    // existing one and give it a persistent
    // unique file name (i.e. entity's uuid).
    else {
      $uriScheme = $this->fieldDefinition->getSetting('uri_scheme');
      $fileDirectory = $this->fieldDefinition->getSetting('file_directory');
      $uploadLocation = $this->tokenService->replace($uriScheme . '://' .
                                                     $fileDirectory);
      if (file_prepare_directory($uploadLocation, FILE_CREATE_DIRECTORY)) {
        $fileName = md5($contentEntity->uuid() . $this->fieldDefinition->getConfig($this->fieldDefinition->getTargetBundle())->uuid()) .
                    '_event.ics';
        $fileUri = $uploadLocation . '/' . $fileName;
        $file = file_save_data($icsFileStr,
                               $fileUri,
                               FILE_EXISTS_REPLACE);
        if (!$file) {
          $this->handleFileSaveError($fileUri);
        }
        return $file->id();
      } else {
        $this->handleDirectoryError($uploadLocation);
      }
    }
    return NULL;
  }

  /**
   * Get the fields/properties from the entity the widget is attached to.
   *
   * @return FieldDefinitionInterface[]
   *
   * @throws \LogicException
   *   An array of FieldDefinitionInterfaces for all fields/properties.
   */
  private function getEntityFieldDefinitions() {
    $attBundle = $this->fieldDefinition->getConfig($this->fieldDefinition->getTargetBundle())->get('bundle');
    $attEntityType = $this->fieldDefinition->get('entity_type');
    $fieldDefinitions = array_filter(
                          $this->entityFieldManager->getBaseFieldDefinitions($attEntityType),
                          function ($fieldDefinition) {
                            return $fieldDefinition instanceof
                                   FieldDefinitionInterface;
                          }
                        ) + array_filter(
                          $this->entityFieldManager->getFieldDefinitions($attEntityType,
                                                                         $attBundle),
                          function ($fieldDefinition) {
                            return $fieldDefinition instanceof
                                   FieldDefinitionInterface;
                          }
                        );
    // Do not include ourselves in the list of fields that we'll use
    // for token replacement.
    foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
      if ($fieldName === $this->fieldDefinition->get('field_name')) {
        unset($fieldDefinitions[$fieldName]);
        break;
      }
    }
    return $fieldDefinitions;
  }

  /**
   * @param string $fileUri
   */
  private function handleFileSaveError($fileUri) {
    $msg = 'Could not save calendar file: ' . $fileUri;
    drupal_set_message($msg, 'error');
    $this->logger->error($msg);
  }

  /**
   * @param string $uri
   */
  private function handleDirectoryError($uri) {
    $msg = 'Could not access calendar directory: ' . $uri;
    drupal_set_message($msg, 'error');
    $this->logger->error($msg);
  }

  /**
   * Extract and return a ContentEntity from a form.
   *
   * @param FormStateInterface $formState
   *   Incoming FormStateInterface object.
   *
   * @return ContentEntityBase|NULL
   */
  private function getContentEntityFromForm(FormStateInterface $formState) {
    $formObject = $formState->getFormObject();
    if ($formObject instanceof ContentEntityForm) {
      $contentEntity = $formObject->getEntity();
      if ($contentEntity instanceof ContentEntityBase) {
        return $contentEntity;
      }
    }
    return NULL;
  }

}
