<?php

namespace Drupal\ics_field;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\Entity\File;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\ics_field\CalendarProperty\CalendarPropertyProcessorFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A service that handles updating calendar files during a hook_entity_presave.
 */
class IcsFileManager {

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
   * The file.usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsageService;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $tokenService;

  /**
   * @var CalendarPropertyProcessorFactory
   */
  protected $calendarPropertyProcessorFactory;

  /**
   * @var ICalFactory
   */
  protected $iCalFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(RequestStack $requestStack,
                              Token $tokenService,
                              EntityFieldManager $entityFieldManager,
                              FileUsageInterface $fileUsageService,
                              LoggerChannelFactoryInterface $loggerFactory,
                              CalendarPropertyProcessorFactory $calendarPropertyProcessorFactory,
                              ICalFactory $iCalFactory) {
    $this->request = $requestStack->getCurrentRequest();
    $this->tokenService = $tokenService;
    $this->entityFieldManager = $entityFieldManager;
    $this->fileUsageService = $fileUsageService;
    $this->logger = $loggerFactory->get('ics_field');
    $this->calendarPropertyProcessorFactory = $calendarPropertyProcessorFactory;
    $this->iCalFactory = $iCalFactory;
  }

  /**
   * Updates a node's ics file(s).
   *
   * @param ContentEntityBase $contentEntity
   *   Incoming content entity.
   */
  public function updateIcalFile(ContentEntityBase $contentEntity, FieldDefinitionInterface $fieldConfig, array $fieldValue) {
    $calendarPropertyProcessor = $this->calendarPropertyProcessorFactory->create($fieldConfig);
    $tokens = [
      'summary'     => $fieldValue['summary'],
      'url'         => $fieldValue['url'],
      'description' => $fieldValue['description'],
    ];
    $calendarProperties = $calendarPropertyProcessor->getCalendarProperties($tokens,
                                                                            $contentEntity,
                                                                            $this->request->getHost());
    if (!empty($calendarProperties['dates_list'])) {
      try {
        $timeStampFormat = 'Y-m-d\TH:i:s';
        $icsFileStr = $this->iCalFactory->generate($calendarProperties,
                                                  $this->request,
                                                  $timeStampFormat);
        return $this->saveManagedCalendarFile($contentEntity,
                                              $fieldConfig,
                                              $icsFileStr,
                                              isset($fieldValue['fileref']) ?
                                                $fieldValue['fileref'] :
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
   *   Incoming content entity.
   * @param string            $icsFileStr
   *   The ics file as a string.
   * @param int               $fileId
   *   The file id of the managed ical file.
   *
   * @return int|null
   *   Returns the file id of the created/updated file.
   */
  private function saveManagedCalendarFile(ContentEntityBase $contentEntity,
                                           FieldDefinitionInterface $fieldConfig,
                                           $icsFileStr,
                                           $fileId = 0) {
    // Overwrite an existing managed file.
    return $fileId ? $this->updateFile($fileId, $icsFileStr) :
      $this->createNewFile($contentEntity, $fieldConfig, $icsFileStr);
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
                                 FieldDefinitionInterface $fieldConfig,
                                 string $icsFileStr) {

    // Create a new managed file, if there is no
    // existing one and give it a persistent
    // unique file name (i.e. entity's uuid).
    $uriScheme = $fieldConfig->getSetting('uri_scheme');
    $fileDirectory = $fieldConfig->getSetting('file_directory');
    $uploadLocation = $this->tokenService->replace($uriScheme . '://' .
                                                   $fileDirectory);
    if (!is_dir($uploadLocation)) {
      //Don't check anything about the return because it will fail on trying to create anyway if there is a problem
      file_prepare_directory($uploadLocation, FILE_CREATE_DIRECTORY);
    }
    if (file_prepare_directory($uploadLocation, FILE_MODIFY_PERMISSIONS)) {
      $fileName = md5($contentEntity->uuid() .
                      $fieldConfig->getConfig($fieldConfig->getTargetBundle())
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
   * @param string $fileUri
   *   Incoming file uri.
   */
  private function handleFileSaveError($fileUri) {
    $msg = 'Could not save calendar file: ' . $fileUri;
    drupal_set_message($msg, 'error');
    $this->logger->error($msg);
  }

  /**
   * @param string $uri
   *   Incoming file uri.
   */
  private function handleDirectoryError($uri) {
    $msg = 'Could not access calendar directory: ' . $uri;
    drupal_set_message($msg, 'error');
    $this->logger->error($msg);
  }

}
