<?php

namespace Drupal\px_calendar_download\Plugin\Field\FieldFormatter;

use Drupal\file\Entity\File;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'calendar_download_default_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "calendar_download_default_formatter",
 *   label = @Translation("Calendar download default formatter"),
 *   field_types = {
 *     "calendar_download_type"
 *   }
 * )
 */
class CalendarDownloadDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      // Implement default settings.
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
      // Implement settings form.
    ] + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    // Implement settings summary.

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = $this->viewValue($item);
    }

    return $elements;
  }

  /**
   * Generate the output appropriate for one field item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   One field item.
   *
   * @return string
   *   A render array for a link element.
   */
  protected function viewValue(FieldItemInterface $item) {
    $vfileref = $item->get('vfileref')->getValue();
    $file = File::load($vfileref);
    if ($file) {
      $file_url_obj = Url::fromUri(file_create_url($file->getFileUri()));
      $build = [
        '#type' => 'link',
        '#title' => $this->t('iCal Download'),
        '#url' => $file_url_obj,
      ];
      return $build;
    }
    return "";
  }

}
