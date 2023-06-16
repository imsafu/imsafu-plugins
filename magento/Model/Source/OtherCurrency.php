<?php
/**
 * Copyright © 2023 imsafu. All rights reserved.
 * See LICENSE for license details.
 */
 
namespace Imsafu\Payment\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class OtherCurrency implements ArrayInterface {
	
    /**
     * @return array
     */
	public function toOptionArray() {
        return [
            ['value' => '1', 'label' => __('3D Secure')],
            ['value' => '0', 'label' =>__('Sale')]
        ];
    }
}

