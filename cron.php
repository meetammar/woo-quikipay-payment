<?php

// expired_post_delete hook fires when the Cron is executed
add_action( 'delete_on_hold_orders', 'wc_on_hold_orders_delete_callback' );

// This function will run once the 'expired_post_delete' is called
function wc_on_hold_orders_delete_callback() {

    $args = array(
        'status' => 'on-hold',
        'limit' => -1
    );
    $orders = wc_get_orders( $args );
    foreach( $orders as $order )
    {
        $order_id = $order->get_id();
        $date_created_dt = $order->get_date_created(); // Get order date created WC_DateTime Object
        $timezone        = $date_created_dt->getTimezone(); // Get the timezone
        $date_created_ts = $date_created_dt->getTimestamp(); // Get the timestamp in seconds
    
        $now_dt = new WC_DateTime(); // Get current WC_DateTime object instance
        $now_dt->setTimezone( $timezone ); // Set the same time zone
        $now_ts = $now_dt->getTimestamp(); // Get the current timestamp in seconds

        $twenty_four_hours = 24 * 60 * 60; // 24hours in seconds

        $diff_in_seconds = $now_ts - $date_created_ts; // Get the difference (in seconds)

        if( $diff_in_seconds > $twenty_four_hours )
        {
            wp_delete_post($order_id,true);
        }
    
    }

}

// Add function to register event to WordPress init
add_action( 'init', 'register_daily_post_delete_event');
function register_daily_post_delete_event() {
    if( !wp_next_scheduled( 'delete_on_hold_orders' ) ) {
        wp_schedule_event( time(), 'daily', 'delete_on_hold_orders' );
    }
}