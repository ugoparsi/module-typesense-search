<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Helper_CatalogSearch extends Mage_CatalogSearch_Helper_Data
{
    #[\Override]
    public function getResultUrl($query = null): string
    {
        if (!Mage::helper('typesensesearch')->isConfigured(Mage::app()->getStore())) {
            return parent::getResultUrl($query);
        }

        return $this->_getUrl('typesensesearch/result', [
            '_query' => [self::QUERY_VAR_NAME => $query],
            '_secure' => Mage::app()->isCurrentlySecure(),
        ]);
    }

    #[\Override]
    public function getSuggestUrl(): string
    {
        if (!Mage::helper('typesensesearch')->isConfigured(Mage::app()->getStore())) {
            return parent::getSuggestUrl();
        }

        return $this->_getUrl('typesensesearch/ajax/suggest', ['_secure' => Mage::app()->isCurrentlySecure()]);
    }
}
