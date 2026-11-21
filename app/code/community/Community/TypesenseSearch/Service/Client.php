<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

use Maho\Http\Client as HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Typesense\Client;

class Community_TypesenseSearch_Service_Client
{
    public function getClient(Mage_Core_Model_Store $store, bool $write = false): Client
    {
        $helper = Mage::helper('typesensesearch');
        $node = $helper->getNode($store);
        $path = Community_TypesenseSearch_Helper_Data::CONFIG . ($write ? 'index_key' : 'search_key');
        $key = Mage::helper('core')->decrypt((string) Mage::getStoreConfig($path, $store));
        if ($node === null || !is_string($key) || $key === '') {
            throw new Mage_Core_Exception('The Typesense connection settings are incomplete.');
        }

        return new Client([
            'nodes' => [$node],
            'api_key' => $key,
            'client' => $this->createTransport($write),
            'num_retries' => 0,
            'log_level' => Monolog\Level::Emergency->value,
        ]);
    }

    protected function createTransport(bool $write): ClientInterface
    {
        $timeout = $write ? 15 : 2;
        $factory = new Psr17Factory();
        return new Psr18Client(HttpClient::create([
            'timeout' => $timeout,
            'max_duration' => $timeout,
            'max_connect_duration' => $timeout,
            'max_redirects' => 0,
        ]), $factory, $factory);
    }
}
