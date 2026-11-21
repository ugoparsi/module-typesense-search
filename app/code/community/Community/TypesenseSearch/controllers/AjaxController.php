<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_AjaxController extends Mage_Core_Controller_Front_Action
{
    #[Maho\Config\Route('/typesensesearch/ajax/suggest', name: 'typesensesearch.ajax.suggest', methods: ['GET'])]
    public function suggestAction(): void
    {
        $store = Mage::app()->getStore();
        $term = $this->getRequest()->getParam(Mage_CatalogSearch_Helper_Data::QUERY_VAR_NAME);
        $query = Mage::getService('catalogsearch/query')->prepare((int) $store->getId(), is_string($term) ? $term : '');
        $text = (string) $query->getQueryText();
        if ($text === '' || Mage::helper('catalogsearch')->isMinQueryLength()) {
            return;
        }

        $nativeUrl = Mage::getUrl('catalogsearch/ajax/suggest', ['_query' => ['q' => $text]]);
        if (!Mage::helper('typesensesearch')->isConfigured($store)) {
            $this->getResponse()->setRedirect($nativeUrl);
            return;
        }

        try {
            $result = Mage::getService('typesensesearch/search')->search(
                $store,
                $query->getSynonymFor() ?: $text,
                1,
                Community_TypesenseSearch_Service_Search::SUGGESTION_LIMIT,
            );
        } catch (Exception) {
            $this->getResponse()->setRedirect($nativeUrl);
            return;
        }

        $block = $this->getLayout()->createBlock('typesensesearch/products');
        $block->setTemplate('typesensesearch/suggest.phtml');
        $block->setProducts($result['products']);
        $this->getResponse()->setBody($block->toHtml());
    }
}
