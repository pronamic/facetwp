<?php

class FacetWP_Facet_Search extends FacetWP_Facet
{

    function __construct() {
        $this->label = __( 'Search', 'fwp' );
        $this->fields = [ 'search_engine', 'placeholder', 'auto_refresh' ];
    }


    /**
     * Generate the facet HTML
     */
    function render( $params ) {

        $output = '';
        $value = (array) $params['selected_values'];
        $value = empty( $value ) ? '' : stripslashes( $value[0] );
        $placeholder = $params['facet']['placeholder'] ?? __( 'Enter keywords', 'fwp-front' );
        $placeholder = facetwp_i18n( $placeholder );
        $output .= '<span class="facetwp-input-wrap">';
        $output .= '<i class="facetwp-icon"></i>';
        $output .= '<input type="text" class="facetwp-search" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" />';
        $output .= '</span>';
        return $output;
    }


    /**
     * Filter the query based on selected values
     */
    function filter_posts( $params ) {

        $facet = $params['facet'];
        $selected_values = $params['selected_values'];
        $selected_values = is_array( $selected_values ) ? $selected_values[0] : $selected_values;

        if ( empty( $selected_values ) ) {
            return 'continue';
        }

        // Default WP search
        $search_args = [
            's' => $selected_values,
            'posts_per_page' => 200,
            'fields' => 'ids',
        ];

        $search_args = apply_filters( 'facetwp_search_query_args', $search_args, $params );

        $query = new WP_Query( $search_args );

        return (array) $query->posts;
    }


    function register_fields() {
        $engines = apply_filters( 'facetwp_facet_search_engines', [] );
        $choices = [ '' => __( 'WP Default', 'fwp' ) ];

        foreach ( $engines as $key => $label ) {
            $choices[ $key ] = $label;
        }

        return [
            'search_engine' => [
                'type' => 'select',
                'label' => __( 'Search engine', 'fwp' ),
                'choices' => $choices
            ],
            'auto_refresh' => [
                'type' => 'toggle',
                'label' => __( 'Auto refresh', 'fwp' ),
                'notes' => 'Automatically refresh the results while typing?'
            ]
        ];
    }


    /**
     * (Front-end) Attach settings to the AJAX response
     */
    function settings_js( $params ) {
        $auto_refresh = empty( $params['facet']['auto_refresh'] ) ? 'no' : $params['facet']['auto_refresh'];
        return [ 'auto_refresh' => $auto_refresh ];
    }
}
