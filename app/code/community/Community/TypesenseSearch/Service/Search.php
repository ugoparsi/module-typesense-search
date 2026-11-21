<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Service_Search
{
    public const int RESULT_PAGE_SIZE = 24;
    public const int SUGGESTION_LIMIT = 10;

    /**
     * @return array{products: list<Mage_Catalog_Model_Product>, next: bool}
     */
    public function search(Mage_Core_Model_Store $store, string $query, int $page, int $limit): array
    {
        $client = Mage::getService('typesensesearch/client')->getClient($store);
        $collection = Mage::helper('typesensesearch')->getCollection($store);
        $response = $client->collections[$collection]->documents->search([
            'q' => $query,
            'query_by' => 'name,sku',
            'include_fields' => 'id',
            'page' => $page,
            'per_page' => $limit,
        ]);
        if (!isset($response['hits'], $response['found']) || !is_array($response['hits']) || !is_int($response['found'])) {
            throw new Mage_Core_Exception('Typesense returned an invalid search response.');
        }

        $ids = [];
        foreach ($response['hits'] as $hit) {
            $id = filter_var($hit['document']['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[$id] = $id;
            }
        }

        return [
            'products' => Mage::getService('typesensesearch/catalog')->loadResults($store, array_values($ids)),
            'next' => $response['found'] > $page * $limit,
        ];
    }
}
