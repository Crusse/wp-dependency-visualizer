<?php
namespace DependencyVisualizer;

require_once __DIR__ .'/includes/buttons.php';

$allIdentifierTypes = [ 'a', 'fi', 'c', 'fn' ];

if ( !empty( $_GET[ 'depvis-identifier-types' ] ) ) {
  $identifierTypes = array_intersect( $_GET[ 'depvis-identifier-types' ], $allIdentifierTypes );
}
else {
  $identifierTypes = $allIdentifierTypes;
}

print_identifier_usage_chord_graph( $identifierTypes );
print_identifier_usage_table( $identifierTypes );
