<?php

class simplepay extends base {

    var $code;
    var $title;
    var $description;
    var $enabled;

    function simplepay(){

        // Class Initialization

        global $db;

        $this->code            = 'simplepay';
        $this->api_version     = 'SimplePay Payments service v 1.0.0 for ZenCart';
        $this->title           = MODULE_PAYMENT_SIMPLEPAY_TEXT_TITLE;
        $this->description     = MODULE_PAYMENT_SIMPLEPAY_TEXT_DESCRIPTION;
        $this->enabled         = MODULE_PAYMENT_SIMPLEPAY_STATUS;
        $this->form_action_url = '';

        // Currency and Country Check

        $sql = 'select configuration_value from '.TABLE_CONFIGURATION.' where configuration_id = 200;';
        $currency_query = $db->Execute($sql);

        $currency_check = 0;

        while (!$currency_query->EOF) {

            $currency_check = $currency_query->fields['configuration_value']== 'NGN';
            $currency_query->MoveNext();

        }

        if(IS_ADMIN_FLAG === true) {

            if(STORE_COUNTRY != 156){
                $this->title.= '<span class="alert">This Payment method is only available for Nigerian stores, please change your store location</span>';
            }
            $this->title.= $currency_check ? '' : '<span class="alert">The Store coin isn\'t Nigerian Naira</span>';

        }
        if(STORE_COUNTRY != 156 or !$currency_check){
            $this->enabled = false;
        }

    }

    function update_status(){}

    function javascript_validation()
    {
        return false;
    }

    function selection(){

        return array(
            'id' => $this->code,
            'module' => $this->title
        );

    }

    function pre_confirmation_check(){}

    function confirmation(){

        // Implement the SimplePay Gateway on the confirmation button

        global $_POST, $order,$db;

        $public_key = (MODULE_PAYMENT_SIMPLEPAY_MODE == 'Test' ? MODULE_PAYMENT_SIMPLEPAY_PUBLIC_TEST_API_KEY : MODULE_PAYMENT_SIMPLEPAY_PUBLIC_LIVE_API_KEY );

        $last_order_query = $db->Execute("select max(orders_id) as 'max' from ".TABLE_ORDERS.";");
        $last_order = 0;

        while (!$last_order_query ->EOF){

            $last_order = intval($last_order_query->fields['max']);
            $last_order_query->MoveNext();

        }

        echo '
            <script src="https://checkout.simplepay.ng/simplepay.js"></script>
            <script>
                        
                function processPayment (token) {

                    var form = $("#checkout_confirmation");
                    form.attr(\'action\', \'index.php?main_page=checkout_process\');
                    form.append("<input type=\'hidden\' name=\'simplepay_token\' value=\'"+token+"\'/>");
                    form.append("<input type=\'hidden\' name=\'simplepay_amount\' value=\'"+SimplePay.amountToLower("'.$order->info['total'].'")+"\'/>");
                    form.append("<input type=\'hidden\' name=\'simplepay_currency\' value=\'NGN\'/>");
                    form.submit();

                }
            
                var handler = SimplePay.configure({
                   token: processPayment,
                   key: "'.$public_key.'",
                   image: "'. MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_IMG .'" 
                });
            
                $(document).ready(function(){
                                                                    
                    $("#checkout_confirmation").submit(function(e){
                        e.preventDefault();
    
                        handler.open(SimplePay.CHECKOUT,{
                           email: "'.$order->customer['email_address'].'", 
                           phone: "'.$order->customer['telephone'].'",
                           description: "'.(MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_DESCRIPTION == ''? 'Payment of the order n. '.($last_order+1) : MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_DESCRIPTION).'",
                           address: "'.$order->customer['street-address'].'",
                           postal_code: "'.$order->customer['postcode'].'",
                           city: "'.$order->customer['postcode'].'",
                           country: "'.$order->customer['country']['iso_code2'].'",
                           amount: SimplePay.amountToLower("'.$order->info['total'].'"),
                           currency: "'.$order->info['currency'].'"
                        });
                        
                        $("#checkout_confirmation").unbind( "submit" );
                        
                        return false;
                        
                    });
                
                });    
                
            </script>';
    }

    function before_process(){

        //Verify payment

        global $_POST,$messageStack;

        require_once(DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/simplepay/verify.php');
        $private_key = (MODULE_PAYMENT_SIMPLEPAY_MODE == 'Test' ? MODULE_PAYMENT_SIMPLEPAY_PRIVATE_TEST_API_KEY : MODULE_PAYMENT_SIMPLEPAY_PRIVATE_LIVE_API_KEY );

        $verified_transaction = verify_transaction($_POST['simplepay_token'],$_POST['simplepay_amount'], $_POST['simplepay_currency'],$private_key);

        if ($verified_transaction['verified']){
            $_SESSION['simplepay_verifify_token'] = $verified_transaction['response']['id'];
        }
        else{
            $messageStack->add_session('checkout_confirmation','Error verifying the transaction - '.json_encode($verified_transaction['response']), 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_CONFIRMATION, '', 'SSL', true, false));
        }

    }

    function after_process(){

        //Insert order status of the approved payment

        global $insert_id;

        zen_db_perform(TABLE_ORDERS, array('orders_status' => MODULE_PAYMENT_SIMPLEPAY_PAYMENT_STATUS ), "update", "orders_id='" . $insert_id . "'");

        $successful_data = array(
            'orders_id' => $insert_id,
            'orders_status_id' => MODULE_PAYMENT_SIMPLEPAY_PAYMENT_STATUS,
            'date_added' => 'now()',
            'customer_notified' => -1,
            'comments' => 'Payment Successfully Verified - '.$_SESSION['simplepay_verifify_token']
        );
        zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $successful_data);

    }

