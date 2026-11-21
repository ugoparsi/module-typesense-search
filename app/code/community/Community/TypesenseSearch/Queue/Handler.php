<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

class Community_TypesenseSearch_Queue_Handler
{
    #[Maho\Config\MessageHandler]
    public function refreshProduct(Community_TypesenseSearch_Queue_Message_ProductChanged $message): void
    {
        $store = Mage::app()->getStores()[$message->storeId] ?? null;
        if ($store !== null) {
            try {
                Mage::getService('typesensesearch/index')->refreshProduct($store, $message->productId);
            } catch (Mage_Core_Exception_Conflict $busy) {
                throw new RecoverableMessageHandlingException($busy->getMessage(), previous: $busy, retryDelay: 10000);
            }
        }
    }
}
