<?php
/**
 * Core Function 1: Process Order Payment
 * Reference source file for assignment citation.
 *
 * Scope:
 * - Checkout request registration
 * - Shipping information validation
 * - Cart item normalization
 * - Order creation
 * - Initial logistics record creation
 *
 * Note: This file is a separated reference version for GitHub upload.
 */

class Online_Business_Payment_Core {
    public static function init() {
        add_action('wp_ajax_business_checkout', array(__CLASS__, 'ajax_checkout'));
        add_action('wp_ajax_nopriv_business_checkout', array(__CLASS__, 'ajax_checkout'));
    }

    public static function generate_order_number() {
        return 'ORD-' . wp_date('Ymd') . '-' . wp_rand(1000, 9999);
    }

    public static function generate_tracking_number() {
        return 'TRK' . wp_date('ymd') . wp_rand(100000, 999999);
    }

    public static function get_carriers() {
        return array('SF Express', 'ZTO Express', 'YTO Express', 'Cainiao Logistics');
    }

    public static function build_tracking_payload($status, $estimated_delivery) {
        return array(
            'estimated_delivery' => $estimated_delivery,
            'timeline' => array(
                array(
                    'status' => $status ?: 'pending_shipment',
                    'label' => 'Pending Shipment',
                    'note' => 'Order confirmed and waiting for warehouse dispatch.',
                    'time' => current_time('mysql'),
                ),
                array(
                    'status' => 'order_paid',
                    'label' => 'Order Paid',
                    'note' => 'Payment completed and order created successfully.',
                    'time' => current_time('mysql'),
                ),
            ),
        );
    }

    public static function ajax_checkout() {
        check_ajax_referer('business_nonce', 'nonce');

        $cart = isset($_POST['cart']) ? json_decode(stripslashes((string) $_POST['cart']), true) : array();
        $shipping_name = sanitize_text_field(wp_unslash($_POST['shipping_name'] ?? ''));
        $shipping_address = sanitize_textarea_field(wp_unslash($_POST['shipping_address'] ?? ''));
        $shipping_email = sanitize_email(wp_unslash($_POST['shipping_email'] ?? ''));
        $shipping_phone = sanitize_text_field(wp_unslash($_POST['shipping_phone'] ?? ''));
        $payment_method = sanitize_text_field(wp_unslash($_POST['payment_method'] ?? 'wechat'));

        if (!is_array($cart) || empty($cart)) {
            wp_send_json_error(array('message' => 'Your cart is empty.'));
        }

        if (!$shipping_name || !$shipping_address || !$shipping_email) {
            wp_send_json_error(array('message' => 'Please complete the required shipping information.'));
        }

        if (!is_email($shipping_email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
        }

        $normalized_items = array();
        $total = 0;

        foreach ($cart as $item) {
            $id = intval($item['id'] ?? 0);
            $name = sanitize_text_field($item['name'] ?? 'Product');
            $price = floatval($item['price'] ?? 0);
            $qty = max(1, intval($item['qty'] ?? 1));

            if ($price <= 0) {
                continue;
            }

            $subtotal = $price * $qty;
            $total += $subtotal;
            $normalized_items[] = array(
                'id' => $id,
                'name' => $name,
                'price' => $price,
                'qty' => $qty,
                'subtotal' => $subtotal,
            );
        }

        if (empty($normalized_items)) {
            wp_send_json_error(array('message' => 'No valid items were found in the cart.'));
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'business_orders';
        $tracking_table = $wpdb->prefix . 'business_tracking';
        $user_id = get_current_user_id() ?: 0;
        $order_number = self::generate_order_number();
        $carrier_list = self::get_carriers();
        $carrier = $carrier_list[array_rand($carrier_list)];
        $tracking_no = self::generate_tracking_number();
        $estimated_delivery = wp_date('M j, Y', strtotime('+5 days', current_time('timestamp')));

        $order_payload = array(
            'items' => $normalized_items,
            'shipping' => array(
                'name' => $shipping_name,
                'address' => $shipping_address,
                'email' => $shipping_email,
                'phone' => $shipping_phone,
            ),
            'payment_method' => $payment_method,
            'summary' => array(
                'item_count' => array_sum(wp_list_pluck($normalized_items, 'qty')),
                'currency' => 'CNY',
            ),
        );

        $order_inserted = $wpdb->insert($orders_table, array(
            'user_id' => $user_id,
            'order_number' => $order_number,
            'items' => wp_json_encode($order_payload),
            'total' => $total,
            'currency' => 'CNY',
            'pay_method' => $payment_method,
            'pay_status' => 'paid',
        ));

        if (!$order_inserted) {
            wp_send_json_error(array('message' => 'Unable to create the order right now.'));
        }

        $order_id = intval($wpdb->insert_id);
        $tracking_payload = self::build_tracking_payload('pending_shipment', $estimated_delivery);

        $wpdb->insert($tracking_table, array(
            'order_id' => $order_id,
            'carrier' => $carrier,
            'tracking_no' => $tracking_no,
            'status' => 'pending_shipment',
            'events' => wp_json_encode($tracking_payload),
            'anomaly' => 0,
            'anomaly_note' => '',
        ));

        wp_send_json_success(array(
            'message' => 'Order placed successfully.',
            'order_number' => $order_number,
            'status' => 'Pending Shipment',
            'carrier' => $carrier,
            'tracking_no' => $tracking_no,
            'estimated_delivery' => $estimated_delivery,
        ));
    }
}