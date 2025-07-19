<?php
/**
 * Simple test cases for basic functionality.
 *
 * @package FOCUS
 */

class TestSimpleTest extends WP_UnitTestCase {

	public function test_simple_assertion() {
		$this->assertTrue( true );
	}

	public function test_rand_str_function() {
		if ( function_exists( 'rand_str' ) ) {
			$result = rand_str( 10 );
			$this->assertEquals( 10, strlen( $result ) );
		} else {
			$this->markTestSkipped( 'rand_str function not available' );
		}
	}
}