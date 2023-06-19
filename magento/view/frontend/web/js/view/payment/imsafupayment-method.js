/**
 * Copyright © 2023 imsafu. All rights reserved.
 * See LICENSE for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'imsafupayment',
                component: 'Imsafu_Payment/js/view/payment/method-renderer/imsafupayment-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
