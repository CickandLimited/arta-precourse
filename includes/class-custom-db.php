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
     * Ensure required tables exist and create them if missing.
     */
    public function ensure_schema() {
        $conn = $this->connection instanceof mysqli ? $this->connection : $this->connect();
        if ($conn instanceof WP_Error) {
            return;
        }

        $tables = [
            'custom_forms' => "CREATE TABLE custom_forms (\n              id INT AUTO_INCREMENT PRIMARY KEY,\n              name VARCHAR(255) NOT NULL\n            )",
            'custom_form_fields' => "CREATE TABLE custom_form_fields (\n              id INT AUTO_INCREMENT PRIMARY KEY,\n              form_id INT NOT NULL,\n              label VARCHAR(255),\n              type VARCHAR(50),\n              options TEXT,\n              required TINYINT(1) DEFAULT 0,
                min_checked INT DEFAULT 0,
                conditions TEXT,
                validation_rules TEXT
            )",
            'custom_form_submissions' => "CREATE TABLE custom_form_submissions (\n              id INT AUTO_INCREMENT PRIMARY KEY,\n              form_id INT NOT NULL,\n              user_id INT NOT NULL,\n              submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n              data LONGTEXT,\n              status VARCHAR(20) NOT NULL DEFAULT 'draft',\n              locked TINYINT(1) DEFAULT 0\n            )"
        ];

        foreach ($tables as $name => $sql) {
            $check = $conn->query("SHOW TABLES LIKE '$name'");
            if (!$check || $check->num_rows === 0) {
                $conn->query($sql);
            }
        }

        // Optional column checks
        $columns = [
            'custom_form_fields' => [
                'options'  => "ALTER TABLE custom_form_fields ADD COLUMN options TEXT",
                'required' => "ALTER TABLE custom_form_fields ADD COLUMN required TINYINT(1) DEFAULT 0",
                'min_checked' => "ALTER TABLE custom_form_fields ADD COLUMN min_checked INT DEFAULT 0",
                'validation_rules' => "ALTER TABLE custom_form_fields ADD COLUMN validation_rules TEXT",
                'conditions' => "ALTER TABLE custom_form_fields ADD COLUMN conditions TEXT"
            ],
            'custom_form_submissions' => [
                'data'   => "ALTER TABLE custom_form_submissions ADD COLUMN data LONGTEXT",
                'user_id' => "ALTER TABLE custom_form_submissions ADD COLUMN user_id INT NOT NULL",
                'status' => "ALTER TABLE custom_form_submissions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'",
                'locked' => "ALTER TABLE custom_form_submissions ADD COLUMN locked TINYINT(1) DEFAULT 0"
            ]
        ];

        foreach ($columns as $table => $cols) {
            foreach ($cols as $column => $alter) {
                $res = $conn->query("SHOW COLUMNS FROM $table LIKE '$column'");
                if ($res && $res->num_rows === 0) {
                    $conn->query($alter);
                }
            }
        }
    }

    /**
     * Return an existing connection if available.
     */
    public function get_connection() {
        return $this->connect();
    }
}
?>
