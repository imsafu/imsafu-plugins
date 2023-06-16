<?php
/**
 * Copyright © 2023 imsafu. All rights reserved.
 * See LICENSE for license details.
 */
namespace Imsafu\Payment\Block;

class Form extends \Magento\Payment\Block\Form
{
    /**
     * Checkmo template
     *
     * @var string
     */
    protected $_supportedInfoLocales = array('en');
    protected $_defaultInfoLocale = 'en';
    
    protected $_template = 'Imsafu_Payment::form.phtml';
}
