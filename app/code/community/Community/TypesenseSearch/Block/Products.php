<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Block_Products extends Mage_Catalog_Block_Product_Abstract
{
    /** @param list<Mage_Catalog_Model_Product> $products */
    public function setProducts(array $products): static
    {
        return $this->setData('products', $products);
    }

    /** @return list<Mage_Catalog_Model_Product> */
    public function getProducts(): array
    {
        return $this->getData('products') ?? [];
    }

    public function getQueryText(): string
    {
        return (string) $this->getData('query_text');
    }

    public function getPage(): int
    {
        return (int) $this->getData('page');
    }

    public function hasNextPage(): bool
    {
        return (bool) $this->getData('next');
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('typesensesearch/result', ['_query' => ['q' => $this->getQueryText(), 'p' => $page]]);
    }
}
