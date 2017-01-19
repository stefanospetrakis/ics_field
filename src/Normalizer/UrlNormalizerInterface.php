<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 19.01.17
 * Time: 01:15
 */

namespace Drupal\px_calendar_download\Normalizer;

/**
 * Interface UrlNormalizerInterface
 *
 * @package Drupal\px_calendar_download\Normalizer
 */
interface UrlNormalizerInterface {

  /**
   * Normalize a url from a request
   *
   * @param string $url
   * @param string $scheme
   * @param string $schemaAndHttpHost
   *
   * @return mixed
   */
  public function normalize($url, $scheme, $schemaAndHttpHost);

}