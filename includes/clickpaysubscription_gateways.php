<?php

defined('CLICKPAYSUBSCRIPTION_VERSION') or die;

class WC_Gateway_Clickpay_Subscription extends WC_Gateway_ClickpaySub
{
    protected $_code = 'cpsubscription';
    protected $_title = 'Clickpay - Subscription payments';
    protected $_description = 'ClickPay - Subscription';

    protected $_icon = "clickpay.png";
}
