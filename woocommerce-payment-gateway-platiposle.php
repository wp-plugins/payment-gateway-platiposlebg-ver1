<?php
/*
	Plugin Name: WooCommerce Plati Posle Payment Gateway Free
	Plugin URI: http://www.platiposle.bg
	Description: 'Плати После' - метод за отложено плащане
	Version: 2015.4.8
	Author: Плати После
	Author URI: http://www.platiposle.bg/
*/

if ( ! defined( 'ABSPATH' ) )
    exit;

add_action('plugins_loaded', 'wc_platiposle_init', 0);

function wc_platiposle_init() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

    /**
     * Platiposle Gateway class
     */
    class WC_Platiposle_Gateway extends WC_Payment_Gateway {

        const DELIMITER_HASH_FIELD = '|';

        public function __construct(){

            $this->id 					= 'platiposle';
            $this->icon 				= apply_filters('woocommerce_paga_icon', plugins_url( 'assets/platiposle.png' , __FILE__ ) );
            $this->has_fields 			= false;
            $this->method_title     	= __('Плати После', 'platipolse');
            $this->method_description  	= __('Платежен метод \'Плати После\' дава възможност на клиентите да плащат своите поръчки до 15 календарни дни след получаване на стоката без никакво оскъпяване.', 'platiposle');

            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title 					= __('Отложено плащане', 'platiposle');
            $this->description 				= __('Платежен метод \'Плати После\' дава възможност на клиентите да плащат своите поръчки до 15 календарни дни след получаване на стоката без никакво оскъпяване.','platiposle');

            $this->platiposleGateUrl = 'https://gate.platiposle.bg/checkout';

            foreach ($this->settings as $settingsKey => $value)
                $this->$settingsKey = $value;


            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_receipt_platiposle' , array($this, 'receipt_page'));
            add_action( 'woocommerce_api_wc_platiposle_gateway', array( $this, 'platiposle_response' ) );

            // Check if the gateway can be used
            if ( ! $this->is_valid_for_use() ) {
                $this->enabled = false;
            }
        }


        /**
         * Check if gateway is compatible
         * @return bool
         */
        public function is_valid_for_use() {
            if( ! in_array( get_woocommerce_currency(), array('BGN') ) ){
                $this->msg = __('Плати После поддържа магизини с основна валута "ЛЕВА". Настройка за основна валута на вашия магизин може да направите от <a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=general">тук</a>', 'platiposle');
                return false;
            }
            return true;
        }


        /**
         * Admin Panel Options
         **/
        public function admin_options(){
            echo '<h3>' . __('Плати После', 'platiposle') . '</h3>';
            echo '<p>' . __('Получавате цялата стойност на поръчката (без такси) от \'Плати После\' по банков път - гарантирано!', 'platiposle') . '</p>';

            if ( $this->is_valid_for_use() ){
                echo '<table class="form-table">';
                $this->generate_settings_html();
                echo '</table>';
            }
            else
                echo '<div class="inline error"><p><strong>\'Плати После\' е НЕАКТИВЕН</strong>: ' . $this->msg . '</p></div>';

        }


        /**
         * Initialise Gateway Settings Form Fields
         **/
        function init_form_fields(){
            $this->form_fields = array(
                'enabled'     => array(
                    'title'       => __( 'Enable/Disable', 'woothemes' ),
                    'label'       => __( 'Активирай разплащане чрез Плати После', 'woothemes' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'token' => array(
                    'title' 		=> __( 'ИД на магазин (token)', 'woothemes' ),
                    'type' 			=> 'text',
                    'description' 	=> '',
                    'default' 		=> ''
                ),
                'security_method' => array(
                    'title' 		=> __('Сигурност', 'woothemes' ),
                    'type' 			=> 'select',
                    'description' 	=> '',
                    'options'     => array(
                        'yes'   => __('Да', 'woothemes' ),
                        'no'    => __('Не', 'woothemes' )
                    ),
                    'default'     => 'no',
                ),
                'security_key' => array(
                    'title' 		=> __('Частен ключ', 'woothemes' ),
                    'type' 			=> 'text',
                    'description' 	=> '',
                    'default'     => '',
                ),
                'return_url' => array(
                    'title' 		=> __('URL за връщане', 'woothemes' ),
                    'type' 			=> 'text',
                    'description' 	=> '',
                    'default'     => get_home_url() . '/',
                ),
                'error_url' => array(
                    'title' 		=> __('URL за грешка', 'woothemes' ),
                    'type' 			=> 'text',
                    'description' 	=> '',
                    'default'     => get_home_url() . '/',
                ),
            );
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            $order = new WC_Order( $order_id );
            return array(
                'result' 	=> 'success',
                'redirect'	=> $order->get_checkout_payment_url( true )
            );
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order){
            echo '<p>'.__('Благодарим Ви за поръчката! Моля кликнете бутона "Продължи", за да попълните онлайн заявлението за отложено плащане "Плати После”.', 'woocommerce').'</p>';
            echo $this->generate_platiposle_form($order);
        }

        /**
         * Generate Плати После button link
         **/
        public function generate_platiposle_form($order_id){

            global $woocommerce;

            $order = new WC_Order($order_id);
            $aItems = $order->get_items();

            $sToken = $this->token;
            $orderTotal = $order->order_total;
            $aStringToBeCrypt = array(
                $order_id,
                $orderTotal,
                $sToken,
            );

            if ($this->security_method === 'yes') {
                $sSecretKey = $this->security_key;
                array_push($aStringToBeCrypt, sha1(md5($sSecretKey)));
            }

            $sStringToBeCrypt = implode(self::DELIMITER_HASH_FIELD, $aStringToBeCrypt);
            $hash = sha1(md5($sStringToBeCrypt));


            $aOrderArg = array(
                'token'         => $this->token,
                'hash'          => $hash,
                'orderId'       => $order_id,
                'ordertotal'    => $order->order_total,
                'notes'         => esc_html($order->customer_note),
                'returnUrl'     => $this->return_url,
                'successUrl'    => self::getSuccessHash($order),
                'errorUrl'      => $this->error_url,

                'firstName'     => esc_html($order->billing_first_name),
                'lastName'      => esc_html($order->billing_last_name),
                'middleName'    => '',
                'email'         => esc_html($order->billing_email),
                'phone'         => esc_html($order->billing_phone),
                'region'        => esc_html($order->billing_state),
                'city'          => esc_html($order->billing_city),
                'village'       => esc_html($order->billing_city),
                'zip'           => esc_html($order->billing_postcode),
                'address'       => esc_html($order->billing_address_1),
            );

            if (!empty($aItems) && is_array($aItems)) {
                $itemIdx = 0;
                foreach ($aItems as $item) {
                    $aOrderArg[sprintf('items[%d][name]', $itemIdx)] =  esc_html($item['name']);
                    $aOrderArg[sprintf('items[%d][qty]', $itemIdx)] =  esc_html($item['qty']);
                    $itemIdx++;
                }
                unset($itemIdx, $item, $aItems);
            }

            $payu_args_array = array();
            foreach($aOrderArg as $key => $value){
                $payu_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
            }
            return '<form action="'.$this->platiposleGateUrl.'" method="post" id="platiposle_payment_form">
            ' . implode('', $payu_args_array) . '
            <input type="submit" class="button-alt" id="submit_platiposle_payment_form" value="'.__('Продължи', 'platiposle').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Отказ и връщане в количката', 'platiposle').'</a>
            <script type="text/javascript">
jQuery(function(){


    //jQuery("#submit_payu_payment_form").click();

    });</script>
            </form>';


        }


        public function platiposle_response() {
            global $woocommerce;

            if (!isset($_GET['hash']) || strlen($_GET['hash']) < 10) {
                wp_die( "Invalid request." );
            }
            $sOrderKey = $_GET['hash'];

            if (($orderId = wc_get_order_id_by_order_key($sOrderKey)) === NULL) {
                wp_die( "Invalid order id." );
            }

            $order = new WC_Order($orderId);
            $order->update_status( 'completed', 'Попълнено заявление.' );
            $order->add_order_note( 'Платено чрез Плати после<br />Попълнено заявление' );
            // Reduce stock levels
            $order->reduce_order_stock();

            // Empty cart
            WC()->cart->empty_cart();

            $redirect_url = esc_url( $this->get_return_url( $order ) );
            wp_redirect( $redirect_url );
            exit;

        }

        /**
         * Get success url hash
         * @param array $aParams
         * @return string
         */
        protected static function getSuccessHash($order){
            return WC()->api_request_url( 'WC_Platiposle_Gateway' ) . '&hash=' . $order->order_key;
        }

    }

    /**
     * Add Platiposle Gateway to WC
     **/
    function wc_add_platiposle_gateway($methods) {
        $methods[] = 'WC_Platiposle_Gateway';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'wc_add_platiposle_gateway' );


    /**
     * Hide/show platiposle gateway in fron-end depending of platiposle config
     * @param $gateways
     * @return mixed
     */
    function filter_gateways($gateways){
        global $woocommerce;

        $total = WC()->cart->cart_contents_total;
        $configParams = file_get_contents('https://platiposle.bg/config');
        $config = json_decode($configParams);
        $order_amount_min = $order_amount_max = 0;
        if($config){
            $order_amount_min = $config->order_amount_min;
            $order_amount_max = $config->order_amount_max;
        }

        if (!($total >= $order_amount_min && $total <= $order_amount_max))
            unset($gateways['platiposle']);

        return $gateways;
    }
    add_filter('woocommerce_available_payment_gateways','filter_gateways',1);


    /**
     * Add Settings link to the plugin entry in the plugins menu for WC below 2.1
     **/
    add_filter('plugin_action_links', 'platiposle_plugin_action_links', 10, 2);

    function platiposle_plugin_action_links($links, $file) {
        static $this_plugin;

        if (!$this_plugin) {
            $this_plugin = plugin_basename(__FILE__);
        }

        if ($file == $this_plugin) {
            $sSettings = __( 'Settings' , 'akismet');
            if ( version_compare( WOOCOMMERCE_VERSION, "2.1" ) <= 0 ) {
                $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=woocommerce_settings&tab=payment_gateways&section=wc_platiposle_gateway">' . $sSettings . '</a>';
            } elseif (version_compare( WOOCOMMERCE_VERSION, "2.3.7" ) <= 0)
                $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_platiposle_gateway">' . $sSettings . '</a>';
            else
                $settings_link = '';

            //Add plugin documentation link
            array_unshift($links, '<a href="https://platiposle.bg/modules#wordpress" target="_blank">' . __('Документация', 'platiposle') . '</a>');

            if (!empty($settings_link))
                array_unshift($links, $settings_link);
        }
        return $links;
    }



}
