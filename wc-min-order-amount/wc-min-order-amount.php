<?php
/**
 * Plugin Name: WooCommerce Minimum Order Amount
 * Description: Enforce a minimum order subtotal with friendly notices. Configurable amount, apply after coupons, and role exclusions.
 * Version: 1.0.0
 * Author: Muhammad Ahmed
 * License: GPL-2.0-or-later
 * Text Domain: wc-min-order-amount
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WC_Min_Order_Amount' ) ) :

final class WC_Min_Order_Amount {
    const OPTION_KEY = 'wcmoa_options';

    public static function instance() {
        static $inst = null;
        if ( null === $inst ) { $inst = new self(); }
        return $inst;
    }

    private function __construct() {
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
        add_action( 'admin_menu',  [ $this, 'add_settings_page' ] );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'settings_link' ] );

        // Frontend checks
        add_action( 'woocommerce_before_cart',       [ $this, 'maybe_notice_on_cart' ] );
        add_action( 'woocommerce_check_cart_items',  [ $this, 'validate_on_checkout' ] );

        // Optional shortcode/banner
        add_shortcode( 'min_order_banner', [ $this, 'shortcode_banner' ] );
    }

    /** Defaults */
    public static function defaults() {
        return [
            'enabled'             => 1,
            'min_amount'          => 50,
            'apply_after_coupons' => 1,
            'exclude_roles'       => [ 'administrator', 'shop_manager' ],
            'notice_cart'         => 'Minimum order amount is {min}. Add {remaining} more to proceed.',
            'notice_block'        => 'Minimum order amount is {min}. Your current subtotal is {subtotal}.',
        ];
    }

    /** Options helper */
    public function options() {
        $opts = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $opts, self::defaults() );
    }

    /** Settings API */
    public function register_settings() {
        register_setting( self::OPTION_KEY, self::OPTION_KEY, [ $this, 'sanitize' ] );

        add_settings_section( 'wcmoa_main', __( 'Minimum Order Settings', 'wc-min-order-amount' ), function() {
            echo '<p>' . esc_html__( 'Set a minimum order subtotal. Buyers see a friendly notice in cart and are blocked at checkout until the threshold is met.', 'wc-min-order-amount' ) . '</p>';
        }, self::OPTION_KEY );

        add_settings_field( 'enabled', __( 'Enable', 'wc-min-order-amount' ), function() {
            $o = $this->options();
            printf( '<label><input type="checkbox" name="%1$s[enabled]" %2$s> %3$s</label>',
                esc_attr( self::OPTION_KEY ),
                checked( ! empty( $o['enabled'] ), true, false ),
                esc_html__( 'Enforce minimum order amount', 'wc-min-order-amount' )
            );
        }, self::OPTION_KEY, 'wcmoa_main' );

        add_settings_field( 'min_amount', __( 'Minimum amount', 'wc-min-order-amount' ), function() {
            $o = $this->options();
            printf( '<input type="number" step="0.01" min="0" name="%1$s[min_amount]" value="%2$s" class="small-text">',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $o['min_amount'] )
            );
        }, self::OPTION_KEY, 'wcmoa_main' );

        add_settings_field( 'apply_after_coupons', __( 'Apply after coupons', 'wc-min-order-amount' ), function() {
            $o = $this->options();
            printf( '<label><input type="checkbox" name="%1$s[apply_after_coupons]" %2$s> %3$s</label><p class="description">%4$s</p>',
                esc_attr( self::OPTION_KEY ),
                checked( ! empty( $o['apply_after_coupons'] ), true, false ),
                esc_html__( 'Compare threshold against subtotal after coupon discounts', 'wc-min-order-amount' ),
                esc_html__( 'Taxes and shipping are not counted.', 'wc-min-order-amount' )
            );
        }, self::OPTION_KEY, 'wcmoa_main' );

        add_settings_field( 'exclude_roles', __( 'Exclude roles', 'wc-min-order-amount' ), function() {
            $o = $this->options();
            $roles = implode( ',', array_map( 'sanitize_text_field', (array) $o['exclude_roles'] ) );
            printf( '<input type="text" name="%1$s[exclude_roles]" value="%2$s" class="regular-text"> <span class="description">%3$s</span>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $roles ),
                esc_html__( 'Comma-separated. Example: administrator,shop_manager', 'wc-min-order-amount' )
            );
        }, self::OPTION_KEY, 'wcmoa_main' );

        add_settings_field( 'notice_cart', __( 'Cart notice', 'wc-min-order-amount' ), function() {
            $o = $this->options();
            printf( '<input type="text" name="%1$s[notice_cart]" value="%2$s" class="regular-text" /> <p class="description">%3$s</p>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $o['notice_cart'] ),
                esc_html__( 'Placeholders: {min}, {remaining}, {subtotal}', 'wc-min-order-amount' )
            );
        }, self::OPTION_KEY, 'wcmoa_main' );

        add_settings_field( 'notice_block', __( 'Checkout error', 'wc-min-order-amount' ), function() {
            $o = $this->options();
            printf( '<input type="text" name="%1$s[notice_block]" value="%2$s" class="regular-text" /> <p class="description">%3$s</p>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $o['notice_block'] ),
                esc_html__( 'Shown at checkout if below minimum. Placeholders: {min}, {subtotal}', 'wc-min-order-amount' )
            );
        }, self::OPTION_KEY, 'wcmoa_main' );
    }

    public function sanitize( $input ) {
        $out = self::defaults();
        $out['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;
        $out['min_amount'] = isset( $input['min_amount'] ) ? floatval( $input['min_amount'] ) : $out['min_amount'];
        $out['apply_after_coupons'] = ! empty( $input['apply_after_coupons'] ) ? 1 : 0;

        // roles
        $roles = [];
        if ( isset( $input['exclude_roles'] ) ) {
            $parts = array_map( 'trim', explode( ',', (string) $input['exclude_roles'] ) );
            foreach ( $parts as $r ) {
                if ( $r !== '' ) { $roles[] = sanitize_key( $r ); }
            }
        }
        $out['exclude_roles'] = $roles ? $roles : [];

        $out['notice_cart']  = sanitize_text_field( $input['notice_cart'] ?? $out['notice_cart'] );
        $out['notice_block'] = sanitize_text_field( $input['notice_block'] ?? $out['notice_block'] );
        return $out;
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Minimum Order Amount', 'wc-min-order-amount' ),
            __( 'Min Order Amount', 'wc-min-order-amount' ),
            'manage_woocommerce',
            self::OPTION_KEY,
            [ $this, 'render_settings_page' ]
        );
    }

    public function settings_link( $links ) {
        $url = admin_url( 'admin.php?page=' . self::OPTION_KEY );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wc-min-order-amount' ) . '</a>';
        return $links;
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WooCommerce Minimum Order Amount', 'wc-min-order-amount' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( self::OPTION_KEY );
                    do_settings_sections( self::OPTION_KEY );
                    submit_button();
                ?>
            </form>
            <p><code>[min_order_banner]</code> — <?php esc_html_e( 'Shortcode to show a live banner in cart/checkout pages.', 'wc-min-order-amount' ); ?></p>
        </div>
        <?php
    }

    /** Helpers */
    private function user_is_excluded() {
        $o = $this->options();
        if ( empty( $o['exclude_roles'] ) ) return false;
        if ( ! is_user_logged_in() ) return false;

        $user = wp_get_current_user();
        foreach ( (array) $o['exclude_roles'] as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) return true;
        }
        return false;
    }

    private function cart_amount_to_compare() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return 0;
        $subtotal = (float) WC()->cart->get_subtotal(); // excl tax, before coupons
        $o = $this->options();
        if ( ! empty( $o['apply_after_coupons'] ) ) {
            $subtotal -= (float) WC()->cart->get_discount_total(); // excl tax
        }
        return max( 0, $subtotal );
    }

    private function placeholders( $template, $subtotal, $min ) {
        $remaining = max( 0, $min - $subtotal );
        $repl = [
            '{min}'      => wc_price( $min ),
            '{remaining}'=> wc_price( $remaining ),
            '{subtotal}' => wc_price( $subtotal ),
        ];
        return strtr( $template, $repl );
    }

    /** Frontend notices */
    public function maybe_notice_on_cart() {
        $o = $this->options();
        if ( empty( $o['enabled'] ) || $this->user_is_excluded() ) return;
        $min = (float) $o['min_amount'];
        if ( $min <= 0 ) return;

        $subtotal = $this->cart_amount_to_compare();
        if ( $subtotal < $min ) {
            $msg = $this->placeholders( $o['notice_cart'], $subtotal, $min );
            wc_print_notice( wp_kses_post( $msg ), 'notice' );
        }
    }

    public function validate_on_checkout() {
        $o = $this->options();
        if ( empty( $o['enabled'] ) || $this->user_is_excluded() ) return;

        $min = (float) $o['min_amount'];
        if ( $min <= 0 ) return;

        $subtotal = $this->cart_amount_to_compare();
        if ( $subtotal < $min ) {
            $msg = $this->placeholders( $o['notice_block'], $subtotal, $min );
            wc_add_notice( wp_kses_post( $msg ), 'error' );
        }
    }

    /** Shortcode */
    public function shortcode_banner() {
        $o = $this->options();
        if ( empty( $o['enabled'] ) ) return '';
        $min      = (float) $o['min_amount'];
        $subtotal = $this->cart_amount_to_compare();
        $msg      = $this->placeholders( $o['notice_cart'], $subtotal, $min );
        return '<div class="wcmoa-banner" style="padding:8px 12px;background:#fff8e1;border:1px solid #f0e1a1;border-radius:6px;margin:8px 0;">' . wp_kses_post( $msg ) . '</div>';
    }
}

endif;

WC_Min_Order_Amount::instance();
