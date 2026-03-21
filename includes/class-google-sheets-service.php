<?php
/**
 * Google Sheets Service for Content Tracker Sync.
 *
 * Self-contained Google Sheets API v4 client using JWT (Service Account) authentication.
 * Features 3-tier smart column matching and true append-at-end row placement.
 * No external Composer dependencies required — uses WordPress HTTP API.
 *
 * @package ContentTrackerSync
 * @since   3.0.0
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
     * Cached column map (data_key => column_index).
     *
     * @var array|null
     */
    private $column_map = null;

    /*--------------------------------------------------------------
     * 3-Tier Smart Column Matching — Header Aliases
     *
     * Tier 1: Exact lowercase match against these aliases.
     * Tier 2: "Contains" match using the first alias group entry.
     * Order matters: more specific keys (keywords_with_tags) must
     *   come BEFORE less specific ones (keywords) to avoid false matches.
     *------------------------------------------------------------*/

    /**
     * Header aliases for smart column matching.
     * Keys are internal data keys; values are arrays of known header names (lowercase).
     *
     * @var array
     */
    private static $header_aliases = array(
        'post_id' => array(
            'post id', 'postid', 'post_id', 'id', 'wp id', 'wordpress id',
            'column 1', 'sr no', 'serial', '#', 'no', 'number', 'post number',
        ),
        'title' => array(
            'title', 'topic', 'topic (title)', 'post title', 'headline',
            'article title', 'name', 'magazine category', 'article',
            'blog title', 'content title', 'heading', 'subject',
            'page title', 'entry title', 'content name',
        ),
        'slug' => array(
            'slug', 'post slug', 'url slug', 'permalink', 'post_slug',
            'url', 'link', 'post url', 'page url', 'post link',
        ),
        'seo_title' => array(
            'seo title', 'seo heading', 'search title', 'og title',
            'yoast title', 'rank math title', 'seo_title', 'meta title',
            'search engine title', 'browser title',
        ),
        'keywords_with_tags' => array(
            'keywords with tags', 'keywords with tag', 'hashtags', 'hash tags',
            'keyword tags', 'tagged keywords', 'tags with hash',
            'keywords_with_tags', '#tags', 'hashtagged',
        ),
        'keywords' => array(
            'keywords', 'keyword', 'tags', 'post tags', 'tag',
            'categories', 'topics', 'labels', 'terms',
        ),
        'meta_description' => array(
            'meta description', 'meta_description', 'description',
            'seo description', 'meta desc', 'metadescription',
            'meta', 'excerpt', 'summary', 'snippet', 'page description',
        ),
        'focus_keyphrase' => array(
            'focus keyphrase', 'focus keyword', 'focus_keyphrase',
            'focus_keyword', 'keyphrase', 'focus key', 'primary keyword',
            'main keyword', 'target keyword', 'seo keyword', 'seo keyphrase',
        ),
        'content_link' => array(
            'content link', 'content_link', 'content url', 'doc link',
            'doc url', 'google doc', 'google doc link', 'article link',
            'article url', 'document link', 'document url', 'content for website',
            'content', 'document', 'gdoc', 'gdoc link',
        ),
    );

    /**
     * Tier 2 "contains" keywords for each data key.
     * Checked in order — more specific patterns first.
     * Each entry: array( 'contains_term', 'data_key', optional 'must_not_contain' ).
     *
     * @var array
     */
    private static $contains_rules = array(
        array( 'post id',      'post_id' ),
        array( 'post_id',      'post_id' ),
        array( 'seo title',    'seo_title' ),
        array( 'seo heading',  'seo_title' ),
        array( 'doc link',     'content_link' ),
        array( 'doc url',      'content_link' ),
        array( 'gdoc',         'content_link' ),
        array( 'content link', 'content_link' ),
        array( 'article link', 'content_link' ),
        array( 'hashtag',      'keywords_with_tags' ),
        array( 'with tags',    'keywords_with_tags' ),
        array( 'with tag',     'keywords_with_tags' ),
        array( 'keyword',      'keywords' ),
        array( 'tag',          'keywords' ),
        array( 'title',        'title' ),
        array( 'topic',        'title' ),
        array( 'heading',      'title' ),
        array( 'slug',         'slug' ),
        array( 'permalink',    'slug' ),
        array( 'meta desc',    'meta_description' ),
        array( 'description',  'meta_description' ),
        array( 'keyphrase',    'focus_keyphrase' ),
        array( 'focus key',    'focus_keyphrase' ),
        array( 'focus word',   'focus_keyphrase' ),
    );

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
     * Smart Column Mapping
     *------------------------------------------------------------*/

    /**
     * Read Row 1 headers and build a mapping of data keys to column indices.
     * Uses 3-tier matching: exact → contains → skip.
     *
     * @return array|WP_Error Map of data_key => column_index, or error.
     */
    public function get_column_map() {

        if ( null !== $this->column_map ) {
            return $this->column_map;
        }

        $range   = $this->build_range( '1:1' );
        $headers = $this->get_values( $range );

        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        if ( empty( $headers ) || empty( $headers[0] ) ) {
            return new WP_Error(
                'cts_no_headers',
                'Could not read the header row (Row 1) from your spreadsheet. Make sure your sheet has column headers in Row 1.'
            );
        }

        $header_row = $headers[0];
        $map        = array();
        $matched    = array(); // Track which data_keys are already matched.

        // ---- Tier 1: Exact match (case-insensitive) ----
        foreach ( $header_row as $col_index => $header_value ) {
            $normalized = strtolower( trim( $header_value ) );

            if ( '' === $normalized ) {
                continue;
            }

            foreach ( self::$header_aliases as $data_key => $aliases ) {
                if ( isset( $matched[ $data_key ] ) ) {
                    continue;
                }

                foreach ( $aliases as $alias ) {
                    if ( $normalized === $alias ) {
                        $map[ $data_key ]     = $col_index;
                        $matched[ $data_key ] = true;
                        break 2;
                    }
                }
            }
        }

        // ---- Tier 2: Contains match (for headers not matched in Tier 1) ----
        foreach ( $header_row as $col_index => $header_value ) {
            // Skip if this column was already matched.
            if ( in_array( $col_index, $map, true ) ) {
                continue;
            }

            $normalized = strtolower( trim( $header_value ) );

            if ( '' === $normalized ) {
                continue;
            }

            foreach ( self::$contains_rules as $rule ) {
                $contains_term = $rule[0];
                $data_key      = $rule[1];

                if ( isset( $matched[ $data_key ] ) ) {
                    continue;
                }

                if ( false !== strpos( $normalized, $contains_term ) ) {
                    $map[ $data_key ]     = $col_index;
                    $matched[ $data_key ] = true;
                    break;
                }
            }
        }

        $this->column_map = $map;

        // Debug logging.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CTS] Header row: ' . wp_json_encode( $header_row ) );
            error_log( '[CTS] Column map: ' . wp_json_encode( $map ) );
        }

        return $map;
    }

    /**
     * Convert a key-value data array into a positional row array
     * using the column map from the header row.
     *
     * Unmapped columns get null (they will not be written to).
     *
     * @param  array $data Associative array of data_key => value.
     * @return array|WP_Error Positional row array or error.
     */
    public function map_data_to_row( array $data ) {

        $map = $this->get_column_map();

        if ( is_wp_error( $map ) ) {
            return $map;
        }

        if ( empty( $map ) ) {
            return new WP_Error(
                'cts_no_matching_columns',
                'No matching columns found in your spreadsheet. Make sure Row 1 has headers like "Post ID", "Title", "Slug", "Keywords", "Meta Description", etc.'
            );
        }

        // Find the max column index to determine row length.
        $max_col = max( array_values( $map ) );
        $row     = array_fill( 0, $max_col + 1, null );

        // Place each data value in its mapped column position.
        foreach ( $data as $key => $value ) {
            if ( isset( $map[ $key ] ) ) {
                $row[ $map[ $key ] ] = $value;
            }
        }

        return $row;
    }

    /*--------------------------------------------------------------
     * Public API
     *------------------------------------------------------------*/

    /**
     * Find the row number of an existing Post ID.
     * Smart: finds whichever column contains Post IDs based on headers.
     *
     * @param  int $post_id The WordPress Post ID.
     * @return int|false|WP_Error Row number (1-indexed), false if not found, or WP_Error.
     */
    public function find_row_by_post_id( int $post_id ) {

        $map = $this->get_column_map();

        // Determine which column has Post IDs.
        $id_col_index = 0; // Default to column A.
        if ( ! is_wp_error( $map ) && isset( $map['post_id'] ) ) {
            $id_col_index = $map['post_id'];
        }

        // Convert index to letter (0=A, 1=B, ... 25=Z).
        $col_letter = chr( 65 + min( $id_col_index, 25 ) );
        $range      = $this->build_range( $col_letter . ':' . $col_letter );
        $values     = $this->get_values( $range );

        // If the API returned an error, propagate it.
        if ( is_wp_error( $values ) ) {
            return $values;
        }

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
     * Find the true last row with any data across ALL columns.
     * This ensures new data is always appended at the very end.
     *
     * @return int Last row number with data (0 if sheet is empty or has only headers).
     */
    public function find_last_data_row() {

        // Read the entire sheet to find the true last row.
        $range  = $this->build_range( 'A:ZZ' );
        $values = $this->get_values( $range );

        if ( is_wp_error( $values ) || empty( $values ) ) {
            return 0;
        }

        // The Sheets API only returns rows up to the last row with data.
        // So count($values) gives us the last row number.
        return count( $values );
    }

    /**
     * Append a new row of data at the TRUE end of the sheet.
     * Uses smart column mapping — only writes to plugin-managed columns.
     *
     * @param  array $row_data Positional row array (may contain nulls for unmanaged columns).
     * @return array|WP_Error API response or error.
     */
    public function append_row( array $row_data ) {

        // Find the actual last row with data across all columns.
        $last_row   = $this->find_last_data_row();
        $target_row = $last_row + 1; // Write to the next empty row.

        // Use smart cell-by-cell update to avoid overwriting unmanaged columns.
        return $this->write_row_smart( $target_row, $row_data );
    }

    /**
     * Update an existing row using smart column mapping.
     * Only updates cells that have data (leaves other columns untouched).
     *
     * @param  int   $row_number 1-indexed row number.
     * @param  array $row_data   Positional row array (may contain nulls for unmanaged columns).
     * @return array|WP_Error    API response or error.
     */
    public function update_row( int $row_number, array $row_data ) {
        return $this->write_row_smart( $row_number, $row_data );
    }

    /**
     * Write data to a specific row, cell by cell.
     * Only writes to cells that have a non-null value.
     * This ensures columns the plugin doesn't manage are never touched.
     *
     * @param  int   $row_number 1-indexed row number.
     * @param  array $row_data   Positional row array (nulls = skip).
     * @return array|WP_Error    API response or error.
     */
    private function write_row_smart( int $row_number, array $row_data ) {

        $requests = array();

        foreach ( $row_data as $col_index => $value ) {
            if ( null === $value ) {
                continue; // Skip columns we don't manage.
            }

            $col_letter = chr( 65 + $col_index );
            $cell_range = $this->build_range( $col_letter . $row_number );

            $requests[] = array(
                'range'  => $cell_range,
                'values' => array( array( $value ) ),
            );
        }

        if ( empty( $requests ) ) {
            return new WP_Error( 'cts_no_data', 'No data to write.' );
        }

        $url = self::SHEETS_API_BASE . '/' . $this->spreadsheet_id
             . '/values:batchUpdate';

        $body = wp_json_encode( array(
            'valueInputOption' => 'USER_ENTERED',
            'data'             => $requests,
        ) );

        return $this->api_request( $url, 'POST', $body );
    }

    /*--------------------------------------------------------------
     * Read values
     *------------------------------------------------------------*/

    /**
     * Get values from a range.
     *
     * @param  string $range A1-style range (e.g. "Sheet1!A:A").
     * @return array|WP_Error 2D array of cell values or WP_Error.
     */
    public function get_values( string $range ) {

        $url = self::SHEETS_API_BASE . '/' . $this->spreadsheet_id
             . '/values/' . rawurlencode( $range );

        $response = $this->api_request( $url, 'GET' );

        // Propagate errors so callers (test connection, find_row) can detect failures.
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['values'] ) ) {
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
