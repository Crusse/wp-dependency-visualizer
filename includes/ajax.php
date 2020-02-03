<?php

namespace DependencyVisualizer;

add_action( 'wp_ajax_depvis_run_step', function() {

  if ( !current_user_can( DEPVIS_REQUIRED_USER_CAPABILITY ) ) {
    status_header( 403 );
    die();
  }

  if ( empty( $_POST[ 'step' ] ) ) {
    status_header( 400 );
    trigger_error( 'Missing "step" variable' );
    die();
  }

  collect_php_code_definitions_and_usage( (int) $_POST[ 'step' ] );

  wp_die();
} );
