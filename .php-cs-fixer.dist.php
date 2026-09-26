<?php declare( strict_types=1 );

$finder = PhpCsFixer\Finder::create()
    ->in( __DIR__ . '/src' )
    ->in( __DIR__ . '/tests' )
    ->in( __DIR__ . '/functions' )
    ->name( '*.php' );

return ( new PhpCsFixer\Config() )
    ->setRiskyAllowed( true )
    ->setRules( [
        '@PSR12'                       => true,
        'declare_strict_types'         => true,
        'strict_param'                 => true,
        'no_unused_imports'            => true,
        'ordered_imports'              => [ 'sort_algorithm' => 'alpha' ],
        'single_quote'                 => true,
        'no_trailing_whitespace'       => true,
        'no_whitespace_in_blank_line'  => true,
        'blank_line_after_namespace'   => true,
        'blank_line_after_opening_tag' => true,
    ] )
    ->setFinder( $finder );
