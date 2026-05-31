<?php
/**
 * Core Function 3: Request Return / Refund
 * Reference source file for assignment citation.
 *
 * Scope:
 * - Read refundable orders for current user
 * - Submit refund request
 * - Prevent duplicated refund requests
 * - Hide refunded orders from the refund list
 *
 * Note: This file is a separated reference version for GitHub upload.
 */

class Online_Business_Refund_Core {
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
            "SELECT o.*, t.carrier, t.tracking_no, t.status AS tracking_status,
                    (SELECT COUNT(*) FROM {$refunds_table} r WHERE r.order_id = o.id) AS refund_count
             FROM {$orders_table} o
             LEFT JOIN {$tracking_table} t ON t.order_id = o.id
             WHERE o.user_id = %d{$refund_filter_sql}
             ORDER BY o.created_at DESC, o.id DESC",
            $user_id
        ));
    }

    public static function get_order_by_number_for_user($user_id, $order_number) {
        $orders = self::get_user_orders($user_id);

        foreach ($orders as $order) {
            if ($order->order_number === $order_number) {
                return $order;
            }
        }

        return null;
    }

    public static function submit_refund_request($post_data) {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return array(
                'success' => false,
                'message' => 'Please sign in before requesting a refund.',
            );
        }

        if (!isset($post_data['refund_nonce']) || !wp_verify_nonce($post_data['refund_nonce'], 'refund_submit')) {
            return array(
                'success' => false,
                'message' => 'Invalid refund request.',
            );
        }

        $order_number = sanitize_text_field(wp_unslash($post_data['refund_order_number'] ?? ''));
        $refund_type = sanitize_text_field(wp_unslash($post_data['refund_type'] ?? 'refund_only'));
        $reason = sanitize_text_field(wp_unslash($post_data['refund_reason'] ?? ''));
        $comments = sanitize_textarea_field(wp_unslash($post_data['refund_comments'] ?? ''));
        $evidence = sanitize_textarea_field(wp_unslash($post_data['refund_evidence'] ?? ''));
        $selected_order = self::get_order_by_number_for_user($user_id, $order_number);

        if (!$selected_order) {
            return array(
                'success' => false,
                'message' => 'Please choose a valid paid order.',
            );
        }

        if (!$reason) {
            return array(
                'success' => false,
                'message' => 'Please select a refund reason.',
            );
        }

        if (!empty($selected_order->refund_count)) {
            return array(
                'success' => false,
                'message' => 'This order already has a refund request and is no longer available here.',
            );
        }

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'business_refunds', array(
            'order_id' => $selected_order->id,
            'user_id' => $user_id,
            'refund_type' => $refund_type === 'return_refund' ? 'return_refund' : 'refund_only',
            'reason' => $reason . ($comments ? ' | ' . $comments : ''),
            'evidence' => $evidence,
            'status' => 'pending',
            'admin_note' => '',
        ));

        return array(
            'success' => true,
            'message' => 'Refund request submitted successfully. The order has been removed from the refundable list.',
        );
    }

    public static function get_refundable_orders() {
        $user_id = get_current_user_id();
        return $user_id ? self::get_user_orders($user_id, array('exclude_refunded' => true)) : array();
    }
}