    function process_button()
    {
        return false;
    }

    function get_error(){

        global $_GET;

        $error = array(
            'title' => '',
            'error' => stripslashes($_GET['error'])
        );
        return $error;
    }

    function check(){

        global $db;

        if (!isset($this->_check)) {
            $check_query  = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_SIMPLEPAY_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;

    }


    function install(){

        //Basic Verifications

        global $db, $messageStack;

        if (defined('MODULE_PAYMENT_SIMPLEPAY_STATUS')) {

            $messageStack->add_session('SimplePay module already installed.', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=simplepay', 'NONSSL'));
            return 'failed';

        }

        if(!is_callable('curl_init')){

            $messageStack->add_session('SimplePay module needs php module curl to work, please install curl!', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=simplepay', 'NONSSL'));
            return 'failed';

        }

        //Administration Fields
        //Enable SimplePay Payments

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable SimplePay Payments', 'MODULE_PAYMENT_SIMPLEPAY_STATUS', 'True', 'Do you want to accept SimplePay payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");

        //Test or live mode

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction Mode', 'MODULE_PAYMENT_SIMPLEPAY_MODE', 'Test', 'Mode used for processing orders', '6', '2', 'zen_cfg_select_option(array(\'Test\', \'Live\'), ', now())");

        //APIs

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Public Test API Key', 'MODULE_PAYMENT_SIMPLEPAY_PUBLIC_TEST_API_KEY', '', '', '6', '3', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Private Test API Key', 'MODULE_PAYMENT_SIMPLEPAY_PRIVATE_TEST_API_KEY', '', '', '6', '4', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Public Live API Key', 'MODULE_PAYMENT_SIMPLEPAY_PUBLIC_LIVE_API_KEY', '', '', '6', '5', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Private Live API Key', 'MODULE_PAYMENT_SIMPLEPAY_PRIVATE_LIVE_API_KEY', '', '', '6', '6', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Custom checkout image', 'MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_IMG', '', '', '6', '7', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Check out description', 'MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_DESCRIPTION', '', '', '6', '8', now())");

        $check_query = $db->Execute("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_name = 'SimplePay - Payment' limit 1");
        $status_id = 0;

        if ($check_query->RecordCount() < 1) {

            $status = $db->Execute("select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS);
            $status_id = $status->fields['status_id'] + 1;
            $languages = zen_get_languages();

            foreach ($languages as $lang) {
                $db->Execute("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('" . $status_id . "', '" . $lang['id'] . "', 'SimplePay - Payment')");
            }

        }

        else {
            $status_id = $check_query->fields['orders_status_id'];
        }

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Payment Status', 'MODULE_PAYMENT_SIMPLEPAY_PAYMENT_STATUS', '" . $status_id . "', '', '6', '9', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");


    }

    function remove(){

        global $db;

        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");

    }
    

    function keys(){

        return array(

            'MODULE_PAYMENT_SIMPLEPAY_STATUS',
            'MODULE_PAYMENT_SIMPLEPAY_MODE',
            'MODULE_PAYMENT_SIMPLEPAY_PUBLIC_TEST_API_KEY',
            'MODULE_PAYMENT_SIMPLEPAY_PRIVATE_TEST_API_KEY',
            'MODULE_PAYMENT_SIMPLEPAY_PUBLIC_LIVE_API_KEY',
            'MODULE_PAYMENT_SIMPLEPAY_PRIVATE_LIVE_API_KEY',
            'MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_IMG',
            'MODULE_PAYMENT_SIMPLEPAY_CHECKOUT_DESCRIPTION',
            'MODULE_PAYMENT_SIMPLEPAY_THEME',
            'MODULE_PAYMENT_SIMPLEPAY_PAYMENT_STATUS'
        );

    }

}

?>

