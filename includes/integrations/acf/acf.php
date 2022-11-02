<?php

class FacetWP_Integration_ACF
{

    public $fields = [];
    public $parent_type_lookup = [];
    public $repeater_row;


    function __construct() {
        add_filter( 'facetwp_facet_sources', [ $this, 'facet_sources' ] );
        add_filter( 'facetwp_facet_orderby', [ $this, 'facet_orderby' ], 10, 2 );
        add_filter( 'facetwp_indexer_query_args', [ $this, 'lookup_acf_fields' ] );
        add_filter( 'facetwp_indexer_post_facet', [ $this, 'index_acf_values' ], 1, 2 );
        add_filter( 'facetwp_acf_display_value', [ $this, 'index_source_other' ], 1, 2 );
        add_filter( 'facetwp_builder_item_value', [ $this, 'layout_builder_values' ], 999, 2 );
    }


    /**
     * Add ACF fields to the Data Sources dropdown
     */
    function facet_sources( $sources ) {
        $fields = $this->get_fields();
        $choices = [];

        foreach ( $fields as $field ) {
            $field_id = $field['hierarchy'];
            $field_name = $field['name'];
            $field_label = '[' . $field['group_title'] . '] ' . $field['parents'] . $field['label'];
            $choices[ "acf/$field_id" ] = $field_label;

            // remove "hidden" ACF fields
            unset( $sources['custom_fields']['choices'][ "cf/_$field_name" ] );
        }

        if ( ! empty( $choices ) ) {
            $sources['acf'] = [
                'label' => 'ACF',
                'choices' => $choices,
                'weight' => 5
            ];
        }

        return $sources;
    }


    /**
     * If the facet "Sort by" value is "Term order", then preserve
     * the custom order of certain ACF fields (checkboxes, radio, etc.)
     */
    function facet_orderby( $orderby, $facet ) {
        if ( isset( $facet['source'] ) && isset( $facet['orderby'] ) ) {
            if ( 0 === strpos( $facet['source'], 'acf/' ) && 'term_order' == $facet['orderby'] ) {
                $source_parts = explode( '/', $facet['source'] );
                $field_id = array_pop( $source_parts );
                $field_object = get_field_object( $field_id );
                if ( ! empty( $field_object['choices'] ) ) {
                    $choices = $field_object['choices'];
                    $choices = implode( "','", esc_sql( $choices ) );
                    $orderby = "FIELD(f.facet_display_value, '$choices')";
                }
            }
        }

        return $orderby;
    }


    /**
     * Index ACF field data
     */
    function index_acf_values( $return, $params ) {
        $defaults = $params['defaults'];
        $facet = $params['facet'];

        if ( isset( $facet['source'] ) && 'acf/' == substr( $facet['source'], 0, 4 ) ) {
            $hierarchy = explode( '/', substr( $facet['source'], 4 ) );

            // support "User Post Type" plugin
            $object_id = apply_filters( 'facetwp_acf_object_id', $defaults['post_id'] );

            // get values (for sub-fields, use the parent repeater)
            $value = get_field( $hierarchy[0], $object_id, false );

            // handle repeater values
            if ( 1 < count( $hierarchy ) ) {

                $parent_field_key = array_shift( $hierarchy );
                $value = $this->process_field_value( $value, $hierarchy, $parent_field_key );

                // get the sub-field properties
                $sub_field = get_field_object( $hierarchy[0], $object_id, false, false );

                foreach ( $value as $key => $val ) {
                    $this->repeater_row = $key;
                    $rows = $this->get_values_to_index( $val, $sub_field, $defaults );
                    $this->index_field_values( $rows );
                }
            }
            else {

                // get the field properties
                $field = get_field_object( $hierarchy[0], $object_id, false, false );

                // index values
                $rows = $this->get_values_to_index( $value, $field, $defaults );
                $this->index_field_values( $rows );
            }

            return true;
        }

        return $return;
    }


