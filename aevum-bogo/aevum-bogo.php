<?php
/**
 * Plugin Name: Aevum Bio - Buy One Get One
 * Description: Adds a free matching unit for eligible products when a promo code is applied. Free units are added at zero cost and carry through cart, checkout and the order.
 * Version:     1.0.0
 * Author:      Anirudha Talmale
 * Requires PHP: 7.2
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Aevum_BOGO' ) ) {

final class Aevum_BOGO {

	const OPT_CODE      = 'aevum_bogo_code';
	const OPT_ITEMS     = 'aevum_bogo_items';
	const OPT_CAP       = 'aevum_bogo_cap';
	const FLAG          = '_aevum_bogo_free';
	const FLAG_PARENT   = '_aevum_bogo_for';

	/** Guards against re-entering the sync while we are mutating the cart. */
	private static $syncing = false;

	public static function init() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compat' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		}

		// The code is defined here rather than as a coupon post, so there is
		// nothing in Marketing > Coupons that can be edited into a real discount by accident.
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_coupon' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid',      array( __CLASS__, 'validate_coupon' ), 10, 2 );

		// Keep the free lines in step with the paid ones.
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_applied_coupon',           array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_removed_coupon',           array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_add_to_cart',              array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_cart_item_removed',        array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_before_checkout_process',  array( __CLASS__, 'sync' ), 20 );

		// Zero the price of the free lines on every totals pass.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_zero_price' ), 100 );

		// Presentation.
		add_filter( 'woocommerce_cart_item_name',          array( __CLASS__, 'item_name' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price',         array( __CLASS__, 'item_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_quantity',      array( __CLASS__, 'item_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link',   array( __CLASS__, 'item_remove_link' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( __CLASS__, 'coupon_totals_html' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_totals_label' ), 10, 2 );

		// Carry the marker onto the order so it shows on checkout, the thank-you page and the admin order.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 4 );
	}

	public static function declare_hpos_compat() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function code() {
		return strtolower( trim( (string) get_option( self::OPT_CODE, 'laborday' ) ) );
	}

	/** @return int[] product / variation IDs the offer applies to */
	public static function eligible_ids() {
		$ids = get_option( self::OPT_ITEMS, array() );
		if ( is_array( $ids ) && $ids ) {
			return array_map( 'absint', $ids );
		}
		// Nothing chosen on the settings screen yet, so fall back to the items
		// agreed for launch. Ticking anything on that screen overrides this.
		return self::default_ids();
	}

	/**
	 * The launch line-up, resolved by slug so it survives any change of product ID.
	 * Every size of a variable product is included.
	 */
	public static function default_ids() {
		$slugs = apply_filters( 'aevum_bogo_default_slugs', array( 'tesamorelin-10mg', 'ghk-cu', 'retatrutide' ) );
		$ids   = array();

		foreach ( $slugs as $slug ) {
			$post = get_page_by_path( $slug, OBJECT, 'product' );
			if ( ! $post ) {
				continue;
			}
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$ids[] = (int) $child_id;
				}
			} else {
				$ids[] = (int) $product->get_id();
			}
		}

		return $ids;
	}

	/** Maximum free units per eligible item, per order. 0 = uncapped. */
	public static function cap() {
		return absint( get_option( self::OPT_CAP, 1 ) );
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Buy One Get One',
			'Buy One Get One',
			'manage_woocommerce',
			'aevum-bogo',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'aevum_bogo', self::OPT_CODE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'aevum_bogo', self::OPT_CAP,  array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'aevum_bogo', self::OPT_ITEMS, array(
			'sanitize_callback' => function ( $value ) {
				return array_values( array_unique( array_map( 'absint', (array) $value ) ) );
			},
		) );
	}

	public static function settings_page() {
		$selected = self::eligible_ids();
		?>
		<div class="wrap">
			<h1>Buy One Get One</h1>
			<p>When a customer applies the promo code below, a second unit of each ticked item is added to their cart free of charge.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'aevum_bogo' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aevum_bogo_code">Promo code</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPT_CODE ); ?>" id="aevum_bogo_code" type="text"
								   value="<?php echo esc_attr( self::code() ); ?>" class="regular-text" />
							<p class="description">The code the customer types at the cart or checkout. Case does not matter.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aevum_bogo_cap">Free units per item, per order</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPT_CAP ); ?>" id="aevum_bogo_cap" type="number" min="0" step="1"
								   value="<?php echo esc_attr( self::cap() ); ?>" class="small-text" />
							<p class="description">Set to 0 for no cap, which means every paid unit earns a free one.</p>
						</td>
					</tr>
				</table>

				<h2>Items included in the offer</h2>
				<p>Tick every product or size the offer should apply to. Anything left unticked is unaffected.</p>
				<?php self::render_item_checkboxes( $selected ); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_item_checkboxes( $selected ) {
		$products = wc_get_products( array(
			'limit'   => -1,
			'status'  => 'publish',
			'orderby' => 'name',
			'order'   => 'ASC',
		) );

		echo '<table class="widefat striped" style="max-width:820px">';
		echo '<thead><tr><th style="width:60px">Include</th><th>Item</th><th style="width:120px">Price</th></tr></thead><tbody>';

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( ! $child ) {
						continue;
					}
					self::render_row( $child_id, $child->get_formatted_name(), $child->get_price_html(), $selected );
				}
			} else {
				self::render_row( $product->get_id(), $product->get_name(), $product->get_price_html(), $selected );
			}
		}

		echo '</tbody></table>';
	}

	private static function render_row( $id, $label, $price_html, $selected ) {
		printf(
			'<tr><td style="text-align:center"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s /></td><td>%4$s <span style="color:#888">(#%2$d)</span></td><td>%5$s</td></tr>',
			esc_attr( self::OPT_ITEMS ),
			absint( $id ),
			checked( in_array( (int) $id, $selected, true ), true, false ),
			esc_html( wp_strip_all_tags( $label ) ),
			wp_kses_post( $price_html )
		);
	}

	/* ---------------------------------------------------------------------
	 * Cart logic
	 * ------------------------------------------------------------------ */

	/**
	 * Defines the promo code on the fly. Zero discount by itself - the saving is the free unit.
	 */
	public static function virtual_coupon( $data, $code ) {
		if ( strtolower( $code ) !== self::code() || '' === self::code() ) {
			return $data;
		}
		return array(
			'id'                 => -1,
			'amount'             => 0,
			'discount_type'      => 'fixed_cart',
			'individual_use'     => false,
			'usage_limit'        => 0,
			'free_shipping'      => false,
			'exclude_sale_items' => false,
		);
	}

	/**
	 * The code only makes sense when there is something in the cart it applies to.
	 */
	public static function validate_coupon( $valid, $coupon ) {
		if ( strtolower( $coupon->get_code() ) !== self::code() ) {
			return $valid;
		}
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return $valid;
		}

		$eligible = self::eligible_ids();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_free_line( $item ) ) {
				continue;
			}
			$id = $item['variation_id'] ? (int) $item['variation_id'] : (int) $item['product_id'];
			if ( in_array( $id, $eligible, true ) ) {
				return true;
			}
		}

		throw new Exception( 'This code applies to selected items only. Add one of the qualifying products to your cart to use it.' );
	}

	private static function coupon_applied( $cart ) {
		$code = self::code();
		if ( '' === $code ) {
			return false;
		}
		foreach ( (array) $cart->get_applied_coupons() as $applied ) {
			if ( strtolower( $applied ) === $code ) {
				return true;
			}
		}
		return false;
	}

	private static function is_free_line( $item ) {
		return ! empty( $item[ self::FLAG ] );
	}

	/**
	 * Adds, trims or removes the free lines so they match the paid lines.
	 */
	public static function sync() {
		if ( self::$syncing || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! $cart->get_cart_contents() && ! self::coupon_applied( $cart ) ) {
			return;
		}

		self::$syncing = true;

		$active   = self::coupon_applied( $cart );
		$eligible = self::eligible_ids();
		$cap      = self::cap();

		// What is in the cart right now, paid vs free, per item.
		$paid = array();
		$free = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$id = $item['variation_id'] ? (int) $item['variation_id'] : (int) $item['product_id'];
			if ( self::is_free_line( $item ) ) {
				$free[ $id ] = array( 'key' => $key, 'qty' => (int) $item['quantity'] );
			} else {
				$paid[ $id ] = ( isset( $paid[ $id ] ) ? $paid[ $id ] : 0 ) + (int) $item['quantity'];
			}
		}

		// Drop any free line that no longer belongs: code removed, item removed,
		// item no longer in the offer, or a leftover from an earlier settings change.
		foreach ( $free as $id => $line ) {
			$wanted = ( $active && in_array( $id, $eligible, true ) ) ? self::free_qty_for( $paid, $id, $cap ) : 0;
			if ( $wanted < 1 ) {
				$cart->remove_cart_item( $line['key'] );
				unset( $free[ $id ] );
			} elseif ( $wanted !== $line['qty'] ) {
				$cart->set_quantity( $line['key'], $wanted, false );
				$free[ $id ]['qty'] = $wanted;
			}
		}

		// Add the free lines that are missing.
		if ( $active ) {
			foreach ( $paid as $id => $qty ) {
				if ( ! in_array( $id, $eligible, true ) || isset( $free[ $id ] ) ) {
					continue;
				}
				$wanted = self::free_qty_for( $paid, $id, $cap );
				if ( $wanted < 1 ) {
					continue;
				}
				self::add_free_unit( $id, $wanted );
			}
		}

		self::$syncing = false;
	}

	private static function free_qty_for( $paid, $id, $cap ) {
		$qty = isset( $paid[ $id ] ) ? (int) $paid[ $id ] : 0;
		if ( $qty < 1 ) {
			return 0;
		}
		return $cap > 0 ? min( $qty, $cap ) : $qty;
	}

	private static function add_free_unit( $id, $qty ) {
		$product = wc_get_product( $id );
		if ( ! $product || ! $product->is_purchasable() ) {
			return;
		}

		// A free unit still has to be in stock, otherwise we would oversell.
		if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $qty ) ) {
			return;
		}

		$parent_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $id;
		$variation_id = $product->is_type( 'variation' ) ? $id : 0;
		$attributes   = $variation_id ? $product->get_variation_attributes() : array();

		WC()->cart->add_to_cart(
			$parent_id,
			$qty,
			$variation_id,
			$attributes,
			array(
				self::FLAG        => true,
				self::FLAG_PARENT => $id,
			)
		);
	}

	/**
	 * Free lines cost nothing. Runs on every totals pass so nothing can knock it out.
	 */
	public static function apply_zero_price( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_free_line( $item ) && isset( $item['data'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( 0 );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Presentation
	 * ------------------------------------------------------------------ */

	public static function item_name( $name, $item, $key ) {
		if ( self::is_free_line( $item ) ) {
			$name .= ' <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:3px;background:#1f7a34;color:#fff;font-size:11px;letter-spacing:.03em">FREE</span>';
		}
		return $name;
	}

	public static function item_price( $price, $item, $key ) {
		if ( ! self::is_free_line( $item ) ) {
			return $price;
		}
		$product = wc_get_product( isset( $item[ self::FLAG_PARENT ] ) ? $item[ self::FLAG_PARENT ] : 0 );
		$was     = $product ? (float) wc_get_price_to_display( $product ) : 0;

		return ( $was > 0 ? '<del>' . wp_kses_post( wc_price( $was ) ) . '</del> ' : '' )
			. '<ins style="text-decoration:none">' . wp_kses_post( wc_price( 0 ) ) . '</ins>';
	}

	public static function item_quantity( $html, $key, $item ) {
		if ( self::is_free_line( $item ) ) {
			return esc_html( $item['quantity'] );
		}
		return $html;
	}

	public static function item_remove_link( $link, $key ) {
		$cart = WC()->cart->get_cart();
		if ( isset( $cart[ $key ] ) && self::is_free_line( $cart[ $key ] ) ) {
			return '';
		}
		return $link;
	}

	/**
	 * The saving is the free unit itself, so the totals row would otherwise read
	 * a confusing "-$0.00". Show what the customer actually got instead.
	 */
	public static function coupon_totals_html( $html, $coupon ) {
		if ( strtolower( $coupon->get_code() ) !== self::code() ) {
			return $html;
		}

		$units = 0;
		if ( function_exists( 'WC' ) && ! is_null( WC()->cart ) ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_free_line( $item ) ) {
					$units += (int) $item['quantity'];
				}
			}
		}

		// No figure here on purpose: the free unit is already priced at zero in the
		// lines above, so a negative amount in this row would not add up.
		$text   = $units > 0 ? sprintf( '%d free %s added', $units, 1 === $units ? 'unit' : 'units' ) : 'No qualifying item yet';
		$remove = '<a href="' . esc_url( add_query_arg( 'remove_coupon', rawurlencode( $coupon->get_code() ), wc_get_cart_url() ) ) . '" class="woocommerce-remove-coupon" data-coupon="' . esc_attr( $coupon->get_code() ) . '">[Remove]</a>';

		return esc_html( $text ) . ' ' . $remove;
	}

	public static function coupon_totals_label( $label, $coupon ) {
		if ( strtolower( $coupon->get_code() ) !== self::code() ) {
			return $label;
		}
		return 'Buy one get one (' . esc_html( strtoupper( $coupon->get_code() ) ) . ')';
	}

	public static function order_line_item( $line_item, $key, $values, $order ) {
		if ( self::is_free_line( $values ) ) {
			$line_item->add_meta_data( 'Offer', 'Buy One Get One - free unit', true );
		}
	}
}

add_action( 'plugins_loaded', array( 'Aevum_BOGO', 'init' ) );

}
