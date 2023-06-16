<?php
/**
 * Copyright © 2023 imsafu. All rights reserved.
 * See LICENSE for license details.
 */
 
namespace Imsafu\Payment\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class PayMode implements ArrayInterface {
	
    /**
     * @return array
     */
	public function toOptionArray() {
        return [
            ['value' => 'iframe', 'label' => __('Iframe')],
            ['value' => 'redirect', 'label' =>__('Redirect')]
        ];
    }
}

