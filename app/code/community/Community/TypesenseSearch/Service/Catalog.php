<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Service_Catalog
{
    public function getEligibleProducts(Mage_Core_Model_Store $store, bool $forIndex = false): Mage_Catalog_Model_Resource_Product_Collection
    {
        $products = Mage::getResourceModel($forIndex ? 'typesensesearch/index_collection' : 'catalog/product_collection')
            ->setStoreId((int) $store->getId())
            ->addStoreFilter($store)
            ->addWebsiteFilter((int) $store->getWebsiteId())
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => Mage_Catalog_Model_Product_Visibility::getVisibleInSearchIds()]);
        if (!Mage::getStoreConfigFlag(Mage_CatalogInventory_Helper_Data::XML_PATH_SHOW_OUT_OF_STOCK, $store)) {
            Mage::getSingleton('cataloginventory/stock_status')->addIsInStockFilterToCollection($products);
        }
        return $products;
    }

    /** @return array{id: string, sku: string, name: string} */
    public function buildDocument(Mage_Catalog_Model_Product $product): array
    {
        return [
            'id' => (string) $product->getId(),
            'sku' => (string) $product->getSku(),
            'name' => (string) $product->getName(),
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<Mage_Catalog_Model_Product>
     */
    public function loadResults(Mage_Core_Model_Store $store, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = $this->getEligibleProducts($store)
            ->addIdFilter($ids)
            ->addAttributeToSelect(Mage::getSingleton('catalog/config')->getProductAttributes())
            ->addPriceData(null, (int) $store->getWebsiteId())
            ->addTaxPercents()
            ->addUrlRewrite();
        $order = Mage::getResourceHelper('catalogsearch')->getFieldOrderExpression('e.entity_id', $ids);
        $products->getSelect()->order(new Maho\Db\Expr($order));

        return array_values($products->getItems());
    }
}
