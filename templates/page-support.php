<?php

class FacetWP_Support
{

    public $payment_id;


    function __construct() {
        $this->payment_id = (int) FWP()->helper->get_license_meta( 'payment_id' );
    }


    function get_html() {
        if ( 0 < $this->payment_id ) {
            $output = '<iframe src="https://facetwp.com/documentation/support/create-ticket/?sysinfo=' . $this->get_sysinfo() .'"></iframe>';
        }
        else {
            $output = '<h3>Active License Required</h3>';
            $output .= '<p>Please activate or renew your license to access support.</p>';
        }

        return $output;
    }


    function get_sysinfo() {
        $plugins = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        $theme = wp_get_theme();
        $parent = $theme->parent();

        ob_start();

?>
Home URL:                   <?php echo home_url(); ?>

Payment ID:                 <?php echo (int) $this->payment_id; ?>

WordPress Version:          <?php echo get_bloginfo( 'version' ); ?>

Theme:                      <?php echo $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ); ?>

Parent Theme:               <?php echo empty( $parent ) ? '' : $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ); ?>


PHP Version:                <?php echo phpversion(); ?>

MySQL Version:              <?php echo esc_html( $GLOBALS['wpdb']->db_version() ); ?>

Web Server Info:            <?php echo esc_html( $_SERVER['SERVER_SOFTWARE'] ); ?>


<?php
        foreach ( $plugins as $plugin_path => $plugin ) {
            if ( in_array( $plugin_path, $active_plugins ) ) {
                echo $plugin['Name'] . ' ' . $plugin['Version'] . "\n";
            }
        }

        $output = ob_get_clean();
        $output = preg_replace( "/[ ]{2,}/", ' ', trim( $output ) );
        $output = str_replace( "\n", '{n}', $output );
        $output = urlencode( $output );
        return $output;
    }
}
