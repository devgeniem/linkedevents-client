<?php
/**
 * Copyright (c) 2021. Geniem Oy
 */

namespace Geniem\LinkedEvents;

use Exception;
use Requests;
use stdClass;

/**
 * Class LinkedEventsClient
 *
 * @package Geniem\LinkedEvents
 */
class LinkedEventsClient {
    /**
     * API Base URI.
     *
     * @var string
     */
    private string $base_uri;

    /**
     * LinkedEvents API Client
     *
     * @param string $base_url API Base URL. Required.
     *
     * @throws \Exception Thrown if API Base URL is not set, or isn't valid URL.
     */
    public function __construct( string $base_url ) {
        $base_url = filter_var( $base_url, FILTER_SANITIZE_URL );
        if ( empty( $base_url ) || ! filter_var( $base_url, FILTER_VALIDATE_URL ) ) {
            throw new Exception( 'You need to provide a valid API base url' );
        }

        $this->base_uri = trim( rtrim( $base_url, '/ \t\n\r\0\x0B' ) ); // We'll set the last slash.
    }

    /**
     * Get Items from API.
     *
     * This has been limited to the first page of results. Use get_all() if you want _all_ results.
     *
     * @param string $endpoint   Endpoint to call. 'event' or 'event/1' to get specific event by ID.
     * @param array  $parameters Optional query parameters. Will be added to the end as ?key=value&key2=value2.
     *
     * @return false|\stdClass
     * @throws \Exception If Request or JSON Conversion failed.
     */
    public function get( string $endpoint, array $parameters = [] ) {
        $contents = $this->get_first_page( $endpoint, $parameters );

        return $contents->data ?? false;
    }

    /**
     * Get all paged results from the API.
     *
     * Please use only if you know you have checked your search parameters.
     *
     * @param string $endpoint   Endpoint to call. 'event' or 'event/1' to get specific event by ID.
     * @param array  $parameters Optional query parameters. Will be added to the end as ?key=value&key2=value2.
     *
     * @return array
     * @throws \Geniem\LinkedEvents\LinkedEventsException If HTTP Response was not 2XX.
     * @throws \JsonException If Response could not be converted to JSON.
     */
    public function get_all( string $endpoint, array $parameters = [] ) : array {
        $output   = [];
        $contents = $this->get_first_page( $endpoint, $parameters );
        $meta     = $contents->meta ?? new stdClass();
        $output   = array_merge( $output, $contents->data );

        if ( ! empty( $meta->next ?? '' ) ) {
            $next   = $meta->next ?? '';
            $output = array_merge( $output, $this->do_get_next_pages( $next ) );
        }

        return $output;
    }

    /**
     * Get first page from API.
     *
     * Use this if you want to build your own paged response fetching.
     * Contains data, and meta fields from the payload.
     *
     * @param string $endpoint   Endpoint URL.
     * @param array  $parameters Request parameters.
     *
     * @return false|\stdClass
     * @throws \Geniem\LinkedEvents\LinkedEventsException If API responded with anything other than 2XX.
     * @throws \JsonException If API response JSON decode fails.
     */
    public function get_first_page( string $endpoint, array $parameters = [] ) {
        $endpoint_url = sprintf(
            '/%s/?%s',
            $endpoint,
            self::to_query_parameters( $parameters )
        );
        $request_url  = $this->base_uri . trim( rtrim( $endpoint_url, '?' ) );
        $body         = $this->do_get_request( $request_url );

        return empty( $body )
            ? false
            : self::decode_contents( $body );
    }

    /**
     * Get request body from API by URL.
     *
     * @param string $api_url Api URL To Fetch from.
     *
     * @return string|false
     * @throws LinkedEventsException If API responded with anything other than 2XX.
     */
    public function do_get_request( string $api_url = '' ) {
        if ( empty( $api_url ) || ! filter_var( $api_url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        $payload     = Requests::get( $api_url );
        $status_code = $payload->status_code;

        if ( $status_code < 200 || $status_code >= 300 ) {
            throw new LinkedEventsException(
                sprintf( '%s: %s', $api_url, $payload->body ),
                $payload->status_code
            );
        }

        return $payload->body ?? '';
    }

    /**
     * Get next pages, if there are any.
     *
     * @param string $next Next page URL.
     *
     * @return array
     */
    private function do_get_next_pages( string $next ) : array {
        $data = [];
        while ( ! empty( $next ) ) {
            try {
                $page_body = $this->do_get_request( $next );
                $page_body = self::decode_contents( $page_body );

                $next = $page_body->meta->next ?? '';

                if ( isset( $page_body->data ) && ! empty( $page_body->data ) ) {
                    $data[] = $page_body->data;
                }
            }
            catch ( Exception $exception ) {
                self::log_exception( $exception );
                $next = '';
            }
        }

        // Flatten the array.
        $data = array_merge( ...$data );

        return $data;
    }

    /**
     * Decode the API Response.
     *
     * @param string $body Response body.
     *
     * @return \stdClass
     * @throws \JsonException If JSON Decode fails.
     */
    public static function decode_contents( string $body = '' ) : stdClass {
        $body = json_decode( $body, false, 512, JSON_THROW_ON_ERROR );

        // Multiple items, like from searching.
        if ( isset( $body->meta, $body->data ) ) {
            return $body;
        }

        // Single item, or error message.
        $output       = new stdClass();
        $output->data = $body;
        $output->meta = [];

        return $output;
    }

    /**
     * Generates query string compatible output from array.
     *
     * @param array|array[] $parameters Parameters to combine to query string format.
     *
     * @return string
     */
    public static function to_query_parameters( array $parameters = [] ) : string {
        if ( empty( $parameters ) ) {
            return '';
        }

        $list = array_map( static function ( $k, $v ) {
            if ( is_array( $v ) ) {
                $v = implode( ',', $v );
            }

            return sprintf( '%s=%s', $k, $v );
        }, array_keys( $parameters ), $parameters );

        return implode( '&', $list );
    }

    /**
     * Write our exception to error log.
     *
     * @param \Exception $exception Exception to log.
     */
    private static function log_exception( $exception ) : void {
        try {
            error_log( json_encode( [ // phpcs:ignore
                'message'  => $exception->getMessage(),
                'code'     => $exception->getCode(),
                'location' => $exception->getFile() . ':' . $exception->getLine(),
            ], JSON_THROW_ON_ERROR ) );
        }
        catch ( Exception $exception ) {
            // All is lost.
            error_log( 'Tried to json_encode error, but it failed.' ); // phpcs:ignore
        }
    }
}
