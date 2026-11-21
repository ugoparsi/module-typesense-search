<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Model_Resource_Index_Collection extends Mage_Catalog_Model_Resource_Product_Collection
{
    #[\Override]
    protected function _initSelect()
    {
        // A delayed replica can still show a removed or older product between two worker deliveries.
        $this->setConnection(Mage::getSingleton('core/resource')->getConnection('core_write'));
        return parent::_initSelect();
    }
}
