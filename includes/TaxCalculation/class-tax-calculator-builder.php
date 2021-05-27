<?php
/**
 * Tax Calculator Builder
 *
 * @package TaxJar\TaxCalculation
 */

namespace TaxJar;

use WC_Taxjar_Nexus;
use TaxJar_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tax_Calculator_Builder
 */
class Tax_Calculator_Builder {

	/**
	 * Tax Calculator
	 *
	 * @var Tax_Calculator
	 */
	private $calculator;

	/**
	 * Tax_Calculator_Builder constructor.
	 */
	public function __construct() {
		$this->calculator = new Tax_Calculator();
		$this->set_tax_cache();
		$this->set_tax_client();
	}

	/**
	 * Builds order tax calculator.
	 *
	 * @param bool     $should_calculate Whether or not tax should be calculated on the order.
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @return Tax_Calculator
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	public function build_order_calculator( $should_calculate, $order ) {
		if ( $should_calculate ) {
			if ( $this->is_rest_request() ) {
				$this->maybe_setup_api_tax_calculator( $order );
			} else {
				$this->setup_order_calculator( $order );
			}
		} else {
			$this->maybe_setup_admin_order_calculator( $order );
		}

		return $this->calculator;
	}

	/**
	 * Check if WordPress is handling a request to its REST API.
	 *
	 * @return bool
	 */
	private function is_rest_request() {
		return Constants_Manager::is_true( 'REST_REQUEST' );
	}

	/**
	 * Builds tax calculator for an API order if the TaxJar API Tax Calculation setting is enabled.
	 *
	 * @param WC_Order $order Order create or update through WooCommerce REST API to calculate tax on.
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	private function maybe_setup_api_tax_calculator( $order ) {
		if ( ! $this->is_api_tax_calculation_enabled() ) {
			$this->calculator = false;
		} else {
			$this->setup_api_tax_calculator( $order );
		}
	}

	/**
	 * Checks if TaxJar API Tax Calculation setting is enabled.
	 *
	 * @return bool
	 */
	private function is_api_tax_calculation_enabled() {
		$settings = TaxJar_Settings::get_taxjar_settings();
		return isset( $settings['api_calcs_enabled'] ) && 'yes' === $settings['api_calcs_enabled'];
	}

	/**
	 * Builds tax calculator for API order.
	 *
	 * @param WC_Order $order Order created or update through WooCommerce REST API.
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	private function setup_api_tax_calculator( $order ) {
		$this->set_order_logger( $order );
		$this->set_order_tax_request_body_builder( $order );
		$this->set_order_applicator( $order );
		$this->set_order_validator( $order );
		$this->set_context( 'api_order' );
	}

	/**
	 * Builds order tax calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	private function setup_order_calculator( $order ) {
		$this->set_order_logger( $order );
		$this->set_order_tax_request_body_builder( $order );
		$this->set_order_applicator( $order );
		$this->set_order_validator( $order );
		$this->set_context( 'order' );
	}

	/**
	 * Sets the logger for order calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @throws \Exception When logger is not instance of Logger class.
	 */
	private function set_order_logger( $order ) {
		$wc_logger = wc_get_logger();
		$this->calculator->set_logger( new Order_Calculation_Logger( $wc_logger, $order ) );
	}

	/**
	 * Sets the cache for a calculator.
	 *
	 * @throws \Exception When cache does not implement Cache_Interface.
	 */
	private function set_tax_cache() {
		$this->calculator->set_cache( new Cache( HOUR_IN_SECONDS, 'tj_tax_' ) );
	}

	/**
	 * Sets tax request body builder for order calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @throws \Exception When request body builder is not instance of Tax_Request_Body_Builder.
	 */
	private function set_order_tax_request_body_builder( $order ) {
		$this->calculator->set_request_body_builder( new Order_Tax_Request_Body_Builder( $order ) );
	}

	/**
	 * Set tax client for calculator.
	 *
	 * @throws \Exception When tax client does not implement Tax_Client_Interface.
	 */
	private function set_tax_client() {
		$this->calculator->set_tax_client( new Tax_Client() );
	}

	/**
	 * Set tax applicator for order calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @throws \Exception When tax applicator is not instance of Tax_Applicator_Interface.
	 */
	private function set_order_applicator( $order ) {
		$this->calculator->set_applicator( new Order_Tax_Applicator( $order ) );
	}

