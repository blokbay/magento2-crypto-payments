/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Blokbay_Payment/payment/blokbay',
            redirectAfterPlaceOrder: false
        },

        /**
         * Sends the customer to the page that creates the Blokbay payment.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('blokbay/payment/redirect'));
        }
    });
});