    /**
     * Hijack the "facetwp_indexer_query_args" hook to lookup the fields once
     */
    function lookup_acf_fields( $args ) {
        $this->get_fields();
        return $args;
    }


    /**
     * Grab all ACF fields
     */
    function get_fields() {

        add_action( 'pre_get_posts', [ $this, 'disable_wpml' ] );
        $field_groups = acf_get_field_groups();
        remove_action( 'pre_get_posts', [ $this, 'disable_wpml' ] );

        foreach ( $field_groups as $field_group ) {
            $fields = acf_get_fields( $field_group );

            if ( ! empty( $fields ) ) {
                $this->flatten_fields( $fields, $field_group );
            }
        }

        return $this->fields;
    }


    /**
     * We need to get field groups in ALL languages
     */
    function disable_wpml( $query ) {
        $query->set( 'suppress_filters', true );
        $query->set( 'lang', '' );
    }


    /**
     * Extract field values from the repeater array
     */
    function process_field_value( $value, $hierarchy, $parent_field_key ) {

        if ( ! is_array( $value ) ) {
            return [];
        }

        $temp_val = [];
        $parent_field_type = $this->parent_type_lookup[ $parent_field_key ];

        // reduce the hierarchy array
        $field_key = array_shift( $hierarchy );

        // group
        if ( 'group' == $parent_field_type ) {
            if ( 0 == count( $hierarchy ) ) {
                $temp_val[] = $value[ $field_key ];
            }
            else {
                return $this->process_field_value( $value[ $field_key ], $hierarchy, $field_key );
            }
        }
        // repeater
        else {
            if ( 0 == count( $hierarchy ) ) {
                foreach ( $value as $val ) {
                    $temp_val[] = $val[ $field_key ];
                }
            }
            else {
                foreach ( $value as $outer ) {
                    if ( isset( $outer[ $field_key ] ) ) {
                        foreach ( $outer[ $field_key ] as $inner ) {
                            $temp_val[] = $inner;
                        }
                    }
                }

                return $this->process_field_value( $temp_val, $hierarchy, $field_key );
            }
        }

        return $temp_val;
    }


    /**
     * Get an array of $params arrays
     * Useful for indexing and grabbing values for the Layout Builder
     * @since 3.4.0
     */
    function get_values_to_index( $value, $field, $params ) {
        $value = maybe_unserialize( $value );
        $type = $field['type'];
        $output = [];

        // checkboxes
        if ( 'checkbox' == $type || 'select' == $type || 'radio' == $type ) {
            if ( false !== $value ) {
                foreach ( (array) $value as $val ) {
                    $display_value = isset( $field['choices'][ $val ] ) ?
                        $field['choices'][ $val ] :
                        $val;

                    $params['facet_value'] = $val;
                    $params['facet_display_value'] = $display_value;
                    $output[] = $params;
                }
            }
        }

        // relationship
        elseif ( 'relationship' == $type || 'post_object' == $type || 'page_link' == $type ) {
            if ( false !== $value ) {
                foreach ( (array) $value as $val ) {

                    // does the post exist?
                    if ( false !== get_post_type( $val ) ) {
                        $params['facet_value'] = $val;
                        $params['facet_display_value'] = get_the_title( $val );
                        $output[] = $params;
                    }
                }
            }
        }

        // user
        elseif ( 'user' == $type ) {
            if ( false !== $value )  {
                foreach ( (array) $value as $val ) {
                    $user = get_user_by( 'id', $val );

                    // does the user exist?
                    if ( false !== $user ) {
                        $params['facet_value'] = $val;
                        $params['facet_display_value'] = $user->display_name;
                        $output[] = $params;
                    }
                }
            }
        }

        // taxonomy
        elseif ( 'taxonomy' == $type ) {
            if ( ! empty( $value ) ) {
                foreach ( (array) $value as $val ) {
                    global $wpdb;

                    $term_id = (int) $val;
                    $term = $wpdb->get_row( "SELECT name, slug FROM {$wpdb->terms} WHERE term_id = '$term_id' LIMIT 1" );

                    // does the term exist?
                    if ( null !== $term ) {
                        $params['facet_value'] = $term->slug;
                        $params['facet_display_value'] = $term->name;
                        $params['term_id'] = $term_id;
                        $output[] = $params;
                    }
                }
            }
        }

        // date_picker
        elseif ( 'date_picker' == $type ) {
            $formatted = $this->format_date( $value );
            $params['facet_value'] = $formatted;
            $params['facet_display_value'] = apply_filters( 'facetwp_acf_display_value', $formatted, $params );
            $output[] = $params;
        }

        // true_false
        elseif ( 'true_false' == $type ) {
            $display_value = ( 0 < (int) $value ) ? __( 'Yes', 'fwp-front' ) : __( 'No', 'fwp-front' );
            $params['facet_value'] = $value;
            $params['facet_display_value'] = $display_value;
            $output[] = $params;
        }

        // google_map
        elseif ( 'google_map' == $type ) {
            if ( isset( $value['lat'] ) && isset( $value['lng'] ) ) {
                $params['facet_value'] = $value['lat'];
                $params['facet_display_value'] = $value['lng'];
                $params['place_details'] = $value;
                $output[] = $params;
            }
        }

        // text
        else {
            $params['facet_value'] = $value;
            $params['facet_display_value'] = apply_filters( 'facetwp_acf_display_value', $value, $params );
            $output[] = $params;
        }

        return $output;
    }


