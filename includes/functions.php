<?php

namespace DependencyVisualizer;

require_once __DIR__ .'/DefinitionAndUsageCollector.php';

// Increment this if you change the database table schema, so that the table is updated
define( 'DEPVIS_IDENTIFIERS_TABLE_VERSION', 3 );
define( 'DEPVIS_IDENTIFIERS_TABLE_NAME', 'depvis_identifiers' );
define( 'DEPVIS_FILES_TABLE_VERSION', 3 );
define( 'DEPVIS_FILES_TABLE_NAME', 'depvis_files' );

function create_or_update_database_tables() {
  global $wpdb;

  $charsetAndCollate = '';
  if ( !empty( $wpdb->charset ) )
    $charsetAndCollate = "DEFAULT CHARACTER SET $wpdb->charset";
  if ( !empty( $wpdb->collate ) )
    $charsetAndCollate .= " COLLATE $wpdb->collate";

  if ( get_option( 'depvis_identifiers_table_version', 0 ) != DEPVIS_IDENTIFIERS_TABLE_VERSION ) {

    require_once ABSPATH .'wp-admin/includes/upgrade.php';

    $tableName = esc_sql( $wpdb->prefix . DEPVIS_IDENTIFIERS_TABLE_NAME );
    // statement_type: 'd' for definitions, 'u' for usage
    // identifier_type: 'a' for WP action, 'fi' for WP filter, 'fn' for function, 'g' for global var, 'c' for class
    $sqlQuery = sprintf( "CREATE TABLE %s (
      id bigint(9) unsigned NOT NULL AUTO_INCREMENT,
      statement_type varchar(2) NOT NULL,
      identifier_type varchar(2) NOT NULL,
      name varchar(191) NOT NULL,
      file_id bigint(9) unsigned NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY statement_identifier_file (statement_type, identifier_type, name, file_id),
      KEY file_id (file_id)
    ) $charsetAndCollate;", $tableName );

    dbDelta( $sqlQuery );

    update_option( 'depvis_identifiers_table_version', DEPVIS_IDENTIFIERS_TABLE_VERSION );
  }

  if ( get_option( 'depvis_files_table_version', 0 ) != DEPVIS_FILES_TABLE_VERSION ) {

    require_once ABSPATH .'wp-admin/includes/upgrade.php';

    $tableName = esc_sql( $wpdb->prefix . DEPVIS_FILES_TABLE_NAME );
    $sqlQuery = sprintf( "CREATE TABLE %s (
      id bigint(9) unsigned NOT NULL AUTO_INCREMENT,
      file_path varchar(191) NOT NULL,
      file_hash varchar(16) NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY file_path (file_path)
    ) $charsetAndCollate;", $tableName );

    dbDelta( $sqlQuery );

    update_option( 'depvis_files_table_version', DEPVIS_FILES_TABLE_VERSION );
  }
}

function collect_php_code_definitions_and_usage( $step = null ) {

  set_time_limit( 120 );

  $wpCoreCollector = new DefinitionAndUsageCollector;
  $wpCoreCollector->collectUsage = false;
  $pluginAndThemeCollector = new DefinitionAndUsageCollector;

  if ( $step == 1 || $step === null ) {
    $wpCoreCollector->collect( ABSPATH .'wp-includes' );
    _store_collected_php_code_definitions_and_usage( $wpCoreCollector );
  }

  if ( $step == 2 || $step === null ) {
    $wpCoreCollector->collect( ABSPATH .'wp-admin' );
    _store_collected_php_code_definitions_and_usage( $wpCoreCollector );
  }

  if ( $step == 3 || $step === null ) {
    $pluginAndThemeCollector->collect( ABSPATH .'wp-content/plugins' );
    $pluginAndThemeCollector->collect( ABSPATH .'wp-content/mu-plugins' );
    _store_collected_php_code_definitions_and_usage( $pluginAndThemeCollector );
  }

  if ( $step == 4 || $step === null ) {
    $pluginAndThemeCollector->collect( ABSPATH .'wp-content/themes' );
    _store_collected_php_code_definitions_and_usage( $pluginAndThemeCollector );
    update_option( 'depvis_last_analysis_timestamp', time() );
  }
}

