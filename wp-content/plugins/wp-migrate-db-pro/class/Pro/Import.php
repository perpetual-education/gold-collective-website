<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\Common\BackupExport;
use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\WPMDBRestAPIServer;
use DeliciousBrains\WPMDB\Common\MigrationPersistence\Persistence;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Sql\Table;
use DeliciousBrains\WPMDB\Common\Util\Util;

class Import
{

    /**
     * @var Http
     */
    private $http;
    /**
     * @var Properties
     */
    private $props;
    /**
     * @var Filesystem
     */
    private $filesystem;
    /**
     * @var
     */
    protected $template;
    /**
     * @var ErrorLog
     */
    private $error_log;
    /**
     * @var MigrationStateManager
     */
    private $migration_state_manager;
    /**
     * @var FormData
     */
    private $form_data;
    /**
     * @var BackupExport
     */
    private $backup_export;
    /**
     * @var Table
     */
    private $table;
    /**
     * @var WPMDBRestAPIServer
     */
    private $rest_API_server;
    /**
     * @var Helper
     */
    private $http_helper;

    /**
     * Import constructor.
     *
     * @param Http                  $http
     * @param MigrationStateManager $migration_state_manager
     * @param ErrorLog              $error_log
     * @param Filesystem            $filesystem
     * @param BackupExport          $backup_export
     * @param Table                 $table
     * @param FormData              $form_data
     * @param Properties            $properties
     * @param WPMDBRestAPIServer    $rest_API_server
     */
    public function __construct(
        Http $http,
        MigrationStateManager $migration_state_manager,
        ErrorLog $error_log,
        Filesystem $filesystem,
        BackupExport $backup_export,
        Table $table,
        FormData $form_data,
        Properties $properties,
        WPMDBRestAPIServer $rest_API_server,
        Helper $http_helper
    ) {
        $this->http                    = $http;
        $this->error_log               = $error_log;
        $this->migration_state_manager = $migration_state_manager;
        $this->form_data               = $form_data;
        $this->filesystem              = $filesystem;
        $this->table                   = $table;
        $this->backup_export           = $backup_export;
        $this->props                   = $properties;
        $this->rest_API_server         = $rest_API_server;
        $this->http_helper             = $http_helper;
    }

    /**
     * Stores the chunk size used for imports
     *
     * @var int $chunk_size
     */
    protected $chunk_size = 10000;

    /**
     * State data for the migration
     *
     * @var array $state_data
     */
    protected $state_data;

    /**
     *
     */
    public function register()
    {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_filter('wpmdb_preserved_options', array($this, 'filter_preserved_options'), 10, 2);
        add_filter('wpmdb_preserved_options_data', array($this, 'filter_preserved_options_data'), 10, 2);
    }

    public function register_rest_routes()
    {
        $this->rest_API_server->registerRestRoute(
            '/get-import-info',
            [
                'methods'  => 'POST',
                'callback' => [$this, 'ajax_get_import_info'],
            ]
        );

        $this->rest_API_server->registerRestRoute(
            '/upload-file',
            [
                'methods'  => 'POST',
                'callback' => [$this, 'ajax_upload_file'],
            ]
        );

        $this->rest_API_server->registerRestRoute(
            '/prepare-upload',
            [
                'methods'  => 'POST',
                'callback' => [$this, 'ajax_prepare_import_file'],
            ]
        );

        $this->rest_API_server->registerRestRoute(
            '/import-file',
            [
                'methods'  => 'POST',
                'callback' => [$this, 'ajax_import_file'],
            ]
        );
    }


    /**
     * Returns info about the import file.
     *
     * @return array|bool
     */
    public function ajax_get_import_info()
    {
        $_POST = $this->http_helper->convert_json_body_to_post();

        $data       = $this->decode_chunk($_POST['file_data']);
        $is_gzipped = false;

        if (false !== $data && $this->str_is_gzipped($data)) {
            if (!Util::gzip()) {
                $error_msg = __('The server is not compatible with gzip, please decompress the import file and try again.', 'wp-migrate-db');
                $return    = array('wpmdb_error' => 1, 'body' => $error_msg);
                $this->error_log->log_error($error_msg);

                return $this->http->end_ajax(json_encode($return));
            }

            $data       = Util::gzdecode($data);
            $is_gzipped = true;
        }

        if (!$data && !$is_gzipped) {
            $error_msg = __('Unable to read data from the import file', 'wp-migrate-db');
            $return    = array('wpmdb_error' => 1, 'body' => $error_msg);
            $this->error_log->log_error($error_msg);
            $result = $this->http->end_ajax(json_encode($return));

            return $result;
        }

        $return                   = $this->parse_file_header($data);
        $return['import_gzipped'] = $is_gzipped;

        return $this->http->end_ajax($return);
    }