    /**
     * Index values
     */
    function index_field_values( $rows ) {
        foreach ( $rows as $params ) {
            FWP()->indexer->index_row( $params );
        }
    }


    /**
     * Handle "source_other" setting
     */
    function index_source_other( $value, $params ) {
        if ( ! empty( $params['facet_name'] ) ) {
            $facet = FWP()->helper->get_facet_by_name( $params['facet_name'] );

            if ( ! empty( $facet['source_other'] ) ) {
                $hierarchy = explode( '/', substr( $facet['source_other'], 4 ) );

                // support "User Post Type" plugin
                $object_id = apply_filters( 'facetwp_acf_object_id', $params['post_id'] );

                // get the value
                $value = get_field( $hierarchy[0], $object_id, false );

                // handle repeater values
                if ( 1 < count( $hierarchy ) ) {
                    $parent_field_key = array_shift( $hierarchy );
                    $value = $this->process_field_value( $value, $hierarchy, $parent_field_key );
                    $value = $value[ $this->repeater_row ];
                }
            }

            if ( 'date_range' == $facet['type'] ) {
                $value = $this->format_date( $value );
            }
        }

        return $value;
    }


    /**
     * Format dates in YYYY-MM-DD
     */
    function format_date( $str ) {
        if ( 8 == strlen( $str ) && ctype_digit( $str ) ) {
            $str = substr( $str, 0, 4 ) . '-' . substr( $str, 4, 2 ) . '-' . substr( $str, 6, 2 );
        }

        return $str;
    }


    /**
     * Generates a flat array of fields within a specific field group
     */
    function flatten_fields( $fields, $field_group, $hierarchy = '', $parents = '' ) {
        foreach ( $fields as $field ) {

            // append the hierarchy string
            $new_hierarchy = $hierarchy . '/' . $field['key'];

            // loop again for repeater or group fields
            if ( 'repeater' == $field['type'] || 'group' == $field['type'] ) {
                $new_parents = $parents . $field['label'] . ' &rarr; ';

                $this->parent_type_lookup[ $field['key'] ] = $field['type'];
                $this->flatten_fields( $field['sub_fields'], $field_group, $new_hierarchy, $new_parents );
            }
            else {
                $this->fields[] = [
                    'key'           => $field['key'],
                    'name'          => $field['name'],
                    'label'         => $field['label'],
                    'hierarchy'     => trim( $new_hierarchy, '/' ),
                    'parents'       => $parents,
                    'group_title'   => $field_group['title'],
                ];
            }
        }
    }


