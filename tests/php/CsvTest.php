<?php
/**
 * CSV helpers.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Transfer\Csv;

/**
 * @covers \Clicklet\Transfer\Csv
 */
class CsvTest extends TestCase {

	/**
	 * File types are recognized from their headers; the most specific wins.
	 */
	public function test_detect_type() {
		$this->assertSame( 'links', Csv::detect_type( Csv::COLUMNS['links'] ) );
		$this->assertSame( 'stats', Csv::detect_type( Csv::COLUMNS['stats'] ) );
		$this->assertSame( 'clicks', Csv::detect_type( Csv::COLUMNS['clicks'] ) );
		$this->assertSame( 'links', Csv::detect_type( [ 'target_url' ] ) );
		$this->assertNull( Csv::detect_type( [ 'foo', 'bar' ] ) );
	}

	/**
	 * Semicolons (Excel in many locales) are detected.
	 */
	public function test_detect_delimiter() {
		$this->assertSame( ',', Csv::detect_delimiter( 'slug,target_url,title' ) );
		$this->assertSame( ';', Csv::detect_delimiter( 'slug;target_url;title' ) );
		$this->assertSame( ',', Csv::detect_delimiter( 'target_url' ) );
	}

	/**
	 * Header cells are trimmed and lowercased, and the BOM is removed.
	 */
	public function test_normalize_header() {
		$this->assertSame( [ 'slug', 'target_url' ], Csv::normalize_header( [ Csv::BOM . 'Slug', ' Target_URL ' ] ) );
	}

	/**
	 * Formula-like cells are escaped and restored; numbers are untouched.
	 */
	public function test_escape_round_trip() {
		foreach ( [ '=1+1', '+cmd', '-x', '@SUM(A1)', 'plain', '', '-5', '+3.2', "'quoted" ] as $value ) {
			$this->assertSame( $value, Csv::unescape( Csv::escape( $value ) ), $value );
		}

		$this->assertSame( "'=1+1", Csv::escape( '=1+1' ) );
		$this->assertSame( '-5', Csv::escape( '-5' ) );
	}

	/**
	 * Boolean cells.
	 */
	public function test_to_bool() {
		foreach ( [ '1', 'true', 'YES', 'on' ] as $value ) {
			$this->assertTrue( Csv::to_bool( $value, false ), $value );
		}

		foreach ( [ '0', 'false', 'no', 'off', 'x' ] as $value ) {
			$this->assertFalse( Csv::to_bool( $value, true ), $value );
		}

		$this->assertTrue( Csv::to_bool( '', true ) );
		$this->assertFalse( Csv::to_bool( ' ', false ) );
	}
}
