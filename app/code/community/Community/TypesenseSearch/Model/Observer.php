<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

use Maho\Config\Observer;
use Maho\Event\Observer as EventObserver;
use Maho\Queue\QueueManager;

class Community_TypesenseSearch_Model_Observer
{
    #[Observer('catalog_product_save_after', id: 'typesensesearch_product_save')]
    public function productSaved(EventObserver $observer): void
    {
        $product = $observer->getProduct();
        if (!$product instanceof Mage_Catalog_Model_Product || !$product->getId()) {
            return;
        }

        $helper = Mage::helper('typesensesearch');
        $views = $helper->getConfiguredViews();
        if (!$product->getIsChangedWebsites()) {
            $websiteIds = array_map(intval(...), $product->getWebsiteIds());
            $views = array_filter($views, static fn($view): bool => in_array((int) $view->getWebsiteId(), $websiteIds, true));
            $sourceId = (int) $product->getStoreId();

            if ($sourceId !== 0) {
                $scope = $this->changedScope($product);
                $source = Mage::app()->getStore($sourceId);
                $views = match ($scope) {
                    Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL => $views,
                    Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE => array_filter(
                        $views,
                        static fn($view): bool => $view->getWebsiteId() === $source->getWebsiteId(),
                    ),
                    default => array_filter($views, static fn($view): bool => (int) $view->getId() === $sourceId),
                };
            }
        }
        $this->enqueue([(int) $product->getId()], $views);
    }

    #[Observer('catalog_product_delete_after', id: 'typesensesearch_product_delete')]
    public function productDeleted(EventObserver $observer): void
    {
        $product = $observer->getProduct();
        if ($product instanceof Mage_Catalog_Model_Product && $product->getId()) {
            $this->enqueue([(int) $product->getId()], Mage::helper('typesensesearch')->getConfiguredViews());
        }
    }

    #[Observer('catalog_product_attribute_update_after', id: 'typesensesearch_attribute_update')]
    #[Observer('catalog_product_website_update', id: 'typesensesearch_website_update')]
    public function productsChanged(EventObserver $observer): void
    {
        $productIds = array_map(intval(...), (array) $observer->getProductIds());
        $this->enqueue($productIds, Mage::helper('typesensesearch')->getConfiguredViews());
    }

    #[Observer('cataloginventory_stock_item_save_after', id: 'typesensesearch_stock_update')]
    public function stockChanged(EventObserver $observer): void
    {
        $item = $observer->getItem();
        if ($item instanceof Mage_CatalogInventory_Model_Stock_Item && $item->getProductId()) {
            $this->enqueue([(int) $item->getProductId()], Mage::helper('typesensesearch')->getConfiguredViews());
        }
    }

    private function changedScope(Mage_Catalog_Model_Product $product): int
    {
        $scope = Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE;
        foreach (['sku', 'name', 'status', 'visibility'] as $code) {
            if (!$product->dataHasChangedFor($code)) {
                continue;
            }

            $attribute = $product->getResource()->getAttribute($code);
            if (!$attribute instanceof Mage_Catalog_Model_Resource_Eav_Attribute) {
                continue;
            }
            if ($attribute->isScopeGlobal()) {
                return Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL;
            }
            if ($attribute->isScopeWebsite()) {
                $scope = Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE;
            }
        }
        return $scope;
    }

    /** @param array<Mage_Core_Model_Store> $views */
    private function enqueue(array $productIds, array $views): void
    {
        foreach (array_unique($productIds) as $productId) {
            foreach ($views as $store) {
                QueueManager::dispatch(
                    new Community_TypesenseSearch_Queue_Message_ProductChanged((int) $store->getId(), $productId),
                    queue: 'typesensesearch',
                );
            }
        }
    }
}
