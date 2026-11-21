<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

class Community_TypesenseSearch_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const string CONFIG = 'typesensesearch/general/';

    public function isConfigured(Mage_Core_Model_Store $store): bool
    {
        return !$store->isAdmin() && $store->getIsActive()
            && Mage::getStoreConfigFlag(self::CONFIG . 'enabled', $store)
            && $this->getNode($store) !== null
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $this->getPrefix($store)) === 1
            && Mage::getStoreConfig(self::CONFIG . 'search_key', $store)
            && Mage::getStoreConfig(self::CONFIG . 'index_key', $store);
    }

    /** @return array{host: string, protocol: string, port: int, path: string}|null */
    public function getNode(Mage_Core_Model_Store $store): ?array
    {
        $url = parse_url(trim((string) Mage::getStoreConfig(self::CONFIG . 'endpoint', $store)));
        if ($url === false || empty($url['host']) || !in_array($url['scheme'] ?? null, ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        ) {
            return null;
        }
        return [
            'host' => $url['host'],
            'protocol' => $url['scheme'],
            'port' => $url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80),
            'path' => rtrim($url['path'] ?? '', '/'),
        ];
    }

    public function getCollection(Mage_Core_Model_Store $store): string
    {
        return $this->getPrefix($store) . '_products_' . $store->getId();
    }

    /** @return list<Mage_Core_Model_Store> */
    public function getConfiguredViews(): array
    {
        return array_values(array_filter(Mage::app()->getStores(), $this->isConfigured(...)));
    }

    private function getPrefix(Mage_Core_Model_Store $store): string
    {
        return trim((string) Mage::getStoreConfig(self::CONFIG . 'collection_prefix', $store));
    }
}