    /**
     * Get the field value (support User Post Type)
     * @since 3.4.1
     */
    function get_field( $source, $post_id ) {
        $hierarchy = explode( '/', substr( $source, 4 ) );
        $object_id = apply_filters( 'facetwp_acf_object_id', $post_id );
        return get_field( $hierarchy[0], $object_id );
    }


    /**
     * Fallback values for the layout builder
     * @since 3.4.0
     * 
     * ACF return formats:
     * [image, file] = array, url, id
     * [select, checkbox, radio, button_group] = value, label, array (both)
     * [post_object, relationship, taxonomy] = object, id
     * [user] = array, object, id
     * [link] = array, url
     */
    function layout_builder_values( $value, $item ) {
        global $post;

        // exit if not an object or array
        if ( is_scalar( $value ) || is_null( $value ) ) {
            return $value;
        }

        $hierarchy = explode( '/', substr( $item['source'], 4 ) );

        // support "User Post Type" plugin
        $object_id = apply_filters( 'facetwp_acf_object_id', $post->ID );

        // get the field properties
        $field = get_field_object( $hierarchy[0], $object_id, false, false );

        $type = $field['type'];
        $format = $field['return_format'] ?? '';
        $is_multiple = (bool) ( $field['multiple'] ?? false );

        if ( ( 'post_object' == $type || 'relationship' == $type ) && 'object' == $format ) {
            $output = [];

            $value = is_array( $value ) ? $value : [ $value ];

            foreach ( $value as $val ) {
                $output[] = '<a href="' . get_permalink( $val->ID ) . '">' . esc_html( $val->post_title ) . '</a>';
            }

            $value = $output;
        }

        if ( 'taxonomy' == $type && 'object' == $format ) {
            $output = [];

            foreach ( $value as $val ) {
                $output[] = $val->name;
            }

            $value = $output;
        }

        if ( ( 'select' == $type || 'checkbox' == $type || 'radio' == $type || 'button_group' == $type ) && 'array' == $format ) {
            $value = $value['label'] ?? wp_list_pluck( $value, 'label' );
        }

        if ( ( 'image' == $type || 'gallery' == $type ) && 'array' == $format ) {
            $value = ( 'image' == $type ) ? [ $value ] : $value;

            foreach ( $value as $val ) {
                $value = '<img src="' . esc_url( $val['url'] ) . '" title="' . esc_attr( $val['title'] ) . '" alt="' . esc_attr( $val['alt'] ) . '" />';
            }
        }

        if ( 'file' == $type && 'array' == $format ) {
            $value = '<a href="' . esc_url( $value['url'] ) . '">' . esc_html( $value['filename'] ) . '</a> (' . size_format( $value['filesize'], 1 ) . ')';
        }

        if ( 'link' == $type && 'array' == $format ) {
            $value = '<a href="' . esc_url( $value['url'] ) . '" target="' . esc_attr( $value['target'] ) . '">' . esc_html( $value['title'] ) . '</a>';
        }

        if ( 'google_map' == $type ) {
            $value = '<a href="https://www.google.com/maps/?q=' . $value['lat'] . ',' . $value['lng'] . '" target="_blank">' . esc_html( $value['address'] ) . '</a>';
        }

        if ( 'user' == $type && ( 'object' == $format || 'array' == $format ) ) {
            $output = [];

            $value = $is_multiple ? $value : [ $value ];

            foreach ( $value as $val ) {
                if ( 'object' == $format ) {
                    $output[] = $val->display_name;
                }
                elseif ( 'array' == $format ) {
                    $output[] = $val['display_name'];
                }
            }
            $value = $output;
        }

        return $value;
    }
}


if ( function_exists( 'acf' ) && version_compare( acf()->settings['version'], '5.0', '>=' ) ) {
    FWP()->acf = new FacetWP_Integration_ACF();
}
