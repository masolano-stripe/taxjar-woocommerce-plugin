<?php

namespace TaxJar;

use WC_Customer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tax_Calculation_Validator implements Tax_Calculation_Validator_Interface {

	private $order;
	private $nexus;

	public function __construct( $order, $nexus ) {
		$this->order = $order;
		$this->nexus = $nexus;
	}

	public function validate( $request_body ) {
		$request_body->validate();
		$this->validate_order_total_is_not_zero();
		$this->validate_vat_exemption( $request_body );
		$this->validate_order_has_nexus( $request_body );
		$this->filter_interrupt();
	}

	private function validate_order_total_is_not_zero() {
		if ( $this->get_order_subtotal() <= 0 ) {
			throw new Tax_Calculation_Exception(
				'order_subtotal_zero',
				__( 'Tax calculation is not necessary when order subtotal is zero.', 'taxjar' )
			);
		}
	}

	private function get_order_subtotal() {
		return $this->order->get_subtotal() + $this->order->get_total_fees() + floatval( $this->order->get_shipping_total() );
	}

	private function validate_vat_exemption( $request_body ) {
		if ( $this->is_order_vat_exempt() ) {
			throw new Tax_Calculation_Exception(
				'is_vat_exempt',
				__( 'Tax calculation is not performed if order is vat exempt.', 'taxjar' )
			);
		}

		if ( $this->is_customer_vat_exempt( $request_body ) ) {
			throw new Tax_Calculation_Exception(
				'is_vat_exempt',
				__( 'Tax calculation is not performed if customer is vat exempt.', 'taxjar' )
			);
		}
	}

	private function is_order_vat_exempt() {
		$vat_exemption = 'yes' === $this->order->get_meta( 'is_vat_exempt' );
		return apply_filters( 'woocommerce_order_is_vat_exempt', $vat_exemption, $this->order );
	}

	private function is_customer_vat_exempt( $request_body ) {
		$customer_id = intval( $request_body->get_customer_id() );
		if ( $customer_id > 0 ) {
			$customer = new WC_Customer( $customer_id );
			return $customer->is_vat_exempt();
		}
		
		return false;
	}

	private function validate_order_has_nexus( $request_body ) {
		if ( $this->is_out_of_nexus_areas( $request_body ) ) {
			throw new Tax_Calculation_Exception(
				'no_nexus',
				__( 'Order does not have nexus.', 'taxjar' )
			);
		}
	}

	private function is_out_of_nexus_areas( $request_body ) {
		return ! $this->nexus->has_nexus_check( $request_body->get_to_country(), $request_body->get_to_state() );
	}

	private function filter_interrupt() {
		$should_calculate = apply_filters( 'taxjar_should_calculate_order_tax', true, $this->order );
		if ( ! $should_calculate ) {
			throw new Tax_Calculation_Exception(
				'filter_interrupt',
				__( 'Tax calculation has been interrupted through a filter.', 'taxjar' )
			);
		}
	}

}