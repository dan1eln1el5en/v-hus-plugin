<?php
/**
 * Minimal .xlsx / .csv reader with no Composer dependencies.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the first worksheet of an .xlsx file (or a CSV) into a list of rows.
 * Each row is an array indexed from 0 by column position; empty cells are ''.
 */
class Xlsx_Reader {

	const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	/**
	 * Read a spreadsheet file.
	 *
	 * @param string $path     File on disk.
	 * @param string $filename Original name, used to pick the format.
	 * @return array<int,array<int,string>>
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function read( string $path, string $filename = '' ): array {
		$ext = strtolower( pathinfo( $filename ? $filename : $path, PATHINFO_EXTENSION ) );
		if ( 'csv' === $ext ) {
			return self::read_csv( $path );
		}
		return self::read_xlsx( $path );
	}

	/**
	 * Read an .xlsx file.
	 *
	 * @return array<int,array<int,string>>
	 * @throws \RuntimeException On invalid files.
	 */
	public static function read_xlsx( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'PHP-udvidelsen zip mangler på serveren. Gem filen som CSV i stedet.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( 'Filen kunne ikke åbnes som Excel (.xlsx).' );
		}
		try {
			$strings    = self::shared_strings( $zip );
			$sheet_path = self::first_sheet_path( $zip );
			$xml        = $zip->getFromName( $sheet_path );
			if ( false === $xml ) {
				throw new \RuntimeException( 'Regnearket blev ikke fundet i filen.' );
			}
			return self::parse_sheet( $xml, $strings );
		} finally {
			$zip->close();
		}
	}

	/**
	 * Shared string table; rich-text runs are concatenated.
	 *
	 * @return string[]
	 */
	private static function shared_strings( \ZipArchive $zip ): array {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return array();
		}
		$doc = self::load_xml( $xml );
		$out = array();
		foreach ( $doc->children( self::NS )->si as $si ) {
			$out[] = self::string_item_text( $si );
		}
		return $out;
	}

	/** Text of an <si> or <is> element: plain <t> or concatenated <r><t> runs. */
	private static function string_item_text( \SimpleXMLElement $si ): string {
		$c = $si->children( self::NS );
		if ( isset( $c->t ) ) {
			return (string) $c->t;
		}
		$text = '';
		foreach ( $c->r as $run ) {
			$text .= (string) $run->children( self::NS )->t;
		}
		return $text;
	}

	/** Path of the first sheet, following workbook relationships. */
	private static function first_sheet_path( \ZipArchive $zip ): string {
		$default = 'xl/worksheets/sheet1.xml';
		$wb      = $zip->getFromName( 'xl/workbook.xml' );
		$rels    = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $wb || false === $rels ) {
			return $default;
		}
		$wb_doc = self::load_xml( $wb );
		$sheets = $wb_doc->children( self::NS )->sheets;
		if ( ! $sheets || ! isset( $sheets->children( self::NS )->sheet[0] ) ) {
			return $default;
		}
		$rid = (string) $sheets->children( self::NS )->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
		foreach ( self::load_xml( $rels )->children() as $rel ) {
			if ( self::attr( $rel, 'Id' ) === $rid ) {
				$target = ltrim( self::attr( $rel, 'Target' ), '/' );
				return str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
			}
		}
		return $default;
	}

	/**
	 * Parse sheet XML into rows.
	 *
	 * @param string   $xml     Sheet XML.
	 * @param string[] $strings Shared strings.
	 * @return array<int,array<int,string>>
	 */
	private static function parse_sheet( string $xml, array $strings ): array {
		$doc  = self::load_xml( $xml );
		$rows = array();
		$data = $doc->children( self::NS )->sheetData;
		if ( ! $data ) {
			return $rows;
		}
		foreach ( $data->children( self::NS )->row as $row ) {
			$cells = array();
			foreach ( $row->children( self::NS )->c as $c ) {
				$ref  = self::attr( $c, 'r' );
				$col  = self::column_index( $ref );
				$type = self::attr( $c, 't' );
				$kids = $c->children( self::NS );
				$v    = isset( $kids->v ) ? (string) $kids->v : '';

				if ( 's' === $type ) {
					$val = $strings[ (int) $v ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$val = isset( $kids->is ) ? self::string_item_text( $kids->is ) : '';
				} elseif ( 'b' === $type ) {
					$val = '1' === $v ? 'TRUE' : 'FALSE';
				} else {
					$val = $v;
				}
				$cells[ $col ] = trim( $val );
			}
			if ( ! $cells ) {
				continue;
			}
			$max  = max( array_keys( $cells ) );
			$line = array_fill( 0, $max + 1, '' );
			foreach ( $cells as $i => $val ) {
				$line[ $i ] = $val;
			}
			$rows[ (int) self::attr( $row, 'r' ) ] = $line;
		}
		return $rows;
	}

	/** Un-namespaced attribute (SimpleXML hides these after children( NS )). */
	private static function attr( \SimpleXMLElement $el, string $name ): string {
		$attrs = $el->attributes();
		return isset( $attrs[ $name ] ) ? (string) $attrs[ $name ] : '';
	}

	/** "C12" => 2. */
	private static function column_index( string $ref ): int {
		preg_match( '/^[A-Z]+/', $ref, $m );
		$letters = $m[0] ?? 'A';
		$n       = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}

	/**
	 * Parse XML safely (no network, no entity expansion).
	 *
	 * @throws \RuntimeException On invalid XML.
	 */
	private static function load_xml( string $xml ): \SimpleXMLElement {
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $doc ) {
			throw new \RuntimeException( 'Filen indeholder ugyldig XML.' );
		}
		return $doc;
	}

	/**
	 * Read a CSV file, guessing the delimiter and converting Windows-1252 to UTF-8.
	 *
	 * @return array<int,array<int,string>>
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function read_csv( string $path ): array {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			throw new \RuntimeException( 'CSV-filen kunne ikke læses.' );
		}
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		$first = strtok( $raw, "\n" );
		$delim = substr_count( (string) $first, ';' ) >= substr_count( (string) $first, ',' ) ? ';' : ',';

		$rows  = array();
		$n     = 0;
		$fh    = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		while ( ( $line = fgetcsv( $fh, 0, $delim, '"', '' ) ) !== false ) {
			++$n;
			if ( array( null ) === $line ) {
				continue;
			}
			$rows[ $n ] = array_map( fn( $v ) => trim( (string) $v ), $line );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}
}
