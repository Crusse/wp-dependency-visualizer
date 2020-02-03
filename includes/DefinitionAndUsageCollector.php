<?php

namespace DependencyVisualizer;

use \PhpParser\Node\Name;
use \PhpParser\Node\Identifier;
use \PhpParser\Node\Expr;
use \PhpParser\Node\Expr\FuncCall;
use \PhpParser\Node\Expr\MethodCall;
use \PhpParser\Node\Expr\StaticCall;
use \PhpParser\Node\Stmt\Function_;
use \PhpParser\Node\Stmt\Class_;
use \PhpParser\Node\Stmt\ClassMethod;

class DefinitionAndUsageCollector extends \PhpParser\NodeVisitorAbstract {

  const PHP_FILESIZE_LIMIT = 1024 * 256;

  protected $ignoredDirectories = [
    'vendor',
    'node_modules',
    'tests',
  ];
  protected $parser;
  protected $traverser;
  protected $currentFile;
  protected $currentClassStack;

  public $collectDefinitions = true;
  public $collectUsage = true;

  public $actionDefinitions = [];
  public $filterDefinitions = [];
  public $functionDefinitions = [];
  public $classDefinitions = [];
  // TODO: $globalVarDefinitions

  public $actionUsage = [];
  public $filterUsage = [];
  public $functionUsage = [];
  public $classUsage = [];
  // TODO: $globalVarUsage

  function __construct() {
    $this->parser = ( new \PhpParser\ParserFactory )->create( \PhpParser\ParserFactory::PREFER_PHP7 );
    $this->traverser = new \PhpParser\NodeTraverser;
    $this->traverser->addVisitor( $this );
  }

  function collect( $dir ) {

    if ( !file_exists( $dir ) )
      return;

    foreach ( scandir( $dir ) as $filename ) {

      if ( !$filename || $filename[ 0 ] === '.' )
        continue;

      if ( in_array( $filename, $this->ignoredDirectories ) )
        continue;

      $path = $dir .'/'. $filename;

      if ( is_dir( realpath( $path ) ) ) {
        $this->collect( $path );
      }
      else if ( preg_match( '#\.php$#i', $filename ) && filesize( $path ) <= static::PHP_FILESIZE_LIMIT ) {
        $this->parsePhpFile( $path );
      }
    }
  }

  function enterNode( \PhpParser\Node $node ) {

    if ( $node instanceof FuncCall && ( $node->name instanceof Name || $node->name instanceof Identifier ) ) {

      if ( $this->collectDefinitions && preg_match( '/^(do_action|apply_filters)(_ref_array)?$/', $node->name ) ) {
        $this->parseWpHookDefinition( $node );
      }
      else if ( $this->collectUsage ) {
        if ( preg_match( '/^add_(action|filter)$/', $node->name ) ) {
          $this->parseWpHookUsage( $node );
        }
        else {
          $this->parseFunctionCall( $node );
        }
      }
    }
    else {
      if ( $this->collectUsage ) {
        if ( $node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier ) {
          $this->parseStaticMethodCall( $node );
        }
        else if ( $node instanceof Class_ && $node->name instanceof Identifier && $node->extends instanceof Name ) {
          $this->parseClassUsage( $node );
        }
      }

      if ( $this->collectDefinitions ) {
        if ( $node instanceof Function_ ) {
          $this->parseFunctionDefinition( $node );
        }
        else if ( $node instanceof Class_ && $node->name instanceof Identifier ) {
          $this->parseClassDefinition( $node );
          $this->currentClassStack[] = (string) $node->name;
        }
        else if ( $node instanceof ClassMethod && $node->isStatic() && $this->currentClassStack ) {
          $this->parseStaticMethodDefinition( $node );
        }
      }
    }
  }

  function leaveNode( \PhpParser\Node $node ) {

    if ( $node instanceof Class_ && $node->name instanceof Identifier ) {
      array_pop( $this->currentClassStack );
    }
  }

  function beforeTraverse( array $nodes ) { /* No-op */ }
  function afterTraverse( array $nodes ) { /* No-op */ }

  protected function parsePhpFile( $path ) {

    $contents = file_get_contents( $path );

    // Performance optimization: do a string lookup for the functions we're
    // interested in. If they're not found, we don't need to parse the code.
    if ( !preg_match( '/add_(filter|action)|do_action|apply_filters/', $contents ) )
      return;

    try {
      $ast = $this->parser->parse( $contents );
    }
    catch ( \PhpParser\Error $error ) {
      return;
    }

    $this->currentFile = str_replace( ABSPATH, '', $path );
    $this->currentClassStack = [];

    $this->traverser->traverse( $ast );

    $this->currentFile = null;
    $this->currentClassStack = [];
  }

  // -----------------------------------------------------------------------
  // Definitions
  // -----------------------------------------------------------------------

  protected function parseWpHookDefinition( FuncCall $node ) {

    if ( empty( $node->args ) || !( $node->args[ 0 ]->value instanceof \PhpParser\Node\Scalar\String_ ) )
      return;

    $hookName = (string) $node->args[ 0 ]->value->value;

    if ( strpos( (string) $node->name, 'do_action' ) === 0 )
      $this->actionDefinitions[ $hookName ][ $this->currentFile ] = true;
    else
      $this->filterDefinitions[ $hookName ][ $this->currentFile ] = true;
  }

  protected function parseFunctionDefinition( Function_ $node ) {
    $this->functionDefinitions[ (string) $node->name ][ $this->currentFile ] = true;
  }

  protected function parseClassDefinition( Class_ $node ) {
    $this->classDefinitions[ (string) $node->name ][ $this->currentFile ] = true;
  }

  protected function parseStaticMethodDefinition( ClassMethod $node ) {
    $classAndMethod = $this->currentClassStack[ count( $this->currentClassStack ) - 1 ] .'::'. $node->name;
    $this->functionDefinitions[ $classAndMethod ][ $this->currentFile ] = true;
  }

  // -----------------------------------------------------------------------
  // Usage
  // -----------------------------------------------------------------------

  protected function parseWpHookUsage( FuncCall $node ) {

    if ( empty( $node->args ) || !( $node->args[ 0 ]->value instanceof \PhpParser\Node\Scalar\String_ ) )
      return;

    $hookName = (string) $node->args[ 0 ]->value->value;

    if ( ( (string) $node->name ) === 'add_action' )
      $this->actionUsage[ $hookName ][ $this->currentFile ] = true;
    else
      $this->filterUsage[ $hookName ][ $this->currentFile ] = true;
  }

  protected function parseFunctionCall( Expr $node ) {
    $this->functionUsage[ (string) $node->name ][ $this->currentFile ] = true;
  }

  protected function parseClassUsage( Class_ $node ) {
    $this->classDefinitions[ (string) $node->extends ][ $this->currentFile ] = true;
  }

  protected function parseStaticMethodCall( StaticCall $node ) {
    $this->functionUsage[ $node->class .'::'. $node->name ][ $this->currentFile ] = true;
  }
}

