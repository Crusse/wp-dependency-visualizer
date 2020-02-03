<?php

namespace DependencyVisualizer;

?>

<style type="text/css">

#depvis-toolbar {
  margin: 16px 0;
}

#depvis-messages {
  margin: 16px 0;
}

#depvis-identifier-type-selection {
  margin: 32px 0;
}
#depvis-identifier-type-selection button {
  margin-top: 6px;
}

</style>

<div id="depvis-toolbar">

  <button id="depvis-analyze-button" class="button">
    <?= ( get_option( 'depvis_last_analysis_timestamp' ) ? 'Re-analyze' : 'Click here to analyze' ) ?> dependencies
  </button>

  <div id="depvis-messages">
    <?=
      get_option( 'depvis_last_analysis_timestamp' )
        ? 'Last analyzed at '. date( 'j.n.Y H:i', get_option( 'depvis_last_analysis_timestamp' ) ) .'.'
        : 'Click the button above to analyze dependencies.'
    ?>
  </div>

  <script>

  jQuery( function() {

    var messages = jQuery( '#depvis-messages' );
    var lastStep = 4;

    function runStep( step ) {
      messages.append( 'Running step '+ step +'/'+ lastStep +'...' );

      jQuery.post( '<?= site_url( '/wp-admin/admin-ajax.php' ) ?>?action=depvis_run_step', { step: step } ).
        then( function() {
          messages.append( ' done<br>' );
          if ( step < lastStep ) {
            runStep( step + 1 );
          }
          else {
            window.location.reload();
          }
        } ).
        fail( function() {
          messages.append( ' FAIL. See the server error logs.<br>' );
        } );
    }

    jQuery( '#depvis-analyze-button' ).click( function( event ) {
      event.preventDefault();
      messages.empty().append( 'Parsing PHP files, please wait...<br>' );

      runStep( 1 );
    } );
  } );

  </script>

  <?php

  if ( get_option( 'depvis_last_analysis_timestamp' ) ) {
    ?>

    <form id="depvis-identifier-type-selection" action="<?= admin_url( 'admin.php' ) ?>" method="GET">
      <input type="hidden" name="page" value="dependency-visualizer">
      <p>Show dependencies for the following identifiers:</p>
      <?php

      foreach ( [
        'a' => 'WP actions',
        'fi' => 'WP filters',
        'fn' => 'PHP functions',
        'c' => 'PHP classes',
      ] as $identifierType => $label )
      {
        ?>
        <label>
          <input
            type="checkbox"
            name="depvis-identifier-types[]"
            <?=
              ( empty( $_GET[ 'depvis-identifier-types' ] ) || in_array( $identifierType, $_GET[ 'depvis-identifier-types' ] ) )
                ? 'checked="checked"'
                : ''
            ?>
            value="<?= $identifierType ?>"
          >&nbsp;<?= $label ?>
          <br>
        </label>
        <?php
      }

      ?>
      <button type="submit" class="button">Show dependencies</button>
    </form>

    <?php
  }

  ?>

</div>
