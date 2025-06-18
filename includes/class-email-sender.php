<?php
class Ayotte_Email_Sender {

    public function send_email($to, $subject, $message, $headers = [], $attachments = []) {
        $sent = wp_mail($to, $subject, $message, $headers, $attachments);
        ayotte_log_message($sent ? 'INFO' : 'ERROR', ($sent ? 'Email sent' : 'Failed email') . " to $to");
        return $sent;
    }

    public function send_invitation($email, $link) {
        $subject = "Complete Your Precourse Registration";
        $message = "Hi,\n\nPlease complete your registration here:\n$link\n\nThanks,\nAyotte Training";
        return $this->send_email($email, $subject, $message);
    }

    public function send_credentials($email, $password) {
        $login_link = wp_login_url(site_url('/precourse-forms'));
        $subject = 'Your Precourse Portal Account';
        $message = "Hi,\n\nYour account has been created.\nUsername: $email\nPassword: $password\nLogin here: $login_link\n\nThanks,\nAyotte Training";
        return $this->send_email($email, $subject, $message);
    }
}

