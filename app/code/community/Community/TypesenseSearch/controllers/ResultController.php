<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_ResultController extends Mage_Core_Controller_Front_Action
{
    #[Maho\Config\Route('/typesensesearch/result', name: 'typesensesearch.result.index', methods: ['GET'])]
    public function indexAction(): void
    {
        $store = Mage::app()->getStore();
        $term = $this->getRequest()->getParam(Mage_CatalogSearch_Helper_Data::QUERY_VAR_NAME);
        $nativeQuery = Mage::getService('catalogsearch/query');
        $query = $nativeQuery->prepare((int) $store->getId(), is_string($term) ? $term : '');
        $text = (string) $query->getQueryText();
        $nativeUrl = Mage::getUrl('catalogsearch/result', ['_query' => ['q' => $text]]);
        if ($text === '' || !Mage::helper('typesensesearch')->isConfigured($store)) {
            $this->getResponse()->setRedirect($nativeUrl);
            return;
        }

        $redirect = $nativeQuery->getRedirect($query);
        if ($redirect !== null) {
            $nativeQuery->log($query);
            $this->getResponse()->setRedirect($redirect);
            return;
        }

        $page = filter_var($this->getRequest()->getParam('p'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 1;
        try {
            $result = Mage::getService('typesensesearch/search')->search(
                $store,
                $query->getSynonymFor() ?: $text,
                $page,
                Community_TypesenseSearch_Service_Search::RESULT_PAGE_SIZE,
            );
        } catch (Exception) {
            Mage::log('Typesense search is currently unavailable.', Mage::LOG_WARNING);
            $this->getResponse()->setRedirect($nativeUrl);
            return;
        }

        $this->loadLayout();
        $block = $this->getLayout()->getBlock('typesense.search.results');
        if ($block instanceof Community_TypesenseSearch_Block_Products) {
            $block->setProducts($result['products']);
            $block->setData('query_text', $text);
            $block->setData('page', $page);
            $block->setData('next', $result['next'] && $page < 1000);
        }
        $this->renderLayout();
        $nativeQuery->log($query, count($result['products']));
    }
}
