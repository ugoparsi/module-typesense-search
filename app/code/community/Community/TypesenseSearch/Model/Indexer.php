<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Model_Indexer extends Mage_Index_Model_Indexer_Abstract
{
    #[\Override]
    public function getName(): string
    {
        return Mage::helper('typesensesearch')->__('Typesense Catalog Search');
    }

    #[\Override]
    public function getDescription(): string
    {
        return Mage::helper('typesensesearch')->__('Rebuild the Typesense product index in enabled store views.');
    }

    #[\Override]
    public function reindexAll(): void
    {
        $index = Mage::getService('typesensesearch/index');
        foreach (Mage::helper('typesensesearch')->getConfiguredViews() as $store) {
            try {
                $index->rebuild($store);
            } catch (Exception) {
                throw new Mage_Core_Exception(Mage::helper('typesensesearch')->__('The Typesense product index could not be rebuilt for store view %s.', $store->getCode()));
            }
        }
    }

    #[\Override]
    protected function _registerEvent(Mage_Index_Model_Event $event): void {}

    #[\Override]
    protected function _processEvent(Mage_Index_Model_Event $event): void {}
}
