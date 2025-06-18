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

    public function send_invite_email($email) {
        if (!is_email($email)) {
            ayotte_log_message('ERROR', "Invalid email: $email");
            return false;
        }
        $token = $this->generate_token($email);
        $link = site_url("/precourse-invite/?token={$token}");
        $sender = new Ayotte_Email_Sender();
        $sent = $sender->send_invitation($email, $link);
        ayotte_log_message($sent ? 'INFO' : 'ERROR', ($sent ? 'Sent' : 'Failed to send') . " invite to $email");
        return $sent;
    }
}
?>