	/**
	 * Sets validator for order calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @throws \Exception When validator does not implement Tax_Calculation_Validator_Interface.
	 */
	private function set_order_validator( $order ) {
		$nexus = new WC_Taxjar_Nexus();
		$this->calculator->set_validator( new Order_Tax_Calculation_Validator( $order, $nexus ) );
	}

	/**
	 * Sets the context of the tax calculation.
	 *
	 * @param string $context Context calculation is occurring in.
	 */
	private function set_context( $context ) {
		$this->calculator->set_context( $context );
	}

	/**
	 * Builds an admin order calculator if creating or editing order through admin dashboard.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	private function maybe_setup_admin_order_calculator( $order ) {
		if ( $this->is_doing_ajax_method_that_needs_tax_calculation() ) {
			$this->setup_admin_order_calculator( $order );
		} else {
			$this->calculator = false;
		}
	}

	/**
	 * Checks if execution is inside an ajax call from the admin dashboard that needs tax to be calculated on an order.
	 *
	 * @return bool
	 */
	private function is_doing_ajax_method_that_needs_tax_calculation() {
		return wp_doing_ajax() && $this->should_calculate_tax_for_action();
	}

	/**
	 * Checks if ajax action needs tax calculation and that nonce is valid.
	 *
	 * @return bool
	 */
	private function should_calculate_tax_for_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['action'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_text_field( wp_unslash( $_POST['action'] ) );

		if ( $this->action_needs_calculation( $action ) && $this->is_nonce_valid( $action ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if ajax action needs tax calculation.
	 *
	 * @param string $action AJAX action.
	 *
	 * @return bool
	 */
	private function action_needs_calculation( $action ) {
		return array_key_exists( $action, $this->get_actions_that_need_calculation() );
	}

	/**
	 * Get all actions that need tax calculation and their respective nonce keys.
	 *
	 * @return array
	 */
	private function get_actions_that_need_calculation() {
		return array(
			'woocommerce_add_order_fee'       => 'order-item',
			'woocommerce_add_coupon_discount' => 'order-item',
			'woocommerce_remove_order_coupon' => 'order-item',
			'woocommerce_remove_order_item'   => 'order-item',
			'woocommerce_calc_line_taxes'     => 'calc-totals',
		);
	}

	/**
	 * Check if nonce is valid.
	 *
	 * @param string $action AJAX action.
	 *
	 * @return bool|int
	 */
	private function is_nonce_valid( $action ) {
		$actions          = $this->get_actions_that_need_calculation();
		$action_nonce_key = $actions[ $action ];
		return check_ajax_referer( $action_nonce_key, 'security', false );
	}

	/**
	 * Build admin order calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	private function setup_admin_order_calculator( $order ) {
		$this->set_order_logger( $order );
		$this->set_admin_order_tax_request_body_builder( $order );
		$this->set_order_applicator( $order );
		$this->set_order_validator( $order );
		$this->set_context( 'admin_order' );
	}

	/**
	 * Sets request body builder for admin order calculator.
	 *
	 * @param WC_Order $order Order that needs tax calculation.
	 *
	 * @throws \Exception When request body builder is not instance of Tax_Request_Body_Builder.
	 */
	private function set_admin_order_tax_request_body_builder( $order ) {
		$this->calculator->set_request_body_builder( new Admin_Order_Tax_Request_Body_Builder( $order ) );
	}

	/**
	 * Build subscription order calculator.
	 *
	 * @param WC_Subscription $subscription Subscription that needs tax calculation.
	 *
	 * @return Tax_Calculator
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	public function build_subscription_order_calculator( $subscription ) {
		$this->set_order_logger( $subscription );
		$this->set_order_tax_request_body_builder( $subscription );
		$this->set_order_applicator( $subscription );
		$this->set_order_validator( $subscription );
		$this->set_context( 'subscription_order' );
		return $this->calculator;
	}

	/**
	 * Build renewal order calculator.
	 *
	 * @param WC_Order $renewal Renewal order that needs tax calculation.
	 *
	 * @return Tax_Calculator
	 * @throws \Exception When calculator is built using invalid objects.
	 */
	public function build_renewal_order_calculator( $renewal ) {
		$this->set_order_logger( $renewal );
		$this->set_order_tax_request_body_builder( $renewal );
		$this->set_order_applicator( $renewal );
		$this->set_order_validator( $renewal );
		$this->set_context( 'renewal_order' );
		return $this->calculator;
	}
}

