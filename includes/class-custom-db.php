<?php
class Custom_DB {
    private static $instance = null;
    private $connection;

    private function __construct() {}

    /**
     * Singleton accessor.
     */
    public static function get_instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish a mysqli connection using options stored in WordPress.
     *
     * @return mysqli|WP_Error
     */
    public function connect() {
        if ($this->connection instanceof mysqli) {
            return $this->connection;
        }

        $host = get_option('ayotte_form_db_host', '');
        $user = get_option('ayotte_form_db_user', '');
        $pass = get_option('ayotte_form_db_pass', '');
        $name = get_option('ayotte_form_db_name', '');

        $mysqli = @new mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_error) {
            return new WP_Error('db_connect_error', $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');
        $this->connection = $mysqli;
        return $this->connection;
    }

    /**
     * Return an existing connection if available.
     */
    public function get_connection() {
        return $this->connect();
    }
}
?>
