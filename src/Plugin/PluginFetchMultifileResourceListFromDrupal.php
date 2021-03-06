<?php
// src/Plugin/PluginFetchMultifileResourceListFromDrupal.php

namespace App\Plugin;

use Symfony\Component\Console\Output\ConsoleOutput;

class PluginFetchMultifileResourceListFromDrupal extends AbstractFetchResourceListPlugin {

  public function execute() {
    $output = new ConsoleOutput();

    if (isset($this->settings['drupal_baseurl'])) {
      $this->drupal_base_url = $this->settings['drupal_baseurl'];
    }
    else {
      $this->drupal_base_url = 'http://localhost:8000';
    }
    // An array, we need to loop through and add to guzzle request.
    if (isset($this->settings['jsonapi_authorization_headers'])) {
      $this->jsonapi_authorization_headers = $this->settings['jsonapi_authorization_headers'];
    }
    else {
      $this->jsonapi_authorization_headers = [];
    }
    if (isset($this->settings['drupal_media_auth'])) {
      $this->media_auth = $this->settings['drupal_media_auth'];
    }
    else {
      $this->media_auth = '';
    }
    // For now we only use the first one, not sure how to handle multiple content types.
    // See https://github.com/mjordan/riprap/issues/53.
    if (isset($this->settings['drupal_content_types'])) {
      $this->drupal_content_types = $this->settings['drupal_content_types'];
    }
    else {
      $this->drupal_content_types = [];
    }
    if (isset($this->settings['drupal_media_tags'])) {
      $this->media_tags = $this->settings['drupal_media_tags'];
    }
    else {
      $this->media_tags = [];
    }
    if (isset($this->settings['use_fedora_urls'])) {
      $this->use_fedora_urls = $this->settings['use_fedora_urls'];
    }
    else {
      $this->use_fedora_urls = TRUE;
    }
    if (isset($this->settings['gemini_endpoint'])) {
      $this->gemini_endpoint = $this->settings['gemini_endpoint'];
    }
    else {
      $this->gemini_endpoint = '';
    }
    if (isset($this->settings['gemini_auth_header'])) {
      $this->gemini_auth_header = $this->settings['gemini_auth_header'];
    }
    else {
      $this->gemini_auth_header = '';
    }

    if (isset($this->settings['jsonapi_page_size'])) {
      $this->page_size = $this->settings['jsonapi_page_size'];
    }
    else {
      // The maximum Drupal's JSON:API allows.
      $this->page_size = 50;
    }
    // Must be a multiple of $this->page-size (e.g. 0 remainder if you divide
    // $this->page_size into $this->max_resources).
    if (isset($this->settings['max_resources'])) {
      $this->max_resources = $this->settings['max_resources'];
    }
    else {
      // A single JSON:API page's worth.
      $this->max_resources = $this->page_size;
    }
    if ($this->max_resources % $this->page_size !== 0) {
      if ($this->logger) {
        $this->logger->error(
          "Configuration problem with PluginFetchMultifileResourceListFromDrupal: " .
          " max_resources is not a multiple of jsonapi_page_size",
          [
            'jsonapi_page_size' => $this->page_size,
            'max_resources' => $this->max_resources,
          ]
        );
      }
      $output->writeln("Configuration problem with PluginFetchMultifileResourceListFromDrupal. " .
        "Please see the log for more detail.");
      exit(1);
    }
    elseif ($this->max_resources / $this->page_size >= 1) {
      $this->num_jsonapi_pages_per_run = $this->max_resources / $this->page_size;
    }
    else {
      $this->num_jsonapi_pages_per_run = 1;
    }

    if (isset($this->settings['jsonapi_pager_data_file_path'])) {
      $this->page_data_file = $this->settings['jsonapi_pager_data_file_path'];
    }
    else {
      $this->page_data_file = '';
    }

    if (file_exists($this->page_data_file)) {
      $page_offset = (int) trim(file_get_contents($this->page_data_file));
    }
    else {
      $page_offset = 0;
      file_put_contents($this->page_data_file, $page_offset);
    }

    // Make an initial ping request to Drupal.
    $ping_url = $this->drupal_base_url . '/jsonapi/node/' . $this->drupal_content_types[0];
    $ping_client = new \GuzzleHttp\Client();
    $ping_response = $ping_client->request('GET', $ping_url, [
      'http_errors' => FALSE,
      'auth' => $this->settings['drupal_media_auth'],
      // Sort descending by 'changed' so new and updated nodes
      // get checked immediately after they are added/updated.
      'query' => ['sort' => '-changed'],
    ]);
    $ping_status_code = $ping_response->getStatusCode();

    if ($ping_status_code != 200) {
      if ($this->logger) {
        $this->logger->error(
          "PluginFetchMultifileResourceListFromDrupal request returned a non-200 response",
          [
            'HTTP response code' => $this->settings['drupal_media_auth'],
          ]
        );
      }
      $output->writeln("Ping request to Drupal returned a non-200 status code. Please .
                see the Riprap log for more detail.");
      exit(1);
    }

    $ping_node_list_from_jsonapi_json = (string) $ping_response->getBody();
    $ping_node_list_from_jsonapi = json_decode($ping_node_list_from_jsonapi_json, TRUE);
    // Adjust some variables bases on this ping.
    if (!isset($ping_node_list_from_jsonapi['links']['next']) &&
      !isset($ping_node_list_from_jsonapi['links']['prev'])) {
      // There is only one page of results.
      $this->num_jsonapi_pages_per_run = 1;
    }

    // Since JSON:API only provides a maximum of 50 items per page, we
    // make one request per page for as many pages as we need.
    $whole_node_list = ['data' => []];
    for ($p = 1; $p <= $this->num_jsonapi_pages_per_run; $p++) {
      $client = new \GuzzleHttp\Client();
      $url = $this->drupal_base_url . '/jsonapi/node/' . $this->drupal_content_types[0];
      $response = $client->request('GET', $url, [
        'http_errors' => FALSE,
        // @todo: Loop through this array and add each header.
        'auth' => $this->settings['drupal_media_auth'],
        'headers' => [
          'Accept' => 'application/vnd.api+json',
        ],
        // Sort descending by 'changed' so new and updated nodes
        // get checked immediately after they are added/updated.
        'query' => [
          'page[offset]' => $page_offset,
          'page[limit]' => $this->page_size,
          'sort' => '-changed',
        ],
      ]);

      $status_code = $response->getStatusCode();
      $node_list_from_jsonapi_json = (string) $response->getBody();
      $node_list_from_jsonapi = json_decode($node_list_from_jsonapi_json, TRUE);


      if ($status_code === 200) {
        $whole_node_list['data'] = array_merge($whole_node_list['data'], $node_list_from_jsonapi['data']);
        $this->setPageOffset($page_offset, $node_list_from_jsonapi['links']);
      }
    }

    if (count($whole_node_list['data']) == 0) {
      if ($this->logger) {
        $this->logger->info(
          "PluginFetchMultifileResourceListFromDrupal retrieved an empty node list from Drupal",
          [
            'HTTP response code' => $status_code,
          ]
        );
      }
      $output->writeln("PluginFetchMultifileResourceListFromDrupal retrieved an empty node list from Drupal");
      exit;
    }

    $output_resource_records = [];
    foreach ($whole_node_list['data'] as $node) {
      $nid = $node['attributes']['drupal_internal__nid'];
      // Get the media associated with this node using the Islandora-supplied Manage Media View.
      $media_client = new \GuzzleHttp\Client();
      $media_url = $this->drupal_base_url . '/node/' . $nid . '/media';
      $media_response = $media_client->request('GET', $media_url, [
        'http_errors' => FALSE,
        'auth' => $this->media_auth,
        'query' => ['_format' => 'json'],
      ]);
      $media_status_code = $media_response->getStatusCode();
      $media_list = (string) $media_response->getBody();
      $media_list = json_decode($media_list, TRUE);

      if (count($media_list) === 0) {
        if ($this->logger) {
          $this->logger->info(
            "PluginFetchMultifileResourceListFromDrupal is skipping node with an empty media list.",
            [
              'Node ID' => $nid,
            ]
          );
        }
        continue;
      }

      // Loop through all the media and pick the ones that are tagged with terms in $taxonomy_terms_to_check.
      foreach ($media_list as $media) {

        // Get the timestamp of the current revision.
        // Will be in ISO8601 format.
        $revised = $media['revision_created'][0]['value'];
        if (isset($media['field_media_image'])) {
          $target_file = $media['field_media_image'];
        }
        if (isset($media['field_media_file'])) {
          $target_file = $media['field_media_file'];
        }
        if (isset($media['field_media_video_file'])) {
          $target_file = $media['field_media_video_file'];
        }
        if ($this->use_fedora_urls) {
          // @todo: getFedoraUrl() returns false on failure, so build in logic here to log that
          // the resource ID / URL cannot be found. (But, http responses are already logged in
          // getFedoraUrl() so maybe we don't need to log here?)
          $fedora_url = $this->getFedoraUrl($target_file[0]['target_uuid']);
          if (strlen($fedora_url)) {
            $resource_record_object = new \stdClass;
            $resource_record_object->resource_id = $fedora_url;
            $resource_record_object->last_modified_timestamp = $revised;
            $output_resource_records[] = $resource_record_object;
          }

        }
        else {
          if (strlen($target_file[0]['url'])) {
            $resource_record_object = new \stdClass;
            $resource_record_object->resource_id = $media['field_media_image'][0]['url'];
            $resource_record_object->last_modified_timestamp = $revised;
            $output_resource_records[] = $resource_record_object;
          }
        }
      }
    }


    // $this->logger is null while testing.
    if ($this->logger) {
      $this->logger->info("PluginFetchMultifileResourceListFromDrupal executed");
    }

    return $output_resource_records;
  }

  /**
   * Get a Fedora URL for a File entity from Gemini.
   *
   * @param string $uuid
   *   The File entity's UUID.
   *
   * @return string
   *    The Fedora URL corresponding to the UUID, or false.
   */
  private function getFedoraUrl($uuid) {
    try {
      $client = new \GuzzleHttp\Client();
      $options = [
        'http_errors' => FALSE,
        'headers' => ['Authorization' => $this->gemini_auth_header],
      ];
      $url = $this->gemini_endpoint . '/' . $uuid;
      $response = $client->request('GET', $url, $options);
      $code = $response->getStatusCode();
      if ($code == 200) {
        $body = $response->getBody()->getContents();
        $body_array = json_decode($body, TRUE);
        return $body_array['fedora'];
      }
      elseif ($code == 404) {
        return FALSE;
      }
      else {
        if ($this->logger) {
          $this->logger->error(
            "PluginFetchMultifileResourceListFromDrupal could not get Fedora URL from Gemini",
            [
              'HTTP response code' => $code,
            ]
          );
        }
        return FALSE;
      }
    } catch (Exception $e) {
      if ($this->logger) {
        $this->logger->error(
          "PluginFetchMultifileResourceListFromDrupal could not get Fedora URL from Gemini",
          [
            'HTTP response code' => $code,
            'Exception message' => $e->getMessage(),
          ]
        );
      }
      return FALSE;
    }
  }

  /**
   * Sets the page offset to use in the next JSON:API request.
   *
   * @param int $page_offset
   *   The page offset used in the current JSON:API request.
   * @param string $links
   *   The 'links' array member from the JSON:API response.
   */
  private function setPageOffset($page_offset, $links) {
    // We are not on the last page, so increment the page offset counter.
    // See https://www.drupal.org/docs/8/modules/jsonapi/pagination for
    // info on the JSON API paging logic.
    // As of 8.x-2.x links are link objects. E.g. `$links['next']['href']`.
    if (array_key_exists('next', $links)) {
      $next_url = $links['next']['href'];
      $query_string = parse_url(urldecode($next_url), PHP_URL_QUERY);
      parse_str($query_string, $query_array);
      $next_offset = $query_array['page']['offset'];
      file_put_contents($this->page_data_file, trim($next_offset));
    }
    else {
      // We are on the last page, so reset the offset value to start the
      // verification cycle from the beginning.
      if (array_key_exists('first', $links)) {
        $first_url = $links['first']['href'];
        $query_string = parse_url(urldecode($first_url), PHP_URL_QUERY);
        parse_str($query_string, $query_array);
        $first_offset = $query_array['page']['offset'];
        file_put_contents($this->page_data_file, trim($first_offset));

        if ($this->logger) {
          $this->logger->info(
            "PluginFetchMultifileResourceListFromDrupal has reset Drupal's JSON:API page offset to the first page.",
            [
              'Pager self URL' => $links['self']['href'],
            ]
          );
        }
      }
    }
  }
}
