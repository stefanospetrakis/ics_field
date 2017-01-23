<?php

namespace Drupal\ics_field\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\Entity\File;
use Drupal\ics_field\CalendarProperty\CalendarPropertyProcessor;
use Drupal\ics_field\ICalFactory;
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
   * @var CalendarPropertyProcessor
   */
  protected $calendarPropertyProcessor;

  /**
   * @var ICalFactory
   */
  protected $iCalFactory;

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
                              LoggerChannelInterface $logger,
                              CalendarPropertyProcessor $calendarPropertyProcessor,
                              ICalFactory $iCalFactory) {

    parent::__construct($pluginId,
                        $pluginDefinition,
                        $fieldDefinition,
                        $settings,
                        $thirdPartySettings);

    $this->request = $request;
    $this->tokenService = $tokenService;
    $this->entityFieldManager = $entityFieldManager;
    $this->logger = $logger;
    $this->calendarPropertyProcessor = $calendarPropertyProcessor;
    $this->iCalFactory = $iCalFactory;

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
      $container->get('logger.factory')->get('ics_field'),
      $container->get('ics_field.calendar_property_processor_factory')
                ->create($configuration['field_definition']),
      $container->get('ics_field.ical_factory')
    );
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

    $fieldConfig = $this->isFieldConfigForm($formState);
    $fieldDefinitions = $this->getEntityFieldDefinitions();
    $element['summary'] = [
      '#type'          => 'textfield',
      '#placeholder'   => t('Summary'),
      '#title'         => t('Summary'),
      '#default_value' => isset($items[$delta]->summary) ?
        $items[$delta]->summary : NULL,
      '#required'      => !$fieldConfig,
    ];
    $element['description'] = [
      '#type'          => 'textarea',
      '#placeholder'   => t('Description'),
      '#title'         => t('Description'),
      '#default_value' => isset($items[$delta]->description) ?
        $items[$delta]->description : NULL,
      '#required'      => !$fieldConfig,
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
    if ($this->fieldDefinition->getFieldStorageDefinition()
                              ->getCardinality() === 1
    ) {
      $element += ['#type' => 'fieldset'];
    }

    return $element;
  }

  /**
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *
   * @return bool
   */
  private function isFieldConfigForm(FormStateInterface $formState){

    $build = $formState->getBuildInfo();
    return $build['base_form_id'] === 'field_config_form';
  }

  /**
   * {@inheritdoc}
   *
   * @brief: Create/Update the referenced file.
   *
   * @throws \LogicException
   * @throws \UnexpectedValueException
   * @throws \InvalidArgumentException
   * @throws \Drupal\ics_field\Exception\CalendarDownloadInvalidPropertiesException
   */
  public function massageFormValues(array $values,
                                    array $form,
                                    FormStateInterface $formState) {
    $contentEntity = $this->getContentEntityFromForm($formState);
    if ($contentEntity) {
      $contentEntity = $this->makeUpdatedEntityCopy($formState, $contentEntity);
      foreach ($values as $key => &$value) {
        //we need to do a test here and convert null to 0. because of the entity contraints
        $ref = $this->updateManagedCalFile($value, $contentEntity);
        $value['fileref'] = ($ref === NULL) ? 0 : $ref;
      }
    }
    return $values;
  }

  /**
   * @param \Drupal\Core\Form\FormStateInterface       $formState
   * @param \Drupal\Core\Entity\ContentEntityInterface $contentEntity
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   * @throws \LogicException
   */
  private function makeUpdatedEntityCopy(FormStateInterface $formState,
                                         ContentEntityInterface $contentEntity) {

    $entity = clone $contentEntity;
    $fieldDefinitions = $this->getEntityFieldDefinitions();
    foreach ($formState->getValues() as $key => $value) {
      if (isset($fieldDefinitions[$key])) {
        try {
          $entity->set($key, $value);
        } catch (\InvalidArgumentException $e) {
          $this->logger->error($e->getMessage());
        }
      }
    }
    return $entity;

  }

  /**
   * Generate and save an .ics file.
   *
   * @param mixed[]                                                                          $formValue
   *   Incoming array with the form values of the widget.
   * @param \Drupal\Core\Entity\ContentEntityBase|\Drupal\Core\Entity\ContentEntityInterface $contentEntity
   *   Incoming content entity with the rest of the entity's submitted values.
   *
   * @return int|null
   */
  private function updateManagedCalFile(array &$formValue,
                                        ContentEntityInterface $contentEntity) {

    $calendarProperties = $this->calendarPropertyProcessor->getCalendarProperties([
                                                                                    'summary'     => $formValue['summary'],
                                                                                    'url'         => $formValue['url'],
                                                                                    'description' => $formValue['description'],
                                                                                  ],
                                                                                  $contentEntity,
                                                                                  $this->request->getHost());
    if (!empty($calendarProperties['dates_list'])) {
      try {
        $icsFileStr = $this->iCalFactory->generate($calendarProperties,
                                                   $this->request);
        return $this->saveManagedCalendarFile($contentEntity,
                                              $icsFileStr,
                                              isset($formValue['fileref']) ?
                                                $formValue['fileref'] :
                                                NULL);
      } catch (\Exception $e) {
        $this->logger->error($e->getMessage());
      }
    }
    return NULL;
  }

  /**
   * Create/Update managed ical file.
   *
   * @param ContentEntityBase $contentEntity
   *   Incoming content entity with the rest of the entity's submitted values.
   * @param string            $icsFileStr
   *   The ics file as a string.
   * @param int               $fileId
   *   The file id of the managed ical file.
   *
   * @return int|null
   *   Returns the file id of the created/updated file.
   */
  private function saveManagedCalendarFile(ContentEntityBase $contentEntity,
                                           $icsFileStr,
                                           $fileId = 0) {
    // Overwrite an existing managed file.
    return $fileId ? $this->updateFile($fileId, $icsFileStr) :
      $this->createNewFile($contentEntity, $icsFileStr);
  }

  /**
   * @param string $fileId
   * @param string $icsFileStr
   *
   * @return mixed
   */
  private function updateFile($fileId, $icsFileStr) {

    $file = File::load($fileId);
    $fileUri = $file->getFileUri();
    if (!file_save_data($icsFileStr, $fileUri, FILE_EXISTS_REPLACE)) {
      $this->handleFileSaveError($fileUri);
    }
    //Always return the file id, so that it retains the reference to the original
    //even if saving the update fails
    return $fileId;
  }

  /**
   * @param \Drupal\Core\Entity\ContentEntityBase $contentEntity
   * @param string                                $icsFileStr
   *
   * @return int|null|string
   */
  private function createNewFile(ContentEntityBase $contentEntity,
                                 $icsFileStr) {

    // Create a new managed file, if there is no
    // existing one and give it a persistent
    // unique file name (i.e. entity's uuid).
    $uriScheme = $this->fieldDefinition->getSetting('uri_scheme');
    $fileDirectory = $this->fieldDefinition->getSetting('file_directory');
    $uploadLocation = $this->tokenService->replace($uriScheme . '://' .
                                                   $fileDirectory);
    if (file_prepare_directory($uploadLocation, FILE_CREATE_DIRECTORY)) {
      $fileName = md5($contentEntity->uuid() .
                      $this->fieldDefinition->getConfig($this->fieldDefinition->getTargetBundle())
                                            ->uuid()) .
                  '_event.ics';
      $fileUri = $uploadLocation . '/' . $fileName;
      $file = file_save_data($icsFileStr,
                             $fileUri,
                             FILE_EXISTS_REPLACE);
      if ($file) {
        return $file->id();
      }

      $this->handleFileSaveError($fileUri);
    } else {
      $this->handleDirectoryError($uploadLocation);
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
    $attBundle = $this->fieldDefinition->getConfig($this->fieldDefinition->getTargetBundle())
                                       ->get('bundle');
    $attEntityType = $this->fieldDefinition->get('entity_type');

    /** @var FieldDefinitionInterface[] $fieldDefinitions */
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
    $definitions = array_filter($fieldDefinitions,
      function ($key) {
        return $key !== $this->fieldDefinition->get('field_name');
      },
                                ARRAY_FILTER_USE_KEY);
    return $definitions;
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
   * @return ContentEntityBase|null
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
