<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

class Community_TypesenseSearch_Service_Index
{
    private const int BATCH_SIZE = 250;

    public function rebuild(Mage_Core_Model_Store $store): void
    {
        $this->withViewLock($store, function () use ($store): void {
            $client = Mage::getService('typesensesearch/client')->getClient($store, true);
            $catalog = Mage::getService('typesensesearch/catalog');
            $alias = Mage::helper('typesensesearch')->getCollection($store);
            $previous = $this->getActiveCollection($client, $alias);
            $prefix = $alias . '_build_';
            $collection = $prefix . bin2hex(random_bytes(8));

            $client->collections->create([
                'name' => $collection,
                'fields' => [
                    ['name' => 'sku', 'type' => 'string'],
                    ['name' => 'name', 'type' => 'string'],
                ],
            ]);

            try {
                $lastId = 0;
                while (true) {
                    $products = $catalog->getEligibleProducts($store, forIndex: true)
                        ->addAttributeToFilter('entity_id', ['gt' => $lastId])
                        ->addAttributeToSort('entity_id', 'asc')
                        ->setPageSize(self::BATCH_SIZE);
                    $documents = [];
                    foreach ($products as $product) {
                        $lastId = (int) $product->getId();
                        $documents[] = $catalog->buildDocument($product);
                    }
                    if ($documents === []) {
                        break;
                    }
                    $results = $client->collections[$collection]->documents->import($documents, ['action' => 'upsert']);
                    if (count($results) !== count($documents) || array_filter($results, static fn(array $row): bool => ($row['success'] ?? false) !== true)) {
                        throw new Mage_Core_Exception('Typesense rejected a product index batch.');
                    }
                }
            } catch (Exception $failure) {
                $this->removeCollection($client, $collection);
                throw $failure;
            }

            // A lost response may conceal a successful alias change. Keep both collections in that case.
            $client->aliases->upsert($alias, ['collection_name' => $collection]);
            if ($previous !== null && str_starts_with($previous, $prefix)
                && preg_match('/^[a-f0-9]{16}$/D', substr($previous, strlen($prefix))) === 1
            ) {
                $this->removeCollection($client, $previous);
            }
        });
    }

    public function refreshProduct(Mage_Core_Model_Store $store, int $productId): void
    {
        if (!Mage::helper('typesensesearch')->isConfigured($store)) {
            return;
        }

        $this->withViewLock($store, function () use ($store, $productId): void {
            $client = Mage::getService('typesensesearch/client')->getClient($store, true);
            $alias = Mage::helper('typesensesearch')->getCollection($store);
            $collection = $this->getActiveCollection($client, $alias);
            if ($collection === null) {
                return;
            }

            $catalog = Mage::getService('typesensesearch/catalog');
            $product = $catalog->getEligibleProducts($store, forIndex: true)->addIdFilter($productId)->getFirstItem();
            $documents = $client->collections[$collection]->documents;
            if ($product instanceof Mage_Catalog_Model_Product && $product->getId()) {
                $documents->upsert($catalog->buildDocument($product));
                return;
            }

            try {
                $documents[(string) $productId]->delete();
            } catch (ObjectNotFound) {
                return;
            }
        });
    }

    private function getActiveCollection(Client $client, string $alias): ?string
    {
        try {
            $name = $client->aliases[$alias]->retrieve()['collection_name'] ?? null;
        } catch (ObjectNotFound) {
            return null;
        }
        if (!is_string($name) || $name === '') {
            throw new Mage_Core_Exception('Typesense returned an invalid collection alias.');
        }
        return $name;
    }

    private function removeCollection(Client $client, string $collection): void
    {
        try {
            $client->collections[$collection]->delete();
        } catch (Exception) {
            Mage::log('A retired Typesense collection could not be removed.', Mage::LOG_WARNING);
        }
    }

    private function withViewLock(Mage_Core_Model_Store $store, Closure $update): void
    {
        $lock = Mage::getSingleton('core/lock');
        $name = 'typesense.index.' . $store->getId();
        if (!$lock->acquire($name)) {
            throw new Mage_Core_Exception_Conflict('The Typesense index is already updating for this store view.');
        }

        try {
            $update();
        } finally {
            $lock->release($name);
        }
    }
}
