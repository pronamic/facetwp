<?php

class FacetWP_Upgrade
{
    function __construct() {
        $this->version = FACETWP_VERSION;
        $this->last_version = get_option( 'facetwp_version' );

        if ( version_compare( $this->last_version, $this->version, '<' ) ) {
            if ( version_compare( $this->last_version, '0.1.0', '<' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                $this->clean_install();
            }
            else {
                $this->run_upgrade();
            }

            update_option( 'facetwp_version', $this->version );
        }
    }


    private function clean_install() {
        global $wpdb;

        $int = apply_filters( 'facetwp_use_bigint', false ) ? 'BIGINT' : 'INT';

        $sql = "
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}facetwp_index (
            id BIGINT unsigned not null auto_increment,
            post_id $int unsigned,
            facet_name VARCHAR(50),
            facet_value VARCHAR(50),
            facet_display_value VARCHAR(200),
            term_id $int unsigned default '0',
            parent_id $int unsigned default '0',
            depth INT unsigned default '0',
            variation_id $int unsigned default '0',
            PRIMARY KEY (id),
            INDEX post_id_idx (post_id),
            INDEX facet_name_idx (facet_name),
            INDEX facet_name_value_idx (facet_name, facet_value)
        ) DEFAULT CHARSET=utf8";
        dbDelta( $sql );

        // Add default settings
        $settings = file_get_contents( FACETWP_DIR . '/assets/js/src/sample.json' );
        add_option( 'facetwp_settings', $settings );
    }


    private function run_upgrade() {
        global $wpdb;

        $table = sanitize_key( $wpdb->prefix . 'facetwp_index' );

        if ( version_compare( $this->last_version, '3.1.0', '<' ) ) {
            $wpdb->query( "ALTER TABLE $table MODIFY facet_name VARCHAR(50)" );
            $wpdb->query( "ALTER TABLE $table MODIFY facet_value VARCHAR(50)" );
            $wpdb->query( "ALTER TABLE $table MODIFY facet_display_value VARCHAR(200)" );
            $wpdb->query( "CREATE INDEX facet_name_value_idx ON $table (facet_name, facet_value)" );
        }

        if ( version_compare( $this->last_version, '3.3.2', '<' ) ) {
            $wpdb->query( "CREATE INDEX post_id_idx ON $table (post_id)" );
            $wpdb->query( "DROP INDEX facet_source_idx ON $table" );
            $wpdb->query( "ALTER TABLE $table DROP COLUMN facet_source" );
        }

        if ( version_compare( $this->last_version, '3.3.3', '<' ) ) {
            if ( function_exists( 'SWP' ) ) {
                $engines = array_keys( SWP()->settings['engines'] );
                $settings = get_option( 'facetwp_settings' );
                $settings = json_decode( $settings, true );

                foreach ( $settings['facets'] as $key => $facet ) {
                    if ( 'search' == $facet['type'] ) {
                        if ( in_array( $facet['search_engine'], $engines ) ) {
                            $settings['facets'][ $key ]['search_engine'] = 'swp_' . $facet['search_engine'];
                        }
                    }
                }

                update_option( 'facetwp_settings', json_encode( $settings ) );
            }
        }

        if ( version_compare( $this->last_version, '3.5.3', '<' ) ) {
            update_option( 'facetwp_updater_response', '', 'no' );
            update_option( 'facetwp_updater_last_checked', '', 'no' );
        }

        if ( version_compare( $this->last_version, '3.5.4', '<' ) ) {
            $settings = get_option( 'facetwp_settings' );
            $settings = json_decode( $settings, true );

            if ( ! isset( $settings['settings']['prefix'] ) ) {
                $settings['settings']['prefix'] = 'fwp_';

                update_option( 'facetwp_settings', json_encode( $settings ) );
            }
        }
    }
}
