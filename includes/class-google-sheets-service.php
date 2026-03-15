<?php
/**
 * Google Sheets Service for Content Tracker Sync.
 *
 * Self-contained Google Sheets API v4 client using JWT (Service Account) authentication.
 * No external Composer dependencies required — uses WordPress HTTP API.
 *
 * @package ContentTrackerSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CTS_Google_Sheets_Service
 */
class CTS_Google_Sheets_Service {

    /**
     * Google OAuth2 token endpoint.
     *
     * @var string
     */
    const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * Google Sheets API base URL.
     *
     * @var string
     */
    const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * Decoded service account credentials.
     *
     * @var array
     */
    private $credentials;

    /**
     * Target spreadsheet ID.
     *
     * @var string
     */
    private $spreadsheet_id;

    /**
     * Target sheet (tab) name.
     *
     * @var string
     */
    private $sheet_name;

    /**
     * Cached access token.
     *
     * @var string|null
     */
    private $access_token = null;

    /**
     * Constructor.
     *
     * @param array  $credentials    Decoded service account JSON.
     * @param string $spreadsheet_id Spreadsheet ID.
     * @param string $sheet_name     Sheet tab name.
     */
    public function __construct( array $credentials, string $spreadsheet_id, string $sheet_name ) {
        $this->credentials    = $credentials;
        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_name     = $sheet_name;
    }

    /*--------------------------------------------------------------
     * Public API
     *------------------------------------------------------------*/

    /**
     * Find the row number of an existing Post ID in column A.
     *
     * @param  int $post_id The WordPress Post ID.
     * @return int|false Row number (1-indexed) or false if not found.
     */
    public function find_row_by_post_id( int $post_id ) {

        $range  = $this->build_range( 'A:A' );
        $values = $this->get_values( $range );

        if ( empty( $values ) ) {
            return false;
        }

        foreach ( $values as $index => $row ) {
            if ( isset( $row[0] ) && (int) $row[0] === $post_id ) {
                return $index + 1; // Sheets rows are 1-indexed.
            }
        }

        return false;
    }