    /**
     * Parses info from the export file header.
     *
     * @param $data
     *
     * @return array
     */
    public function parse_file_header($data)
    {
        $lines  = preg_split('/\n|\r\n?/', $data);
        $return = array();

        if (is_array($lines) && 10 <= count($lines)) {
            if ('# URL:' === substr($lines[5], 0, 6)) {
                $return['URL'] = substr($lines[5], 7);
            }

            if ('# Path:' === substr($lines[6], 0, 7)) {
                $return['path'] = substr($lines[6], 8);
            }

            if ('# Tables:' === substr($lines[7], 0, 9)) {
                $return['tables'] = explode(', ', substr($lines[7], 10));
            }

            if ('# Table Prefix:' === substr($lines[8], 0, 15)) {
                $return['prefix'] = substr($lines[8], 16);
            }

            if ('# Post Types:' === substr($lines[9], 0, 13)) {
                $return['post_types'] = explode(', ', substr($lines[9], 14));
            }

            if ('# Protocol:' === substr($lines[10], 0, 11)) {
                $return['protocol'] = substr($lines[10], 12);
            }

            if ('# Multisite:' === substr($lines[11], 0, 12)) {
                $return['multisite'] = substr($lines[11], 13);
            }

            if ('# Subsite Export:' === substr($lines[12], 0, 17)) {
                $return['subsite_export'] = substr($lines[12], 18);
            }
        }

        return $return;
    }

    /**
     * Uploads the import file to the server.
     *
     * @return null
     */
    public function ajax_upload_file()
    {
        $_POST = $this->http_helper->convert_json_body_to_post();

        $key_rules = [
            'form_data'   => 'json',
            'file_data'   => 'string',
            'import_path' => 'string',
        ];

        $this->state_data = Persistence::setPostData($key_rules, __METHOD__);
        $this->form_data->parse_and_save_migration_form_data($this->state_data['form_data']);

        $file_data = $this->decode_chunk($this->state_data['file_data']);

        if (false === $file_data) {
            $error_msg = __('An error occurred while uploading the file.', 'wp-migrate-db');
            $return    = array('wpmdb_error' => 1, 'body' => $error_msg);
            $this->error_log->log_error($error_msg);

            return $this->http->end_ajax($return);
        }

        // Store the data in the file.
        $fp = fopen($this->state_data['import_path'], 'a');
        fwrite($fp, $file_data);
        fclose($fp);

        return $this->http->end_ajax('success');
    }

    /**
     * Prepares for import of a SQL file.
     *
     * @return mixed
     */
    public function ajax_prepare_import_file()
    {
        $_POST            = $this->http_helper->convert_json_body_to_post();
        $this->state_data = $this->migration_state_manager->set_post_data();

        $file = $this->state_data['import_path'];

        if ($this->file_is_gzipped($file)) {
            $file = $this->decompress_file($this->state_data['import_path']);

            if (false === $file) {
                $error_msg = __('An error occurred while decompressing the import file.', 'wp-migrate-db');

                return $this->http->end_ajax(
                    new \WP_Error(
                        'wpmdb-import-decompress-error',
                        $error_msg
                    )
                );
            }
        }

        $return = array(
            'num_chunks'  => $this->get_num_chunks_in_file($file),
            'import_file' => $file,
            'import_size' => $this->filesystem->filesize($file),
        );

        return $this->http->end_ajax($return);
    }

    /**
     * Handles AJAX requests to import a SQL file.
     *
     * @return mixed
     */
    public function ajax_import_file()
    {
        $_POST     = $this->http_helper->convert_json_body_to_post();
        $key_rules = [
            'chunk'         => 'int',
            'current_query' => 'string',
            'import_file'   => 'string',
            'import_info'   => 'json_array',
        ];

        $this->state_data = Persistence::setPostData($key_rules, __METHOD__);

        $file          = $this->state_data['import_file'];
        $chunk         = isset($this->state_data['chunk']) ? $this->state_data['chunk'] : 0;
        $num_chunks    = isset($this->state_data['num_chunks']) ? $this->state_data['num_chunks'] : $this->get_num_chunks_in_file($file);
        $current_query = isset($this->state_data['current_query']) ? base64_decode($this->state_data['current_query']) : '';

        $import = $this->import_chunk($file, $chunk, $current_query);

        if (is_wp_error($import)) {
            $error_msg = $import->get_error_message();
            $return    = array('wpmdb_error' => 1, 'body' => $error_msg);
            $this->error_log->log_error($error_msg);

            return $this->http->end_ajax(json_encode($return));
        }

        $encoded_query = base64_encode($import['current_query']);
        $return        = array(
            'chunk'         => ++$chunk,
            'num_chunks'    => $num_chunks,
            'current_query' => $encoded_query,
            'chunk_size'    => mb_strlen($import['current_query']),
        );

        // Return updated table sizes
        if ($chunk >= $num_chunks) {
            $is_backup = $this->state_data['import_info']['import_gzipped'] === true;

            $this->backup_export->delete_export_file($this->state_data['import_filename'], $is_backup);

            $return['table_sizes'] = $this->table->get_table_sizes();
            $return['table_rows']  = $this->table->get_table_row_count();
            $table_names           = array_keys($return['table_rows']);
            $filtered              = [];
            foreach ($table_names as $name) {
                if (0 === strpos($name, $this->props->temp_prefix)) {
                    $filtered[] = str_replace($this->props->temp_prefix, '', $name);
                }
            }

            $return['tables'] = $filtered;
        }

        return $this->http->end_ajax($return);
    }

