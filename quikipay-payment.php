<?php

/**
 * Plugin Name:       Quikipay  Payment Gateway
 * Plugin URI:        http://quikipay.com/
 * Description:       Quikipay Payment Gateway for Woocommerce
 * Version:           1.0
 * Author:            QuikiPay
 * Author URI:        http://quikipay.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quikipay
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Check if Woocommerce plugin is Active to avoid Fatal Errors
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;


//Initiate Payment Gateway Class
add_action('plugins_loaded', 'wc_quikipay_gateway_init', 11);
function wc_quikipay_gateway_init()
{

    class WC_Quikipay_Gateway extends WC_Payment_Gateway
    {

        private $url;
        private $order_id;
        private $customer_email;
        private $redirect_url;
        private $key;

        public function __construct()
        {
            // Gateway ID
            $this->id = 'quikipay_payment';

            // Gateway Icon
            $this->icon = apply_filters('woocommerce_quikipay_icon', plugin_dir_url(__FILE__) .
                'assets/payment-img.png');

            $this->has_fields = false;

            // Gateway Title
            $this->title = 'Quikipay';

            // Gateway Description
            $this->description = 'Ahora puedes pagar en ARGENTINA, CHILE, PERU Y PANAMÁ, con Tarjetas locales de DEBITO, CREDITO, Transferencias Bancarias, Deposito en Efectivo y Cryptoactivos';

            //Initiate Form Fields
            $this->init_form_fields();

            // Initiate Settings
            $this->init_settings();

            $this->api_key =  $this->get_option('api_key');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));


            // Quikipay Request URL
            $this->url = 'https://dev.quikipay.com/api/payment';
        }

        public function init_form_fields()
        {
            $this->form_fields = apply_filters(
                'woo_quikipay_fields',
                array(
                    'enabled' => array(
                        'title' => __('Enabled/Disabled', 'quikipay'),
                        'type' => 'checkbox'
                    ),
                    'api_key' => array(
                        'title'       => 'API Key',
                        'type'        => 'text',
                        'description' => __('Quikipay API Key', 'quikipay'),
                    )

                )
            );
        }


        public function process_payment($order_id)
        {
            $products = array();
            $order = wc_get_order($order_id);
            $currency = $order->get_currency();
            $acceptable_currencies = ['USD', 'CLP', 'PEN', 'ARS'];


            foreach ($order->get_items() as $item_id => $item) {
                $product   = $item->get_product();
                $prouct_id = $item->get_product_id();
                $image = wp_get_attachment_image_src(get_post_thumbnail_id($prouct_id), 'single-post-thumbnail');
                $products[$prouct_id]['product_name'] = $item->get_name();
                $products[$prouct_id]['quantity'] = $item->get_quantity();
                $products[$prouct_id]['price'] =  $product->get_price();
                $products[$prouct_id]['image'] =  (isset($image[0])) ? $image[0] : '';
                $products[$prouct_id]['currency'] = $currency;
            }

            if (!in_array($currency, $acceptable_currencies)) {
                wc_add_notice("Current Currency Not Supported by Payment Gateway", 'error');
                return;
            }

            if ($order->get_total() < 6) {
                wc_add_notice("La orden mínima para hacer una compra debe ser de 6 dólares o el referente al cambio en tu moneda local", 'error');
                return;
            }

            $redirect = $this->get_return_url($order);
            $redirect_url = $this->quikipay_payment_response($order_id, $order, $redirect, $products);

            if (!empty($redirect_url)) {
                // Mark as on-hold (we're awaiting the payment)
                $order->update_status('on-hold', __('Awaiting Quikipay payment', 'quikipay'));

                // Reduce stock levels
                $order->reduce_order_stock();

                // Remove cart
                WC()->cart->empty_cart();



                // Return thankyou redirect
                return array(
                    'result'    => 'success',
                    'redirect'  => $redirect_url
                );
            }
        }



        public function quikipay_payment_response($order_id, $order, $redirect, $products)
        {
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $full_name = $first_name . ' ' . $last_name;

            $response = wp_remote_post(
                $this->url,
                array(
                    'body' => array(
                        'amount'         => $order->get_total(),
                        'currency'       => $order->get_currency(),
                        'customer_email' => $order->get_billing_email(),
                        'customer_name' => $full_name,
                        'order_id'       => $order_id,
                        'merchant'       =>  $this->api_key,
                        'site_url'       => home_url(),
                        'redirect'       => $redirect,
                        'products_data'  => json_encode($products)
                    )
                )
            );



            $response = json_decode(wp_remote_retrieve_body($response));

            if (isset($response->payment_url) && !empty($response->payment_url)) {
                return $response->payment_url;
            }
        }
    }
}

//Add Quikipay Gateway to Woocmmerce Gateways Array
function wc_quikipay_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Quikipay_Gateway';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'wc_quikipay_add_to_gateways');


add_action('rest_api_init', function () {
    register_rest_route('quikipay/v1', '/order/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'update_wc_quikipay_order',
    ));
});


function update_wc_quikipay_order(WP_REST_Request $request)
{

    $order_id = $request->get_param('id');
    $order = wc_get_order($order_id);
    $status = $request->get_param('status');

    if (!empty($order) && !empty($status)) {

        //   $payment_method = $request->get_param('payment_method');
        if (strtolower($status) == 'pending') {
            $order->update_status('processing', 'Quikipay Payment');
        }

        if (strtolower($status) == 'completed' || strtolower($status) == 'complete') {
            $order->update_status('completed', 'Quikipay Payment');
        }
    }
}

add_action('wp_enqueue_scripts' , 'qx_style_sheet_enq' );
function qx_style_sheet_enq()
{
    wp_enqueue_style( 'qx-style-css' , plugin_dir_url( __FILE__ ) . 'assets/quikistyle.css'  );
}

require_once(plugin_dir_path(__FILE__) . 'cron.php');
require_once(plugin_dir_path(__FILE__) . 'activation.php');
