<?php
/**
 * Generate .mo file from .po file
 * Run: php generate-mo.php
 */

function generate_mo( string $po_file, string $mo_file ): void {
    $content = file_get_contents( $po_file );
    // Normalize line endings to LF
    $content = str_replace( "\r\n", "\n", $content );
    $content = str_replace( "\r", "\n", $content );
    $translations = [];

    // Parse msgid/msgstr pairs
    preg_match_all('/msgid\s+"((?:[^"\\\\]|\\\\.)*)"\nmsgstr\s+"((?:[^"\\\\]|\\\\.)*)"/', $content, $matches, PREG_SET_ORDER);

    foreach ( $matches as $match ) {
        $msgid  = stripcslashes( $match[1] );
        $msgstr = stripcslashes( $match[2] );
        if ( $msgid !== '' && $msgstr !== '' ) {
            $translations[ $msgid ] = $msgstr;
        }
    }

    // Sort by msgid
    ksort( $translations );

    $num = count( $translations );
    $offsets      = [];
    $strs_offsets = [];
    $ids  = '';
    $strs = '';

    foreach ( $translations as $id => $str ) {
        $offsets[]      = [ strlen( $id ),  strlen( $ids ) ];
        $ids .= $id . "\0";
        $strs_offsets[] = [ strlen( $str ), strlen( $strs ) ];
        $strs .= $str . "\0";
    }

    $header_size      = 28;
    $key_table_offset = $header_size;
    $val_table_offset = $header_size + $num * 8;
    $key_data_offset  = $header_size + $num * 16;
    $val_data_offset  = $key_data_offset + strlen( $ids );

    $mo  = pack( 'V', 0x950412de );        // magic number
    $mo .= pack( 'V', 0 );                 // revision
    $mo .= pack( 'V', $num );              // number of strings
    $mo .= pack( 'V', $key_table_offset ); // offset of original strings table
    $mo .= pack( 'V', $val_table_offset ); // offset of translated strings table
    $mo .= pack( 'V', 0 );                 // hash table size (0 = none)
    $mo .= pack( 'V', 0 );                 // hash table offset

    // Original strings table
    foreach ( $offsets as $off ) {
        $mo .= pack( 'V', $off[0] );                    // length
        $mo .= pack( 'V', $key_data_offset + $off[1] ); // offset
    }

    // Translated strings table
    foreach ( $strs_offsets as $off ) {
        $mo .= pack( 'V', $off[0] );                    // length
        $mo .= pack( 'V', $val_data_offset + $off[1] ); // offset
    }

    $mo .= $ids;
    $mo .= $strs;

    file_put_contents( $mo_file, $mo );
    echo "Generated: $mo_file (" . count( $translations ) . " strings)\n";
}

$po = __DIR__ . '/linkvitals/languages/linkvitals-zh_CN.po';
$mo = __DIR__ . '/linkvitals/languages/linkvitals-zh_CN.mo';

generate_mo( $po, $mo );
