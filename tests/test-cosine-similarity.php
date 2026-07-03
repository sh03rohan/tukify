<?php
/**
 * Standalone unit test for Tuki_KB::cosine_similarity().
 *
 * Runs without a WordPress bootstrap: it defines ABSPATH (so the class file's
 * direct-access guard passes) and requires only the KB class. cosine_similarity()
 * is a pure static with no WordPress dependencies, so this exercises it directly.
 *
 * Run:  php tests/test-cosine-similarity.php
 * Exits 0 if all assertions pass, 1 otherwise.
 *
 * @package Tukify
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/class-tuki-kb.php';

$tests_failed = 0;

/**
 * Asserts two floats are equal within a small tolerance.
 *
 * @param float  $expected Expected value.
 * @param float  $actual   Actual value.
 * @param string $label    Test label.
 * @return void
 */
function tuki_assert_close( $expected, $actual, $label ) {
	global $tests_failed;

	$epsilon = 1e-9;

	if ( abs( $expected - $actual ) <= $epsilon ) {
		echo "  PASS  {$label} (= {$actual})\n";
		return;
	}

	$tests_failed++;
	echo "  FAIL  {$label}: expected {$expected}, got {$actual}\n";
}

echo "Tuki_KB::cosine_similarity()\n";

// 1) Identical vectors point the same way → 1.0.
tuki_assert_close( 1.0, Tuki_KB::cosine_similarity( array( 1, 2, 3 ), array( 1, 2, 3 ) ), 'identical vectors → 1' );

// 2) Orthogonal vectors → 0.0.
tuki_assert_close( 0.0, Tuki_KB::cosine_similarity( array( 1, 0 ), array( 0, 1 ) ), 'orthogonal vectors → 0' );

// 3) Opposite vectors → -1.0.
tuki_assert_close( -1.0, Tuki_KB::cosine_similarity( array( 1, 0 ), array( -1, 0 ) ), 'opposite vectors → -1' );

// 4) Two known vectors: [1,2,3]·[4,5,6] = 32, |a|=√14, |b|=√77
//    → 32 / (√14·√77) = 0.9746318461970762.
tuki_assert_close(
	0.9746318461970762,
	Tuki_KB::cosine_similarity( array( 1, 2, 3 ), array( 4, 5, 6 ) ),
	'known pair [1,2,3]·[4,5,6] → 0.97463…'
);

// 5) A zero vector has undefined direction → guarded to 0.0 (no divide-by-zero).
tuki_assert_close( 0.0, Tuki_KB::cosine_similarity( array( 0, 0 ), array( 1, 1 ) ), 'zero vector → 0 (guarded)' );

// 6) An empty vector → 0.0.
tuki_assert_close( 0.0, Tuki_KB::cosine_similarity( array(), array( 1, 2, 3 ) ), 'empty vector → 0' );

// 7) Mismatched lengths compare over the shared prefix: [1,2,3] vs [1,2] uses
//    [1,2]·[1,2] → 1.0.
tuki_assert_close( 1.0, Tuki_KB::cosine_similarity( array( 1, 2, 3 ), array( 1, 2 ) ), 'mismatched lengths use shared prefix → 1' );

echo "\n";

if ( $tests_failed > 0 ) {
	echo "{$tests_failed} test(s) FAILED\n";
	exit( 1 );
}

echo "All tests passed\n";
exit( 0 );
