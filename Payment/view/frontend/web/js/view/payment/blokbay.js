/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'blokbay',
        component: 'Blokbay_Payment/js/view/payment/method-renderer/blokbay-method'
    });

    return Component.extend({});
});
