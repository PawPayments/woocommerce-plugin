/**
 * PawPayments in the Checkout block. Plain browser JavaScript, no build step:
 * the block only shows the method; WC_PawPayments_Blocks passes its title and
 * description, and paying redirects to the PawPayments checkout through
 * WC_Gateway_PawPayments::process_payment().
 */
(function () {
    var settings = window.wc.wcSettings.getSetting('pawpayments_data', {});
    var decode = window.wp.htmlEntities.decodeEntities;
    var el = window.wp.element.createElement;
    var title = decode(settings.title || '') || 'PawPayments';

    function Description() {
        return el('div', null, decode(settings.description || ''));
    }

    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'pawpayments',
        label: el('span', null, title),
        ariaLabel: title,
        content: el(Description),
        edit: el(Description),
        canMakePayment: function () {
            return true;
        },
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
