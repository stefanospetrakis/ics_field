<?php

namespace Drupal\px_calendar_download\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\file\Entity\File;
use Drupal\px_calendar_download\CalendarDownloadUtil;
use Drupal\px_calendar_download\CalendarDownloadInvalidParametersException;

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
class CalendarDownloadDefaultWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_definitions = $this->getEntityFieldDefinitions();
    $element['vsummary'] = [
      '#type' => 'textfield',
      '#placeholder' => t('Summary'),
      '#title' => t('Summary'),
      '#default_value' => isset($items[$delta]->vsummary) ? $items[$delta]->vsummary : NULL,
    ];
    $element['vdescription'] = [
      '#type' => 'textarea',
      '#placeholder' => t('Description'),
      '#title' => t('Description'),
      '#default_value' => isset($items[$delta]->vdescription) ? $items[$delta]->vdescription : NULL,
    ];
    $element['vurl'] = [
      '#type' => 'textfield',
      '#placeholder' => t('URL'),
      '#title' => t('URL'),
      '#default_value' => isset($items[$delta]->vurl) ? $items[$delta]->vurl : NULL,
    ];
    $token_tree = [];
    foreach ($field_definitions as $field_name => $field_definition) {
      $token_tree['[node:' . $field_name . ']'] = [
        'name' => $field_definition->getLabel(),
        'tokens' => [],
      ];
    }
    $element['vtokens'] = [
      '#type' => 'details',
      '#title' => t('Tokens'),
      'tokenlist' => [
        '#type' => 'token_tree_table',
        '#columns' => ['token', 'name'],
        '#token_tree' => $token_tree,
      ],
    ];

    // If cardinality is 1, ensure a label is output for the field by wrapping
    // it in a details element.
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
      $element += ['#type' => 'fieldset'];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * @brief: Create/Update the referenced file.
   */
  public function massageFormValues(array $values,
                                    array $form,
                                    FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    if ($entity->getEntityTypeId() === 'node') {
      $field_definitions = $this->getEntityFieldDefinitions();
      foreach ($form_state->getValues() as $key => $value) {
        if (isset($field_definitions[$key])) {
          try {
            $entity->set($key, $value);
          }
          catch (\InvalidArgumentException $iae) {
            \Drupal::logger('px_calendar_download')->error($iae->getMessage());
          }
        }
      }
      foreach ($values as $key => &$value) {
        $this->updateManagedCalFile($value, $entity);
      }
    }
    return $values;

  }

  /**
   * Generate and save an .ics file.
   */
  private function updateManagedCalFile(array &$value, $entity) {
    $cal_params = [];
    // Set default timezone
    // Note: Use the following if we want to use the site's timezone.
    // $cal_params['timezone'] = \Drupal::config('system.date')->get('timezone.default');
    $cal_params['timezone'] = drupal_get_user_timezone();
    // Use the hostname to set the PRODID field
    $cal_params['prodid'] = \Drupal::request()->getHost();
    // Uses token replacement to interpolate tokens in the field's fields that support them.
    $cal_params['summary'] = \Drupal::token()->replace($value['vsummary'], [$entity->getEntityTypeId() => $entity]);
    $cal_params['url'] = \Drupal::token()->replace($value['vurl'], [$entity->getEntityTypeId() => $entity]);
    $cal_params['description'] = \Drupal::token()->replace($value['vdescription'], [$entity->getEntityTypeId() => $entity]);
    $cal_params['uuid'] = $entity->uuid();

    $cal_params['dates'] = [];
    $vdate_field = $this->fieldDefinition->getSetting('vdate_field');
    if (!empty($vdate_field)) {
      foreach ($entity->$vdate_field->getValue() as $date_val) {
        if (!$date_val['value'] instanceof DrupalDateTime) {
          continue;
        }
        $cal_params['dates'][] = $date_val['value']->render();
      }
      try {
        $ics_file_str = CalendarDownloadUtil::generateCalFileAsString($cal_params);
        // Create a new managed file, if there is no
        // existing one and give it a persistent
        // unique file name (i.e. entity's uuid).
        if (empty($value['vfileref'])) {
          $uri_scheme = $this->fieldDefinition->getSetting('uri_scheme');
          $file_directory = $this->fieldDefinition->getSetting('file_directory');
          $token_service = \Drupal::service('token');
          $upload_location = $token_service->replace($uri_scheme . '://' . $file_directory);
          if (file_prepare_directory($upload_location, FILE_CREATE_DIRECTORY)) {
            $file_name = $entity->uuid() . "_event.ics";
            $file = file_save_data($ics_file_str, $upload_location . '/' . $file_name, FILE_EXISTS_REPLACE);
            $value['vfileref'] = $file->id();
          }
        }
        // Overwrite an existing managed file.
        else {
          $file = File::load($value['vfileref']);
          $file_uri = $file->getFileUri();
          file_save_data($ics_file_str, $file_uri, FILE_EXISTS_REPLACE);
        }
      }
      catch (CalendarDownloadInvalidParametersException $e) {
        // Do something useful with this specific exception.
      }
      catch (Exception $e) {
        // Do something useful with this general exception.
      }
      return;
    }
  }

  /**
   * Get the fields and properties attached to this entity.
   */
  private function getEntityFieldDefinitions() {
    $att_bundle = $this->fieldDefinition->get('bundle');
    $att_entity_type = $this->fieldDefinition->get('entity_type');
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $field_definitions = [];
    $field_definitions = array_filter(
      $entityFieldManager->getBaseFieldDefinitions($att_entity_type), function ($field_definition) {
        return $field_definition instanceof FieldDefinitionInterface;
      }
    ) + array_filter(
      $entityFieldManager->getFieldDefinitions($att_entity_type, $att_bundle), function ($field_definition) {
        return $field_definition instanceof FieldDefinitionInterface;
      }
    );
    // Do not include ourselves in the list of fields that we'll use
    // for token replacement.
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_name == $this->fieldDefinition->get('field_name')) {
        unset($field_definitions[$field_name]);
        break;
      }
    }
    return $field_definitions;
  }

}
