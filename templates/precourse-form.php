<?php
/* Template for displaying the precourse form via shortcode */
get_header();
?>
<div class="precourse-form">
<?php echo do_shortcode('[ayotte_precourse_form]'); ?>
    <p class="ayotte-back-link"><a href="<?php echo esc_url(site_url('/precourse-forms')); ?>">Back to Dashboard</a></p>
</div>
<?php
get_footer();