    /**
     * Gets the file data from the base64 encoded chunk
     *
     * @param string $data
     *
     * @return string|bool
     */
    public function decode_chunk($data)
    {
        $data = explode(';base64,', $data);

        if (!is_array($data) || !isset($data[1])) {
            return false;
        }

        $data = base64_decode($data[1]);
        if (!$data) {
            return false;
        }

        return $data;
    }

    /**
     * Gets the SplFileObject for the provided file
     *
     * @param string $file
     * @param int    $line
     *
     * @return object SplFileObject|WP_Error
     */
    public function get_file_object($file, $line = 0)
    {
        if (!$this->filesystem->file_exists($file) || !$this->filesystem->is_readable($file)) {
            return new \WP_Error('invalid_import_file', __('The import file could not be read.', 'wp-migrate-db'));
        }

        $file = new \SplFileObject($file);
        $file->seek($line);

        return $file;
    }

    /**
     * Check that SplFileObject key and fgets are aligned
     *
     * Some versions of PHP $file->key returns 0 twice 
     *
     * @return bool
     **/
    protected function has_aligned_keys()
    {
        $file = new \SplTempFileObject();

        for ($i = 0; $i < 3; $i++) {
            $file->fwrite($i . PHP_EOL);
        }
        $file->rewind();
        while (!$file->eof()) {
            if ($file->key() !== intval($file->fgets())) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the number of chunks in a SQL file
     *
     * @param $file
     *
     * @return int|object WP_Error
     */
    public function get_num_chunks_in_file($file)
    {
        $file = $this->get_file_object($file, PHP_INT_MAX);

        if (is_wp_error($file)) {
            return $file;
        }

        $lines = $file->key();

        return ceil($lines / $this->chunk_size);
    }

    /**
     * Imports a chunk of a provided SQL file into the database
     *
     * @param string $file
     * @param int    $chunk
     * @param string $current_query
     *
     * @return array|object WP_Error
     */
    public function import_chunk($file, $chunk = 0, $current_query = '')
    {
        global $wpdb;

        $start = $chunk * $this->chunk_size;
        if (false === $this->has_aligned_keys() && $start > 0 ) {
            $start = $start - 1;
        }
        
        $lines = 0;
        $file  = $this->get_file_object($file, $start);

        if (is_wp_error($file)) {
            return $file;
        }

        while (!$file->eof()) {
            $line = trim($file->fgets());
            $lines++;

            if ($lines > $this->chunk_size) {
                // Bail if we've exceeded the chunk size
                return array(
                    'import_complete' => false,
                    'current_query'   => $current_query,
                );
            }

            if (empty($line) || '' === $line) {
                // Skip empty/new lines
                continue;
            }

            if ('--' === substr($line, 0, 2) ||
                '/* ' === substr($line, 0, 3) ||
                '#' === substr($line, 0, 1)
            ) {
                // Skip if it's a comment
                continue;
            }

            if (preg_match('/\/\*![0-9]{5} SET (.*)\*\/;/', $line, $matches)) {
                // Skip user and system defined MySQL variables
                continue;
            }

            $current_query .= $line;

            if (';' !== substr($line, -1, 1)) {
                // Doesn't have a semicolon at the end, not the end of the query
                continue;
            }

            // Run the query
            ob_start();
            $wpdb->show_errors();

            $current_query = $this->convert_to_temp_query($current_query);
            if (false === $wpdb->query($current_query)) {
                $error     = ob_get_clean();
                $error_msg = sprintf(__('Failed to import the SQL query: %s', 'wp-migrate-db'), esc_html($error));
                $return    = new \WP_Error('import_sql_execution_failed', $error_msg);

                $invalid_text = $this->table->maybe_strip_invalid_text_and_retry($current_query, 'import');
                if (false !== $invalid_text) {
                    $return = $invalid_text;
                }

                if (is_wp_error($return)) {
                    return $return;
                }
            }

            ob_end_clean();

            // Reset the temp variable
            $current_query = '';
        }

        return array('import_complete' => true, 'current_query' => $current_query);
    }

    /**
     * Decompress a file
     *
     * @param string $file The file to decompress
     * @param string $dest The destination of the decompressed file
     *
     * @return string|boolean
     */
    public function decompress_file($file, $dest = '')
    {
        if (!function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $error = false;

        if (!$this->filesystem->file_exists($file) || !$this->filesystem->is_readable($file)) {
            return $error;
        }

        $tmp_file = wp_tempnam();

        if ('' === $dest) {
            $dest = ('.gz' === substr($file, -3)) ? substr($file, 0, -3) : $file;
        }

        if ($fp_in = gzopen($file, 'rb')) {
            if ($fp_out = fopen($tmp_file, 'w')) {
                while (!gzeof($fp_in)) {
                    $string = gzread($fp_in, '4096');
                    fwrite($fp_out, $string, strlen($string));
                }

                fclose($fp_out);

                $this->filesystem->move($tmp_file, $dest);
            } else {
                $error = true;
            }

            gzclose($fp_in);
        } else {
            $error = true;
        }

        if ($error) {
            return false;
        }

        return $dest;
    }

    /**
     * Converts a query to run on temporary tables
     *
     * @param $query
     *
     * @return string
     */
    public function convert_to_temp_query($query)
    {
        $temp_prefix = $this->props->temp_prefix;

		//Look for ansi quotes and replace them with back ticks
		if ( substr( $query, 0, 14 ) === 'CREATE TABLE "' ) {
			$query = $this->table->remove_ansi_quotes( $query );
		}

		if ( substr( $query, 0, 13 ) === 'INSERT INTO `' ) {
			$query = Util::str_replace_first( 'INSERT INTO `', 'INSERT INTO `' . $temp_prefix, $query );
		} elseif ( substr( $query, 0, 14 ) === 'CREATE TABLE `' ) {
			$query = Util::str_replace_first( 'CREATE TABLE `', 'CREATE TABLE `' . $temp_prefix, $query );
		} elseif ( substr( $query, 0, 22 ) === 'DROP TABLE IF EXISTS `' ) {
			$query = Util::str_replace_first( 'DROP TABLE IF EXISTS `', 'DROP TABLE IF EXISTS `' . $temp_prefix, $query );
		} elseif ( substr( $query, 0, 13 ) === 'LOCK TABLES `' ) {
			$query = Util::str_replace_first( 'LOCK TABLES `', 'LOCK TABLES `' . $temp_prefix, $query );
		} elseif ( substr( $query, 0, 13 ) === 'ALTER TABLE `' || substr( $query, 9, 13 ) === 'ALTER TABLE `' ) {
			$query = Util::str_replace_first( 'ALTER TABLE `', 'ALTER TABLE `' . $temp_prefix, $query );
		}

        return $query;
    }

    /**
     * Checks if a string is compressed via gzip
     *
     * @param string $string
     *
     * @return bool
     */
    public function str_is_gzipped($string)
    {
        if (!function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        $is_gzipped = false;
        $tmp_file   = \wp_tempnam();

        $fh = fopen($tmp_file, 'a');
        fwrite($fh, $string);


        if ($this->file_is_gzipped($tmp_file)) {
            $is_gzipped = true;
        }

        $this->filesystem->unlink($tmp_file);

        return $is_gzipped;
    }

    /**
     * Checks if the provided file is gzipped
     *
     * @param string $file
     *
     * @return bool
     */
    public function file_is_gzipped($file)
    {
        $is_gzipped = false;

        if (!$this->filesystem->is_file($file)) {
            return $is_gzipped;
        }

        $content_type = mime_content_type($file);

        if (in_array($content_type, array('application/x-gzip', 'application/gzip'))) {
            $is_gzipped = true;
        }

        return $is_gzipped;
    }

    /**
     * Maybe change options keys to be preserved.
     *
     * @param array  $preserved_options
     * @param string $intent
     *
     * @return array
     */
    public function filter_preserved_options($preserved_options, $intent = '')
    {
        if ('import' === $intent) {
            $preserved_options = $this->table->preserve_active_plugins_option($preserved_options);
        }

        return $preserved_options;
    }

    /**
     * Maybe preserve the WPMDB plugins if they aren't already preserved.
     *
     * @param array  $preserved_options_data
     * @param string $intent
     *
     * @return array
     */
    public function filter_preserved_options_data($preserved_options_data, $intent = '')
    {
        if ('import' === $intent) {
            $preserved_options_data = $this->table->preserve_wpmdb_plugins($preserved_options_data);
        }

        return $preserved_options_data;
    }
}
