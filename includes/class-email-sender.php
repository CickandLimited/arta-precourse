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
}
?>
