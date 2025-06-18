<?php
class Invitation_Manager {

    public function generate_token($email) {
        $token = bin2hex(random_bytes(16));
        $expiry = time() + WEEK_IN_SECONDS;
        add_option("ayotte_invite_{$token}", ['email' => $email, 'expires' => $expiry]);
        ayotte_log_message('INFO', "Generated token for $email");
        return $token;
    }

    public function validate_token($token) {
        $data = get_option("ayotte_invite_{$token}");
        if ($data && $data['expires'] > time()) {
            return $data['email'];
        }
        return false;
    }

    public function create_customer_user($email) {
        $user = get_user_by('email', $email);
        if ($user) {
            return [$user->ID, null];
        }

        $password = wp_generate_password();
        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            ayotte_log_message('ERROR', 'Failed to create user ' . $email . ': ' . $user_id->get_error_message());
            return [0, null];
        }

        $user = get_user_by('id', $user_id);
        if ($user) {
            $user->set_role('customer');
        }

        return [$user_id, $password];
    }

    public function send_invite_email($email) {
        if (!is_email($email)) {
            ayotte_log_message('ERROR', "Invalid email: $email");
            return false;
        }

        list($user_id, $password) = $this->create_customer_user($email);

        if (!$user_id || !$password) {
            ayotte_log_message('ERROR', "Failed to create account for $email");
            return false;
        }

        $sender = new Ayotte_Email_Sender();
        $sent = $sender->send_credentials($email, $password);
        ayotte_log_message($sent ? 'INFO' : 'ERROR', ($sent ? 'Sent' : 'Failed to send') . " credentials to $email");
        return $sent;
    }
}
?>
