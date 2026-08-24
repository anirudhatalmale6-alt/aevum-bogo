<?php
/**
 * Exercises the BOGO cart logic directly, with no browser in the way.
 * Run with: wp eval-file cli_test.php
 */

wc_load_cart();

$GLOBALS['bogo_pass'] = 0;
$GLOBALS['bogo_fail'] = 0;

function check( $label, $ok, $detail = '' ) {
	// WP-CLI runs eval-file inside a function, so keep the counters in $GLOBALS.
	if ( $ok ) {
		$GLOBALS['bogo_pass']++;
		WP_CLI::log( "PASS  $label" . ( $detail ? "   $detail" : '' ) );
	} else {
		$GLOBALS['bogo_fail']++;
		WP_CLI::log( "FAIL  $label" . ( $detail ? "   $detail" : '' ) );
	}
}

function snapshot() {
	WC()->cart->calculate_totals();
	$lines = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$id   = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
		$prod = wc_get_product( $id );
		$lines[] = array(
			'id'   => (int) $id,
			'name' => $prod ? $prod->get_name() . ( $item['variation_id'] ? ' ' . implode( '/', $item['variation'] ) : '' ) : '?',
			'qty'  => (int) $item['quantity'],
			'free' => ! empty( $item['_aevum_bogo_free'] ),
			'line' => (float) $item['line_total'],
		);
	}
	return array( 'lines' => $lines, 'total' => (float) WC()->cart->get_total( 'edit' ) );
}

function show( $label ) {
	$s = snapshot();
	WP_CLI::log( "--- $label ---" );
	foreach ( $s['lines'] as $l ) {
		WP_CLI::log( sprintf( '    #%-4d %-28s qty %d  %s  line $%s', $l['id'], $l['name'], $l['qty'], $l['free'] ? 'FREE' : 'paid', number_format( $l['line'], 2 ) ) );
	}
	WP_CLI::log( '    TOTAL $' . number_format( $s['total'], 2 ) );
	return $s;
}

function free_qty( $s, $id ) {
	foreach ( $s['lines'] as $l ) {
		if ( $l['id'] === $id && $l['free'] ) {
			return $l['qty'];
		}
	}
	return 0;
}

$TESA = 10;   // Tesamorelin 10mg $80, eligible
$NAD  = 11;   // NAD+ 500mg $70, NOT eligible
$RETA_10 = 16; // $90, eligible
$RETA_20 = 17; // $165, eligible
$BPC_10  = 19; // $75, NOT eligible

WC()->cart->empty_cart();

/* 1. Eligible item, no code yet -> nothing free, nothing discounted. */
WC()->cart->add_to_cart( $TESA, 1 );
$s = show( '1. eligible item, no code' );
check( 'no free unit before the code is entered', free_qty( $s, $TESA ) === 0 );
check( 'total is the plain price, 80.00', abs( $s['total'] - 80 ) < 0.01, '$' . $s['total'] );

/* 2. Apply the code -> one free unit, total unchanged. */
WC()->cart->apply_coupon( 'laborday' );
$s = show( '2. code applied' );
check( 'one free unit added', free_qty( $s, $TESA ) === 1 );
check( 'free unit costs nothing, total still 80.00', abs( $s['total'] - 80 ) < 0.01, '$' . $s['total'] );

/* 3. Cap of 1: buying 3 still yields exactly 1 free. */
foreach ( WC()->cart->get_cart() as $key => $item ) {
	if ( empty( $item['_aevum_bogo_free'] ) && (int) $item['product_id'] === $TESA ) {
		WC()->cart->set_quantity( $key, 3 );
	}
}
$s = show( '3. paid qty 3, cap 1' );
check( 'cap holds at 1 free unit', free_qty( $s, $TESA ) === 1, 'free qty ' . free_qty( $s, $TESA ) );
check( 'total is 3 x 80 = 240.00', abs( $s['total'] - 240 ) < 0.01, '$' . $s['total'] );

