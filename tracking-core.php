<?php
/**
 * Core Function 2: Track Order
 * Reference source file for assignment citation.
 *
 * Scope:
 * - Read current user's orders
 * - Join order data with logistics data
 * - Hide refunded orders from tracking page
 * - Decode order payload and tracking timeline
 *
 * Note: This file is a separated reference version for GitHub upload.
 */

class Online_Business_Tracking_Core {
    public static function get_status_labels() {
        return array(
            'pending_shipment' => 'Pending Shipment',
            'in_transit' => 'In Transit',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
        );
    }

    public static function get_status_label($status) {
        $labels = self::get_status_labels();
        return isset($labels[$status]) ? $labels[$status] : ucwords(str_replace('_', ' ', (string) $status));
    }

    public static function decode_order_payload($items_json) {
        $payload = json_decode((string) $items_json, true);
        return is_array($payload) ? $payload : array();
    }

    public static function decode_tracking_events($events_json) {
        $payload = json_decode((string) $events_json, true);
        return is_array($payload) ? $payload : array();
    }

    public static function get_user_orders($user_id, $args = array()) {
        global $wpdb;

        if (!$user_id) {
            return array();
        }

        $orders_table = $wpdb->prefix . 'business_orders';
        $tracking_table = $wpdb->prefix . 'business_tracking';
        $refunds_table = $wpdb->prefix . 'business_refunds';

        $defaults = array(
            'exclude_refunded' => false,
        );
        $args = wp_parse_args($args, $defaults);

        $refund_filter_sql = $args['exclude_refunded']
            ? " AND NOT EXISTS (SELECT 1 FROM {$refunds_table} rf WHERE rf.order_id = o.id)"
            : '';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, t.carrier, t.tracking_no, t.status AS tracking_status, t.events AS tracking_events, t.updated_at AS tracking_updated_at,
                    (SELECT COUNT(*) FROM {$refunds_table} r WHERE r.order_id = o.id) AS refund_count
             FROM {$orders_table} o
             LEFT JOIN {$tracking_table} t ON t.order_id = o.id
             WHERE o.user_id = %d{$refund_filter_sql}
             ORDER BY o.created_at DESC, o.id DESC",
            $user_id
        ));
    }

    public static function render_tracking_model($requested_order = '') {
        $user_id = get_current_user_id();
        $orders = $user_id ? self::get_user_orders($user_id, array('exclude_refunded' => true)) : array();
        $selected_order = null;

        if (!empty($orders)) {
            $selected_order = $orders[0];

            if ($requested_order) {
                foreach ($orders as $order) {
                    if ($order->order_number === $requested_order) {
                        $selected_order = $order;
                        break;
                    }
                }
            }
        }

        if (!$selected_order) {
            return array(
                'orders' => $orders,
                'selected_order' => null,
                'shipping' => array(),
                'timeline' => array(),
                'estimated_delivery' => '',
            );
        }

        $order_payload = self::decode_order_payload($selected_order->items);
        $tracking_payload = self::decode_tracking_events($selected_order->tracking_events);
        $timeline = !empty($tracking_payload['timeline']) && is_array($tracking_payload['timeline'])
            ? $tracking_payload['timeline']
            : array();
        $estimated_delivery = !empty($tracking_payload['estimated_delivery'])
            ? $tracking_payload['estimated_delivery']
            : wp_date('M j, Y', strtotime('+5 days', strtotime($selected_order->created_at)));
        $shipping = !empty($order_payload['shipping']) && is_array($order_payload['shipping'])
            ? $order_payload['shipping']
            : array();

        return array(
            'orders' => $orders,
            'selected_order' => $selected_order,
            'shipping' => $shipping,
            'timeline' => $timeline,
            'estimated_delivery' => $estimated_delivery,
            'status_label' => self::get_status_label($selected_order->tracking_status ?: 'pending_shipment'),
        );
    }
}