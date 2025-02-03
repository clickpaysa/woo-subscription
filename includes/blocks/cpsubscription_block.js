const cpsub_settings = window.wc.wcSettings.getSetting('cpsubscription_data', {});
const cpsub_label = window.wp.htmlEntities.decodeEntities(cpsub_settings.title) || window.wp.i18n.__('ClickPay Subscription Payment', 'clickpay');


const cpsub_content = () => {
      return window.wp.htmlEntities.decodeEntities(cpsub_settings.description || '');
 };
  

const cpsub_icon = cpsub_settings.icon;

const CP_Block_Subscription_Gateway = {
    name: 'cpsubscription',
  	label: cpsub_label,
    content: Object(window.wp.element.createElement)(cpsub_content, null ),
    edit: Object(window.wp.element.createElement)(cpsub_content, null ),
    canMakePayment: () => true,
    ariaLabel: cpsub_label,
    supports: {
        features: cpsub_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod( CP_Block_Subscription_Gateway );