    /**
     * Append a new row of data.
     *
     * @param  array $row_data Flat array of cell values.
     * @return array|WP_Error API response or error.
     */
    public function append_row( array $row_data ) {

        $range = $this->build_range( 'A1' );
        $url   = self::SHEETS_API_BASE . '/' . $this->spreadsheet_id
               . '/values/' . rawurlencode( $range ) . ':append'
               . '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $body = wp_json_encode( array(
            'values' => array( $row_data ),
        ) );

        return $this->api_request( $url, 'POST', $body );
    }

    /**
     * Update an existing row.
     *
     * @param  int   $row_number 1-indexed row number.
     * @param  array $row_data   Flat array of cell values.
     * @return array|WP_Error    API response or error.
     */
    public function update_row( int $row_number, array $row_data ) {

        $range = $this->build_range( 'A' . $row_number );
        $url   = self::SHEETS_API_BASE . '/' . $this->spreadsheet_id
               . '/values/' . rawurlencode( $range )
               . '?valueInputOption=USER_ENTERED';

        $body = wp_json_encode( array(
            'values' => array( $row_data ),
        ) );

        return $this->api_request( $url, 'PUT', $body );
    }

    /*--------------------------------------------------------------
     * Read values
     *------------------------------------------------------------*/

    /**
     * Get values from a range.
     *
     * @param  string $range A1-style range (e.g. "Sheet1!A:A").
     * @return array  2D array of cell values.
     */
    public function get_values( string $range ) {

        $url = self::SHEETS_API_BASE . '/' . $this->spreadsheet_id
             . '/values/' . rawurlencode( $range );

        $response = $this->api_request( $url, 'GET' );

        if ( is_wp_error( $response ) || empty( $response['values'] ) ) {
            return array();
        }

        return $response['values'];
    }

    /*--------------------------------------------------------------
     * HTTP / Auth helpers
     *------------------------------------------------------------*/

    /**
     * Make an authenticated request to the Sheets API.
     *
     * @param  string      $url    Full API URL.
     * @param  string      $method HTTP method.
     * @param  string|null $body   JSON body (for POST/PUT).
     * @return array|WP_Error Decoded response body or WP_Error.
     */
    private function api_request( string $url, string $method = 'GET', ?string $body = null ) {

        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
        );

        if ( $body ) {
            $args['body'] = $body;
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code  = wp_remote_retrieve_response_code( $response );
        $json  = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $api_msg = isset( $json['error']['message'] ) ? $json['error']['message'] : '';

            // Translate API codes into actionable user messages.
            if ( 403 === $code ) {
                $message = 'Permission denied. Share your Google Sheet with the service account email as Editor. (API: ' . $api_msg . ')';
            } elseif ( 404 === $code ) {
                $message = 'Spreadsheet not found. Check your Spreadsheet ID in Settings → Content Tracker Sync. (API: ' . $api_msg . ')';
            } elseif ( 400 === $code && stripos( $api_msg, 'parse' ) !== false ) {
                $message = 'Unable to find the sheet tab. Make sure the Sheet Name in plugin settings exactly matches your Google Sheet tab name (case-sensitive). (API: ' . $api_msg . ')';
            } elseif ( 401 === $code ) {
                $message = 'Authentication failed. Your credentials may have expired. Download a new JSON key from Google Cloud and update Settings. (API: ' . $api_msg . ')';
            } else {
                $message = $api_msg ? $api_msg : 'Unknown API error (HTTP ' . $code . '). Check Settings → Content Tracker Sync.';
            }

            return new WP_Error( 'cts_sheets_api_error', $message, array( 'status' => $code ) );
        }

        return $json ? $json : array();
    }

    /**
     * Obtain an OAuth2 access token via JWT grant.
     *
     * Token is cached in a transient for reuse within its lifetime.
     *
     * @return string|WP_Error Access token string or error.
     */
    private function get_access_token() {

        if ( $this->access_token ) {
            return $this->access_token;
        }

        // Transient key is unique per spreadsheet to avoid cross-site/cross-sheet token collisions.
        $transient_key = 'cts_token_' . substr( md5( $this->spreadsheet_id ), 0, 12 );

        // Check transient cache first.
        $cached = get_transient( $transient_key );
        if ( $cached ) {
            $this->access_token = $cached;
            return $cached;
        }

        $jwt = $this->create_jwt();

        if ( is_wp_error( $jwt ) ) {
            return $jwt;
        }

        $response = wp_remote_post( self::TOKEN_URI, array(
            'timeout' => 15,
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Token exchange failed';
            return new WP_Error( 'cts_token_error', $msg );
        }

        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3540;

        set_transient( $transient_key, $body['access_token'], $expires_in );

        $this->access_token = $body['access_token'];

        return $this->access_token;
    }

    /**
     * Create a signed JWT for the Google OAuth2 token endpoint.
     *
     * @return string|WP_Error Encoded JWT string or error.
     */
    private function create_jwt() {

        if ( empty( $this->credentials['client_email'] ) || empty( $this->credentials['private_key'] ) ) {
            return new WP_Error( 'cts_credentials_error', 'Service account credentials are incomplete.' );
        }

        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        );

        $now = time();

        $claim = array(
            'iss'   => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => self::TOKEN_URI,
            'iat'   => $now,
            'exp'   => $now + 3600,
        );

        $segments = array(
            $this->base64url_encode( wp_json_encode( $header ) ),
            $this->base64url_encode( wp_json_encode( $claim ) ),
        );

        $signing_input = implode( '.', $segments );

        $private_key = openssl_pkey_get_private( $this->credentials['private_key'] );

        if ( ! $private_key ) {
            return new WP_Error( 'cts_key_error', 'Unable to parse private key from credentials.' );
        }

        $signature = '';
        $success   = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

        if ( ! $success ) {
            return new WP_Error( 'cts_sign_error', 'JWT signing failed.' );
        }

        $segments[] = $this->base64url_encode( $signature );

        return implode( '.', $segments );
    }

    /**
     * Base64-url-encode a string.
     *
     * @param  string $data Raw data.
     * @return string URL-safe Base64.
     */
    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Build an A1 range, quoting the sheet name only when it contains
     * spaces or special characters (Google Sheets API requirement).
     *
     * @param  string $a1 A1 row/column part without sheet name.
     * @return string Full A1 range.
     */
    private function build_range( string $a1 ): string {
        $name = $this->sheet_name;

        // Quote only when the name has spaces, quotes, or non-alphanumeric chars.
        if ( preg_match( '/[^A-Za-z0-9_]/', $name ) ) {
            $name = "'" . str_replace( "'", "''", $name ) . "'";
        }

        return $name . '!' . $a1;
    }
}