function _store_collected_php_code_definitions_and_usage( DefinitionAndUsageCollector $collector ) {
  global $wpdb;

  $identifiersTableName = $wpdb->prefix . DEPVIS_IDENTIFIERS_TABLE_NAME;
  $filesTableName = $wpdb->prefix . DEPVIS_FILES_TABLE_NAME;

  foreach ( [
    [ $collector->actionDefinitions,   'a',  'd' ],
    [ $collector->filterDefinitions,   'fi', 'd' ],
    [ $collector->functionDefinitions, 'fn', 'd' ],
    [ $collector->classDefinitions,    'c',  'd' ],
    [ $collector->actionUsage,         'a',  'u' ],
    [ $collector->filterUsage,         'fi', 'u' ],
    [ $collector->functionUsage,       'fn', 'u' ],
    [ $collector->classUsage,          'c',  'u' ],
  ] as $data )
  {
    $wpdb->query( 'START TRANSACTION' );

    foreach ( $data[ 0 ] as $identifier => $files ) {
      _store_collected_php_code_files( $files );

      foreach ( $files as $file => $true ) {
        $wpdb->query( $wpdb->prepare( "
          INSERT INTO $identifiersTableName (identifier_type, statement_type, name, file_id)
          VALUES (%s, %s, %s, (SELECT id FROM $filesTableName WHERE file_path = %s))
          ON DUPLICATE KEY UPDATE name = name
        ", $data[ 1 ], $data[ 2 ], $identifier, $file ) );
      }
    }

    $wpdb->query( 'COMMIT' );
  }
}

function _store_collected_php_code_files( array $filePaths ) {
  global $wpdb;

  $tableName = $wpdb->prefix . DEPVIS_FILES_TABLE_NAME;

  foreach ( $filePaths as $file => $true ) {
    $absPath = ( $file[ 0 ] === '/' )
      ? $file
      : ABSPATH . $file;
    $hash = md5_file( $absPath );

    $wpdb->query( $wpdb->prepare( "
      INSERT INTO $tableName (file_path, file_hash)
      VALUES (%s, %s)
      ON DUPLICATE KEY UPDATE file_hash = %s
    ", $file, $hash, $hash ) );
  }
}

function get_identifier_usage( array $identifierTypes ) {
  global $wpdb;

  $identifiersTableName = $wpdb->prefix . DEPVIS_IDENTIFIERS_TABLE_NAME;
  $filesTableName = $wpdb->prefix . DEPVIS_FILES_TABLE_NAME;

  $identifierTypesSql = "'". implode( "','", esc_sql( $identifierTypes ) ) ."'";

  $rows = $wpdb->get_results( "
    SELECT usages.name AS name, usages.identifier_type AS identifierType, files.file_path AS filePath
    FROM $identifiersTableName AS usages
    INNER JOIN $filesTableName AS files
      ON (usages.file_id = files.id)
    WHERE usages.identifier_type IN ( $identifierTypesSql )
    AND usages.statement_type = 'u'

    -- A definition must exist for each usage
    AND EXISTS (
      SELECT 1
      FROM $identifiersTableName
      WHERE statement_type = 'd'
      AND identifier_type = usages.identifier_type
      AND name = usages.name
      LIMIT 1
    )

    -- We exclude all identifiers that were defined by WP core, since we only
    -- want plugin-to-plugin dependencies
    AND NOT EXISTS (
      SELECT 1
      FROM $identifiersTableName core_definitions
      INNER JOIN $filesTableName AS core_definitions_files
        ON (core_definitions.file_id = core_definitions_files.id)
      WHERE core_definitions.statement_type = 'd'
      AND core_definitions.identifier_type = usages.identifier_type
      AND core_definitions.name = usages.name
      AND (core_definitions_files.file_path LIKE 'wp-includes%' OR core_definitions_files.file_path LIKE 'wp-admin%')
      LIMIT 1
    )
  " );

  $definitions = get_identifier_definitions( $identifierTypes );
  $ret = [];

  foreach ( $rows as $row ) {
    $pluginOrTheme = ( preg_match( '#wp-content/((mu-)?plugins|themes)/[^/]+#', $row->filePath ) )
      ? preg_replace( '#^.*wp-content/(?:(?:mu-)?plugins|themes)/([^/]+).*$#', '$1', $row->filePath )
      : '__other__';

    if ( !isset( $definitions[ $row->identifierType ][ $row->name ] ) )
      continue;

    // If the identifier is _only_ used by the same plugin/theme that defines it,
    // skip it, since we only want inter-plugin/theme dependencies, not
    // in-plugin/theme dependencies
    $definitionPluginsAndThemes = $definitions[ $row->identifierType ][ $row->name ];

    if ( count( $definitionPluginsAndThemes ) === 1 && array_keys( $definitionPluginsAndThemes )[ 0 ] === $pluginOrTheme )
      continue;

    $filePath = preg_replace( '#^.*wp-content/((mu-)?plugins|themes)/#', '', $row->filePath );

    $ret[ $row->identifierType ][ $row->name ][ $pluginOrTheme ][] = $filePath;
  }

  return $ret;
}

function get_identifier_definitions( array $identifierTypes ) {
  global $wpdb;

  $identifiersTableName = $wpdb->prefix . DEPVIS_IDENTIFIERS_TABLE_NAME;
  $filesTableName = $wpdb->prefix . DEPVIS_FILES_TABLE_NAME;

  $identifierTypesSql = "'". implode( "','", esc_sql( $identifierTypes ) ) ."'";

  $rows = $wpdb->get_results( "
    SELECT identifiers.name AS name, identifiers.identifier_type AS identifierType, files.file_path AS filePath
    FROM $identifiersTableName identifiers
    INNER JOIN $filesTableName AS files
      ON (identifiers.file_id = files.id)
    WHERE identifiers.statement_type = 'd'
    AND identifiers.identifier_type IN ( $identifierTypesSql )
    AND files.file_path NOT LIKE 'wp-includes%'
    AND files.file_path NOT LIKE 'wp-admin%'

    -- We exclude all identifiers that were defined by WP core, since we only want plugin-to-plugin dependencies
    AND NOT EXISTS (
      SELECT 1
      FROM $identifiersTableName core_definitions
      INNER JOIN $filesTableName AS core_definitions_files
        ON (core_definitions.file_id = core_definitions_files.id)
      WHERE core_definitions.statement_type = 'd'
      AND core_definitions.identifier_type = identifiers.identifier_type
      AND core_definitions.name = identifiers.name
      AND (core_definitions_files.file_path LIKE 'wp-includes%' OR core_definitions_files.file_path LIKE 'wp-admin%')
      LIMIT 1
    )
  " );

  $ret = [];

  foreach ( $rows as $row ) {
    $pluginOrTheme = ( preg_match( '#wp-content/((mu-)?plugins|themes)/#', $row->filePath ) )
      ? preg_replace( '#^.*wp-content/(?:(?:mu-)?plugins|themes)/([^/]+).*$#', '$1', $row->filePath )
      : '__other__';

    $filePath = preg_replace( '#^.*wp-content/((mu-)?plugins|themes)/#', '', $row->filePath );

    $ret[ $row->identifierType ][ $row->name ][ $pluginOrTheme ][] = $filePath;
  }

  return $ret;
}

function get_plugin_and_theme_dependencies( array $identifierTypes ) {

  $usage = get_identifier_usage( $identifierTypes );
  $definitions = get_identifier_definitions( $identifierTypes );
  $definitionsToUsages = [];

  foreach ( $usage as $identifierType => $identifiers ) {
    foreach ( $identifiers as $identifier => $pluginsAndThemes ) {
      foreach ( $pluginsAndThemes as $usagePlugin => $usagePluginFiles ) {
        foreach ( $definitions[ $identifierType ][ $identifier ] as $definitionPlugin => $defPluginFiles ) {

          if ( $usagePlugin === $definitionPlugin )
            continue;

          if ( !isset( $definitionsToUsages[ $usagePlugin ][ $definitionPlugin ] ) )
            $definitionsToUsages[ $usagePlugin ][ $definitionPlugin ] = 0;

          $definitionsToUsages[ $usagePlugin ][ $definitionPlugin ]++;
        }
      }
    }
  }

  return $definitionsToUsages;
}

function print_identifier_usage_table( array $identifierTypes ) {

  $usage = get_identifier_usage( $identifierTypes );
  $definitions = get_identifier_definitions( $identifierTypes );

  if ( !$usage || !$definitions ) {
    echo '<div>Could not print the table: no dependencies found.</div>';
    return;
  }

  ?>

  <style type="text/css">
    .depvis-dependency-table {
      margin-top: 20px;
      border-collapse: collapse;
    }
    .depvis-dependency-table thead {
      border-bottom: 1px solid #ccc;
    }
    .depvis-dependency-table th {
      font-size: 18px;
      text-align: left;
      vertical-align: top;
      padding-bottom: 16px;
    }
    .depvis-dependency-table td {
      vertical-align: top;
      padding: 4px 6px;
    }
    .depvis-dependency-table td:nth-child(2) {
      font-family: monospace;
    }
    .depvis-dependency-table h3 {
      margin: 8px 0 4px;
      padding: 0;
      font-size: 14px;
    }
    .depvis-dependency-table h3:first-child {
      margin-top: 0;
    }
    .depvis-dependency-table ul,
    .depvis-dependency-table li {
      margin: 0;
      padding: 0;
      font-size: 12px;
      line-height: 16px;
    }
  </style>

  <?php
  echo '<table class="depvis-dependency-table wp-list-table widefat striped">';

  echo '<thead>';
  echo '<tr>';
  echo '<th>Type</th>';
  echo '<th>Identifier</th>';
  echo '<th>Definition</th>';
  echo '<th>Usage</th>';
  echo '</tr>';
  echo '</thead>';

  echo '<tbody>';

  foreach ( $usage as $identifierType => $hooks ) {
    foreach ( $hooks as $hookName => $pluginsAndThemes ) {

      echo '<tr>';

      echo '<td>';
      if ( $identifierType === 'a' )
        echo 'WP action';
      else if ( $identifierType === 'fi' )
        echo 'WP filter';
      else if ( $identifierType === 'fn' )
        echo 'PHP function';
      else if ( $identifierType === 'c' )
        echo 'PHP class';
      echo '</td>';

      echo '<td>'. htmlspecialchars( $hookName ) .'</td>';

      // Definitions
      echo '<td>';
      foreach ( $definitions[ $identifierType ][ $hookName ] as $pluginOrTheme => $files ) {
        echo '<h3>'. htmlspecialchars( $pluginOrTheme ) .'</h3>';
        echo '<ul>';
        foreach ( $files as $file ) {
          echo '<li>'. htmlspecialchars( $file ) .'</li>';
        }
        echo '</ul>';
      }
      echo '</td>';

      // Usages
      echo '<td>';
      foreach ( $pluginsAndThemes as $pluginOrTheme => $files ) {
        echo '<h3>'. htmlspecialchars( $pluginOrTheme ) .'</h3>';
        echo '<ul>';
        foreach ( $files as $file ) {
          echo '<li>'. htmlspecialchars( $file ) .'</li>';
        }
        echo '</ul>';
      }
      echo '</td>';

      echo '</tr>';
    }
  }

  echo '</tbody>';
  echo '</table>';
}

function print_identifier_usage_chord_graph( array $identifierTypes ) {

  $dependencies = get_plugin_and_theme_dependencies( $identifierTypes );

  if ( !$dependencies ) {
    echo '<div>Could not print the graph: no dependencies found.</div>';
    return;
  }

  $graphId = 'depvis-dependency-chord-graph'. uniqid();

  ?>

  <script src="https://www.amcharts.com/lib/4/core.js"></script>
  <script src="https://www.amcharts.com/lib/4/charts.js"></script>
  <script src="https://www.amcharts.com/lib/4/themes/animated.js"></script>

  <div id="<?= $graphId ?>" style="width: 100%; height: 90vh; background-color: white;"></div>

  <script>
    am4core.ready( function() {

      am4core.useTheme( am4themes_animated );

      var chart = am4core.create( <?= json_encode( $graphId ) ?>, am4charts.ChordDiagram );
      chart.hiddenState.properties.opacity = 0;
      chart.innerRadius = am4core.percent( 90 );
      chart.nodes.template.label.fontSize = 16;
      chart.links.template.fillOpacity = 0.6;
      chart.data = [
        <?php

        foreach ( $dependencies as $fromPlugin => $toPluginData ) {
          foreach ( $toPluginData as $toPlugin => $dependencyCount ) {
            ?>
            {
              from: <?= json_encode( $fromPlugin ) ?>,
              to: <?= json_encode( $toPlugin ) ?>,
              value: <?= $dependencyCount ?>,
            },
            <?php
          }
        }

        ?>
      ];
      chart.dataFields.fromName = "from";
      chart.dataFields.toName = "to";
      chart.dataFields.value = "value";

      var nodeTemplate = chart.nodes.template;
      nodeTemplate.readerTitle = "Click to show/hide or drag to rearrange";
      nodeTemplate.showSystemTooltip = true;
      nodeTemplate.cursorOverStyle = am4core.MouseCursorStyle.pointer;

    } );
  </script>

  <?php
}