/* 4. Uncapped: every paid unit earns one. */
update_option( 'aevum_bogo_cap', 0 );
do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );   // what the next page load does
WC()->cart->calculate_totals();
$s = show( '4. cap set to 0 (uncapped)' );
check( 'uncapped gives 3 free units', free_qty( $s, $TESA ) === 3, 'free qty ' . free_qty( $s, $TESA ) );
check( 'total still 240.00', abs( $s['total'] - 240 ) < 0.01, '$' . $s['total'] );
update_option( 'aevum_bogo_cap', 1 );
do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
WC()->cart->calculate_totals();

/* 5. An item outside the offer is untouched. */
WC()->cart->add_to_cart( $NAD, 1 );
$s = show( '5. ineligible product added' );
check( 'NAD+ gets no free unit', free_qty( $s, $NAD ) === 0 );
check( 'total is 240 + 70 = 310.00', abs( $s['total'] - 310 ) < 0.01, '$' . $s['total'] );

/* 6. Removing the code removes the free unit. */
WC()->cart->remove_coupon( 'laborday' );
WC()->cart->calculate_totals();
$s = show( '6. code removed' );
check( 'free unit disappears with the code', free_qty( $s, $TESA ) === 0 );
check( 'total unchanged at 310.00', abs( $s['total'] - 310 ) < 0.01, '$' . $s['total'] );

/* 7. Variations are handled per size. */
WC()->cart->empty_cart();
WC()->cart->add_to_cart( 15, 1, $RETA_10 );
WC()->cart->add_to_cart( 18, 1, $BPC_10 );
WC()->cart->apply_coupon( 'laborday' );
$s = show( '7. Retatrutide 10mg (in offer) + BPC 10mg (not in offer)' );
check( 'Retatrutide 10mg gets a free unit', free_qty( $s, $RETA_10 ) === 1 );
check( 'BPC-157 gets nothing', free_qty( $s, $BPC_10 ) === 0 );
check( 'total is 90 + 75 = 165.00', abs( $s['total'] - 165 ) < 0.01, '$' . $s['total'] );

/* 8. The other size of the same variable product also qualifies. */
WC()->cart->add_to_cart( 15, 1, $RETA_20 );
$s = show( '8. Retatrutide 20mg added' );
check( '20mg gets its own free unit', free_qty( $s, $RETA_20 ) === 1 );
check( '10mg free unit still there', free_qty( $s, $RETA_10 ) === 1 );
check( 'total is 90 + 165 + 75 = 330.00', abs( $s['total'] - 330 ) < 0.01, '$' . $s['total'] );

/* 9. The code is rejected when nothing in the cart qualifies. */
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $NAD, 1 );
$applied = WC()->cart->apply_coupon( 'laborday' );
WC()->cart->calculate_totals();
$s = show( '9. code with only an ineligible item' );
check( 'code refused when nothing qualifies', ! $applied && count( $s['lines'] ) === 1, $applied ? 'it applied' : 'refused' );

/* 10. A free line cannot itself earn another free line. */
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $TESA, 1 );
WC()->cart->apply_coupon( 'laborday' );
WC()->cart->calculate_totals();
WC()->cart->calculate_totals();
WC()->cart->calculate_totals();
$s = show( '10. three totals passes, no runaway' );
check( 'still exactly 2 lines after repeated recalculation', count( $s['lines'] ) === 2, count( $s['lines'] ) . ' lines' );
check( 'total still 80.00', abs( $s['total'] - 80 ) < 0.01, '$' . $s['total'] );

WC()->cart->empty_cart();
WP_CLI::log( '' );
WP_CLI::log( str_repeat( '=', 56 ) );
WP_CLI::log( sprintf( '%d passed, %d failed', $GLOBALS['bogo_pass'], $GLOBALS['bogo_fail'] ) );
if ( $GLOBALS['bogo_fail'] ) {
	WP_CLI::error( 'Some checks failed.' );
}
