<?php

class WC_CreditGenie_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * ID of plugin
     *
     * @var string
     */
    public $id = '';
    /**
     * Title plugins
     *
     * @var string
     */
    public $method_title = '';
    /**
     * Description of plugin
     *
     * @var string
     */
    public $method_description = '';
    /**
     * Whether we can add the checkout fiels
     *
     * @var bool
     */
    public $has_fields = false;
    /**
     * All fields mapping as appears in admin
     *
     * @var array
     */
    public $form_fields = [];

    /**
     * The public API key for routing verification
     *
     * @var string
     */
    private $api_key = '';
    /**
     * The private API key used to hash token
     *
     * @var string
     */
    private $api_token = '';
    /**
     * URL to submit to
     *
     * @var string
     */
    private $api_url = '';

    /**
     * Title of Plugin as appears from dashboard
     *
     * @var string
     */
    public $title = '';
    /**
     * Whether the plugin is enabled
     *
     * @var string
     */
    public $enabled = '';
    /**
     * Text that appears under fields to describe
     *
     * @var string
     */
    public $description = '';
    /**
     * This will handle incoming notifications on complete, fail, etc
     *
     * @var string
     */
    private $notify_url = '';
    /**
     * This will handle the redirects back to the site
     *
     * @var string
     */
    private $callback_url = '';

    static $instance = false;

    /**
     * Constructor to fetch fields and values saved by the user
     *
     * @since 1.0.0
     * @version 1.0.0
     */

    public static function init() {
        if ( ! self::$instance ) {
            self::$instance = new WC_CreditGenie_Payment_Gateway();
        }

        return self::$instance;
    }

    public function __construct(){
        $this->id = 'creditgenie';
        $this->method_title       = __( 'CreditGenie', 'woocommerce-creditgenie-payment-gateway' );
        $this->method_description = __( 'Pay with CreditGenie to finalize your payment plan and complete your purchase.', 'woocommerce-creditgenie-payment-gateway' );
        $this->has_fields         = function_exists('is_checkout_pay_page') ? is_checkout_pay_page() : is_page(woocommerce_get_page_id('pay'));
        $this->order_button_text = __( 'Place Order', 'woocommerce' );

        $this->init_form_fields();

        // Get setting values.
        $this->api_key      = $this->_sanitize_cart_data($this->get_option( 'api_key' ));
        $this->api_token    = $this->_sanitize_cart_data($this->get_option( 'api_token' ));
        $this->api_url      = $this->_sanitize_url_data($this->get_option( 'api_url' ));

        $this->enabled      = $this->get_option('enabled');
	    $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->callback_url   = add_query_arg( 'wc-api', 'WC_CreditGenie_Payment_Gateway', home_url( '/' ) );
        $this->notify_url = add_query_arg( 'wc-api','cg_payment_gateway', home_url( '/' ) );

        add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ]);

        add_filter( 'woocommerce_api_cg_payment_gateway', array( $this, 'check_cg_response' ));
        add_action( 'admin_notices', [ $this, 'creditgenie_apikeys_check' ] );
        add_action("woocommerce_order_status_changed", array( $this, 'creditgenie_handle_status_change' ) );
    }

    /**
     * Check if the API keys are set from admin.
     *
     * @since 1.0.0
     * @version 1.0.0
     */
    public function creditgenie_apikeys_check() {
        if ( ($this->api_key  == '') ||   ($this->api_token == '') ) {
            $admin_url = admin_url( 'admin.php?page=wc-settings&tab=checkout' );
            echo '<div class="notice notice-warning"><p>' . sprintf( __('CreditGenie is almost ready. Please set up your CreditGenie keys in Woocommerce -> Settings -> Checkout.', 'woocommerce-creditgenie-payment-gateway' ), $admin_url ) . '</p></div>';
        }
    }

    /**
     * Icon for the front end payment method list.
     *
     * @since 1.0.0
     * @version 1.0.0
     */
    public function get_icon() {

        $icon  = '<img style="max-width:100%;display: block;float: none;margin: auto;" src="' . ( plugin_dir_url( __FILE__ ) . 'res/images/creditGenieLogo.png' ) . '" alt="CreditGenie" />';
        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }

    /**
     * Get the API token provided by user
     *
     * @since 1.0.0
     * @version 1.0.0
     * @return string
     */
    public function get_cg_token(){
        $apiToken = $this->api_token;
        return $apiToken;
    }

    /**
     * All admin fields provided by user
     *
     * @since 1.0.0
     * @version 1.0.0
     * @return string
     */
    public function init_form_fields(){
        $this->form_fields = [
            'enabled' => [
                'title'          => __( 'Enable/Disable', 'woocommerce-creditgenie-payment-gateway' ),
                'type'           => 'checkbox',
                'label'          => __( 'Enable CreditGenie', 'woocommerce-creditgenie-payment-gateway' ),
                'default'        => 'yes'
            ],
            'title' => [
                'title'          => __( 'Title', 'woocommerce-creditgenie-payment-gateway' ),
                'type'           => 'text',
                'description'    => __( 'This controls the title which users sees during checkout', 'woocommerce-creditgenie-payment-gateway' ),
                'default'        => __( 'CreditGenie', 'woocommerce-creditgenie-payment-gateway' ),
                'desc_tip'       => true,
            ],
            'description' => [
                'title'           => __( 'Description', 'woocommerce-creditgenie-payment-gateway' ),
                'type'            => 'text',
                'description'     => __( 'This controls the description which users sees during checkout', 'woocommerce-creditgenie-payment-gateway' ),
                'default'         => __('You will be transferred to the CreditGenie site to complete your purchase.', 'woocommerce-creditgenie-payment-gateway' ),
                'desc_tip'        => true,
            ],
            'api_url' => [
                'title'           => __( 'API URL', 'woocommerce-creditgenie-payment-gateway' ),
                'type'            => 'text',
                'description'     => __( 'Get your API Url from CreditGenie.', 'woocommerce-creditgenie-payment-gateway' ),
                'default'         => '',
                'desc_tip'        => true,
            ],
            'api_key' => [
                'title'           => __( 'API Key', 'woocommerce-other-payment-gateway' ),
                'type'            => 'text',
                'description'     => __( 'Get your API keys from your CreditGenie.', 'woocommerce-creditgenie-payment-gateway' ),
                'default'         => '',
                'desc_tip'        => true,
            ],
            'api_token' => [
                'title'           => __( 'API Token', 'woocommerce-creditgenie-payment-gateway' ),
                'type'            => 'text',
                'description'     => __( 'Get your API Token from your CreditGenie.', 'woocommerce-creditgenie-payment-gateway' ),
                'default'         => '',
                'desc_tip'        => true,
            ],
        ];
    }

    /**
     * Admin Panel Options
     *
     * @since 1.0.0
     * @version 1.0.0
     * @return void
     */
    public function admin_options() {
        ?>
        <h3><?php _e( 'CreditGenie', 'woocommerce-creditgenie-payment-gateway' ); ?></h3>
        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">
                <div id="post-body-content">
                    <table class="form-table">
                        <?php $this->generate_settings_html();?>
                    </table>
                </div>
            </div>
        </div>
        <div class="clear"></div>

        <?php
    }

    /**
     * Once payment is selected and submit hit, redirect to the proper page
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param int $order_id The order ID.
     * @return array|string Depending on outcome
     */
    public function process_payment( $order_id ) {
        try {
            global $woocommerce;
            $order  = wc_get_order( $order_id );

            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url( $order )
            ];
        }
        catch ( Exception $e ) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            echo '<script>console.log($e->getMessage());</script>';
        }
    }

    /**
     * This will do the actual redirect and prepare the data
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param WC_Order $order The order
     * @return void
     */
    public function receipt_page( $order ) {
        echo $this->generate_cg_payment_request( $order );
    }

    /**
     * UNUSED Generate refund request
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param int $order_id The order ID.
     * @return void
     */
    /*
    protected function generate_refund_request($order_id){
        $order  = wc_get_order( $order_id );
        try {

        }
        catch ( Exception $e ) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            echo '<script>console.log($e->getMessage());</script>';
        }
    }
    */

    /**
     * Sanitize the cart value
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param string $string The cart item to be sanitized
     * @return string
     */
    private function _sanitize_cart_data($string) {
        if (empty($string)) {
            return $string;
        }

        return trim(preg_replace("/[^a-z0-9.\s]+/i", "", sanitize_text_field($string)));
    }

    /**
     * Sanitize the URL value provided by user
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param string $string The URL for redirecting to CG
     * @return string
     */
    private function _sanitize_url_data($string) {
        if (empty($string)) {
            return $string;
        }

        return trim(esc_url(sanitize_text_field($string)));
    }

    /**
     * Sanitize the user's email
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param string $string The email from the user
     * @return string
     */
    private function _sanitize_email_data($string) {
        if (empty($string)) {
            return $string;
        }

        return trim(preg_replace("/[^a-z0-9_\-@.]+/i", "", sanitize_text_field($string)));
    }

    /**
     * Extract input from cart as much as possible
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param  string $field
     * @param  WC_Order $order
     * @param  string $field The field to fetch
     * @return string $field_return Extracted and sanitized
     */
    private function _extract_shopping_field($order, $field) {
        $field_return = null;

        if (version_compare( WC_VERSION, '3.0.0', '<' )) {
            switch ($field) {
                case 'cg_currency':
                    if (!empty($order->get_order_currency())) {
                        $field_return = $this->_sanitize_cart_data($order->get_order_currency());
                    }
                    break;

                case 'cg_customer_billing_address1':
                    if (!empty($order->cg_customer_billing_address1)) {
                        $field_return = $this->_sanitize_cart_data($order->cg_customer_billing_address1);
                    }
                    break;

                case 'cg_customer_billing_address2':
                    if (!empty($order->billing_address_2)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_address_2);
                    }
                    break;

                case 'cg_customer_billing_city':
                    if (!empty($order->billing_city)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_city);
                    }
                    break;

                case 'cg_customer_billing_country':
                    if (!empty($order->billing_country)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_country);
                    }
                    break;

                case 'cg_customer_billing_company':
                    if (!empty($order->billing_company)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_company);
                    }
                    break;

                case 'cg_customer_billing_phone':
                    if (!empty($order->billing_phone)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_phone);
                    }
                    break;

                case 'cg_customer_billing_province':
                    if (!empty($order->billing_state)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_state);
                    }
                    break;

                case 'cg_customer_billing_postal_code':
                    if (!empty($order->billing_postcode)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_postcode);
                    }
                    break;

                case 'cg_customer_billing_email':
                    if (!empty($order->billing_email)) {
                        $field_return = $this->_sanitize_email_data($order->billing_email);
                    }
                    break;

                case 'cg_customer_billing_first_name':
                    if (!empty($order->billing_first_name)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_first_name);
                    }
                    break;

                case 'cg_customer_billing_last_name':
                    if (!empty($order->billing_last_name)) {
                        $field_return = $this->_sanitize_cart_data($order->billing_last_name);
                    }
                    break;

                case 'cg_customer_shipping_address1':
                    if (!empty($order->shipping_address_1)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_address_1);
                    }
                    break;

                case 'cg_customer_shipping_address2':
                    if (!empty($order->shipping_address_2)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_address_2);
                    }
                case 'cg_customer_shipping_city':
                    if (!empty($order->shipping_city)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_city);
                    }
                    break;

                case 'cg_customer_shipping_country':
                    if (!empty($order->shipping_country)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_country);
                    }
                    break;

                case 'cg_customer_shipping_company':
                    if (!empty($order->shipping_company)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_company);
                    }
                    break;

                case 'cg_customer_shipping_first_name':
                    if (!empty($order->shipping_first_name)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_first_name);
                    }
                    break;

                case 'cg_customer_shipping_last_name':
                    if (!empty($order->shipping_last_name)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_last_name);
                    }
                    break;

                case 'cg_customer_shipping_province':
                    if (!empty($order->shipping_state)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_state);
                    }
                    break;

                case 'cg_customer_shipping_postal_code':
                    if (!empty($order->shipping_postcode)) {
                        $field_return = $this->_sanitize_cart_data($order->shipping_postcode);
                    }
                    break;

                case 'cg_url_cancel':
                    if (!empty($order->get_checkout_payment_url())) {
                        $field_return = $this->_sanitize_url_data($order->get_checkout_payment_url());
                    }
                    break;
            }
        } else {
            switch ($field) {
                case 'cg_currency':
                    if (!empty($order->get_currency())) {
                        $field_return = $this->_sanitize_cart_data($order->get_currency());
                    }
                    break;

                case 'cg_customer_billing_address1':
                    if (!empty($order->get_billing_address_1())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_address_1());
                    }
                    break;

                case 'cg_customer_billing_address2':
                    if (!empty($order->get_billing_address_2())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_address_2());
                    }
                    break;

                case 'cg_customer_billing_city':
                    if (!empty($order->get_billing_city())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_city());
                    }
                    break;

                case 'cg_customer_billing_country':
                    if (!empty($order->get_billing_country())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_country());
                    }
                    break;

                case 'cg_customer_billing_company':
                    if (!empty($order->get_billing_company())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_company());
                    }
                    break;

                case 'cg_customer_billing_phone':
                    if (!empty($order->get_billing_phone())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_phone());
                    }
                    break;

                case 'cg_customer_billing_province':
                    if (!empty($order->get_billing_state())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_state());
                    }
                    break;

                case 'cg_customer_billing_postal_code':
                    if (!empty($order->get_billing_postcode())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_postcode());
                    }
                    break;

                case 'cg_customer_billing_email':
                    if (!empty($order->get_billing_email())) {
                        $field_return = $this->_sanitize_email_data($order->get_billing_email());
                    }
                    break;

                case 'cg_customer_billing_first_name':
                    if (!empty($order->get_billing_first_name())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_first_name());
                    }
                    break;

                case 'cg_customer_billing_last_name':
                    if (!empty($order->get_billing_last_name())) {
                        $field_return = $this->_sanitize_cart_data($order->get_billing_last_name());
                    }
                    break;

                case 'cg_customer_shipping_address1':
                    if (!empty($order->get_shipping_address_1())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_address_1());
                    }
                    break;

                case 'cg_customer_shipping_address2':
                    if (!empty($order->get_shipping_address_2())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_address_2());
                    }
                    break;

                case 'cg_customer_shipping_city':
                    if (!empty($order->get_shipping_city())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_city());
                    }
                    break;

                case 'cg_customer_shipping_country':
                    if (!empty($order->get_shipping_country())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_country());
                    }
                    break;

                case 'cg_customer_shipping_company':
                    if (!empty($order->get_shipping_company())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_company());
                    }
                    break;

                case 'cg_customer_shipping_first_name':
                    if (!empty($order->get_shipping_first_name())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_first_name());
                    }
                    break;

                case 'cg_customer_shipping_last_name':
                    if (!empty($order->get_shipping_last_name())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_last_name());
                    }
                    break;

                case 'cg_customer_shipping_province':
                    if (!empty($order->get_shipping_state())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_state());
                    }
                    break;

                case 'cg_customer_shipping_postal_code':
                    if (!empty($order->get_shipping_postcode())) {
                        $field_return = $this->_sanitize_cart_data($order->get_shipping_postcode());
                    }
                    break;

                case 'cg_url_cancel':
                    if (!empty($order->get_checkout_payment_url())) {
                        $field_return = $this->_sanitize_url_data($order->get_checkout_payment_url());
                    }
                    break;

            }
        }

        return $field_return;
    }

    /**
     * Generate the request for the payment.
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param  int $order_id The order ID
     * @return null
     */
    protected function generate_cg_payment_request($order_id){
        try {
            $order  = wc_get_order( $order_id );

            if ($order->get_payment_method() === 'creditgenie') {
                $post_data = [];
                $post_data['cg_key'] = $this->api_key;
                $post_data['cg_amount'] = $order-> get_total();
                $productDetails = [];
                $items = $order->get_items();

                foreach( $items as $item ) {
                    $productDetails[] = "[{$this->_sanitize_cart_data($item['qty'])}] {$this->_sanitize_cart_data($item['name'])}";
                }

                $productList = implode( ',', $productDetails );

                $post_data['cg_currency'] = $this->_extract_shopping_field($order, 'cg_currency');
                $post_data['cg_customer_billing_address1'] = $this->_extract_shopping_field($order, 'cg_customer_billing_address1');
                $post_data['cg_customer_billing_address2'] = $this->_extract_shopping_field($order, 'cg_customer_billing_address2');
                $post_data['cg_customer_billing_city'] = $this->_extract_shopping_field($order, 'cg_customer_billing_city');
                $post_data['cg_customer_billing_country'] = $this->_extract_shopping_field($order, 'cg_customer_billing_country');
                $post_data['cg_customer_billing_company'] = $this->_extract_shopping_field($order, 'cg_customer_billing_company');
                $post_data['cg_customer_billing_phone'] = $this->_extract_shopping_field($order, 'cg_customer_billing_phone');
                $post_data['cg_customer_billing_province'] = $this->_extract_shopping_field($order, 'cg_customer_billing_province');
                $post_data['cg_customer_billing_postal_code'] = $this->_extract_shopping_field($order, 'cg_customer_billing_postal_code');
                $post_data['cg_customer_billing_email'] = $this->_extract_shopping_field($order, 'cg_customer_billing_email');
                $post_data['cg_customer_billing_first_name'] = $this->_extract_shopping_field($order, 'cg_customer_billing_first_name');
                $post_data['cg_customer_billing_last_name'] = $this->_extract_shopping_field($order, 'cg_customer_billing_last_name');
                $post_data['cg_customer_shipping_address1'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_address1');
                $post_data['cg_customer_shipping_address2'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_address2');
                $post_data['cg_customer_shipping_city'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_city');
                $post_data['cg_customer_shipping_country'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_country');
                $post_data['cg_customer_shipping_company'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_company');
                $post_data['cg_customer_shipping_first_name'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_first_name');
                $post_data['cg_customer_shipping_last_name'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_last_name');
                $post_data['cg_customer_shipping_province'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_province');
                $post_data['cg_customer_shipping_postal_code'] = $this->_extract_shopping_field($order, 'cg_customer_shipping_postal_code');

                $post_data['cg_platform'] = 'woocommerce';
                $post_data['cg_reference'] = $this->_sanitize_cart_data($order->get_order_number());
                $post_data['cg_shop_country'] = 'CA';
                $post_data['cg_shop_name'] =  $this->_sanitize_url_data(get_home_url());
                $post_data['cg_url_callback'] = $this->_sanitize_url_data($this->callback_url);
                $post_data['cg_url_notify'] = $this->_sanitize_url_data($this->notify_url);
                $post_data['cg_url_cancel'] = $this->_extract_shopping_field($order, 'cg_url_cancel');
                $post_data['cg_url_complete'] = $this->_sanitize_url_data($this->get_return_url($order));
                $post_data['cg_products'] = $productList;

                $address2 = '';
                if (isset($post_data['cg_customer_billing_address2'])) {
                    $address2 = $post_data['cg_customer_billing_address2'];
                }

                $sig_data = [
                    'cg_key' => $post_data['cg_key'],
                    'reference' => $post_data['cg_reference'],
                    'shop_name' => $post_data['cg_shop_name'],
                ];

                $sig_calc = hash_hmac('sha256', json_encode($sig_data), $this->api_token);

                $post_data['cg_token'] = $sig_calc;

                $pb_query  =  http_build_query($post_data, '', '&');

                $pb_url = $this->api_url.'/submit?'.$pb_query;

                header('Location:'.$pb_url );
            }
        }
        catch ( Exception $e ) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            echo '<script>console.log($e->getMessage());</script>';
        }
    }

    /**
     * Process incoming requests and if applicable update/modify the order
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param  null
     * @return json $toReturn Whether update was successful
     */
    public function check_cg_response() {
        $toReturn = [
            'status' => '',
        ];

        try {
            global $woocommerce;

            $data = [];
            $data['cg_reference'] = sanitize_text_field($_POST['cg_reference']);
            $data['cg_pingback'] = sanitize_text_field($_POST['cg_pingback']);
            $data['cg_signature'] = sanitize_text_field($_POST['cg_signature']);
            $data['cg_key'] = sanitize_text_field($_POST['cg_key']);
            $data['cg_amount'] = sanitize_text_field($_POST['cg_amount']);
            $data['cg_result'] = sanitize_text_field($_POST['cg_result']);
            $data['shop_name'] = sanitize_text_field($_POST['shop_name']);
            $data['cg_id'] = sanitize_text_field($_POST['cg_id']);

            $order_id = $data['cg_reference'];
            $order = new WC_Order( $order_id);

            if ($order->get_payment_method() === 'creditgenie') {
                $isPingback = false;

                // TODO: add this and check for creditgenie get_payment_method()
                if (!$order->id) {
                    echo json_encode($toReturn);
                    die;
                }

                if (isset($data['cg_pingback']) && intval($data['cg_pingback']) === 1) {
                    $isPingback = true;
                }

                //Check Signature
                $sig_sent = $data['cg_signature'];

                $sig_data = [
                    "cg_key" => $data['cg_key'],
                    "cg_amount" => $data['cg_amount'],
                    "cg_reference" => $data['cg_reference'],
                    "cg_result" => $data['cg_result'],
                    'shop_name' => $data['shop_name'],
                ];

                $sig_calc = hash_hmac('sha256', json_encode($sig_data), $this->api_token);

                // usually a curl callback no redirect needed
                if ($isPingback) {
                    if ($sig_calc === $sig_sent) {
                        $order_status = $data['cg_result'];

                        if (strtolower($order_status) == 'complete') {
                            // When a payment is complete this function is called.
                            version_compare( WC_VERSION, '3.0.0', '<' ) ? add_post_meta( $order->id, '_transaction_id', $data['cg_reference'], true ) : $order->set_transaction_id($data['cg_reference']);

                            $order->payment_complete();
                            $order->update_status('processing');
                            $woocommerce->cart->empty_cart();

                            $toReturn['status'] = 'success';
                        } else if (strtolower($order_status) === 'failed') {
                            $order->update_status('failed');

                            $toReturn['status'] = 'success';
                        } else if (!empty($data['cg_id'])) {
                            $order->add_order_note(__('Your CreditGenie Transaction ID is - '. $data['cg_id'], 'woocommerce'));
                            $toReturn['status'] = 'success';
                        }
                    } else {
                        $order->add_order_note(__('Invalid Request to CreditGenie.', 'woocommerce'));
                    }

                    echo json_encode($toReturn);
                    die;
                }
            }
        }
        catch ( Exception $e ) {
            echo json_encode($toReturn);
            die;

        }

    }

    /**
     * Ping back if any status changes regarding this order
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param  int $order_id The order ID
     * @return null
     */
    public function creditgenie_handle_status_change($order_id)
    {
        try {
            $order = new WC_Order( $order_id);

            if ($order->get_payment_method() === 'creditgenie') {
                if (!empty($this->api_url) && !empty($this->api_token) && !empty($this->api_url)) {
                    $summaryId = null;
                    $status = $this->_sanitize_cart_data($order->get_status());

                    if ($status === 'refunded') {
                        $status = 'refunded $'.$order->get_total_refunded();
                    }

                    $latestNotes = wc_get_order_notes( array(
                        'order_id' => $order->get_id(),
                        'orderby'  => 'date_created_gmt',
                    ));

                    // try to get the app #
                    foreach ($latestNotes as $k => $v) {
                        if (strpos(strtolower($v->content), 'creditgenie transaction id')) {
                            $explode = explode(' ', $v->content);
                            $content = array_pop($explode);
                            $summaryId = preg_replace("/[^\d]/", '', $content);
                            break;
                        }
                    }

                    $post_data['cg_reference'] = $this->_sanitize_cart_data($order->get_order_number());
                    $post_data['cg_shop_name'] =  $this->_sanitize_url_data(get_home_url());
                    $post_data['cg_key'] = $this->_sanitize_cart_data($this->api_key);
                    $post_data['cg_status'] = $this->_sanitize_cart_data($status);
                    $post_data['cg_summary_id'] = $summaryId ? $this->_sanitize_cart_data(intval($summaryId)) : null;

                    $sig_data = [
                        'cg_key' => $post_data['cg_key'],
                        'reference' => $post_data['cg_reference'],
                        'shop_name' => $post_data['cg_shop_name'],
                    ];

                    $sig_calc = hash_hmac('sha256', json_encode($sig_data), $this->_sanitize_cart_data($this->api_token));

                    $post_data['cg_token'] = $sig_calc;

                    // sennd the callback to notify of status change
                    $args = ['body' => $post_data];
                    $response = wp_remote_post($this->api_url.'/callback/status', $args );
                }
            }
        } catch (Exception $e) {}
    }

    /**
     * If refund initiated via partial refund, ajax, then send callback to site
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param  int $order_id The order ID
     * @return null
     */
    public function creditgenie_handle_refund($order_id)
    {
        if (!empty($_POST) && isset($_POST['order_id'])) {
            $order_id = sanitize_text_field($_POST['order_id']);
        }

        try {
            $order = new WC_Order($order_id);

            if ($order->get_payment_method() === 'creditgenie') {
                // dont send callback if this is a full refund or we've already refunded
                // more than total cart amount
                $refund_amount = floatval(self::$instance->_sanitize_cart_data($_POST['refund_amount']));
                $order_total = $order->get_total();
                $total_refunded = $order->get_total_refunded();

                if (
                    $total_refunded >= $order_total ||
                    $total_refunded + $refund_amount >= $order_total
                ) {
                    return;
                }

                if (!empty(self::$instance->api_url) && !empty(self::$instance->api_token) && !empty(self::$instance->api_url)) {
                    $summaryId = null;

                    $latestNotes = wc_get_order_notes( array(
                        'order_id' => $order->get_id(),
                        'orderby'  => 'date_created_gmt',
                    ));

                    // try to get the app #
                    foreach ($latestNotes as $k => $v) {
                        if (strpos(strtolower($v->content), 'creditgenie transaction id')) {
                            $explode = explode(' ', $v->content);
                            $content = array_pop($explode);
                            $summaryId = preg_replace("/[^\d]/", '', $content);
                            break;
                        }
                    }

                    $post_data['cg_reference'] = self::$instance->_sanitize_cart_data($order->get_order_number());
                    $post_data['cg_shop_name'] =  self::$instance->_sanitize_url_data(get_home_url());
                    $post_data['cg_key'] = self::$instance->_sanitize_cart_data(self::$instance->api_key);
                    $post_data['cg_status'] = self::$instance->_sanitize_cart_data('partial refund').' $'.self::$instance->_sanitize_cart_data($_POST['refund_amount']);
                    $post_data['cg_summary_id'] = $summaryId ? self::$instance->_sanitize_cart_data(intval($summaryId)) : null;

                    $sig_data = [
                        'cg_key' => $post_data['cg_key'],
                        'reference' => $post_data['cg_reference'],
                        'shop_name' => $post_data['cg_shop_name'],
                    ];

                    $sig_calc = hash_hmac('sha256', json_encode($sig_data), self::$instance->_sanitize_cart_data(self::$instance->api_token));

                    $post_data['cg_token'] = $sig_calc;

                    $args = ['body' => $post_data];
                    $response = wp_remote_post(self::$instance->api_url.'/callback/status', $args );
                }
            }
        } catch (Exception $e) {}
    }
}
