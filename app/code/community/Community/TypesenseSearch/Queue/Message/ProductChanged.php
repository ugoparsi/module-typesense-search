<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Community_TypesenseSearch
 */

declare(strict_types=1);

final readonly class Community_TypesenseSearch_Queue_Message_ProductChanged
{
    public function __construct(
        public int $storeId,
        public int $productId,
    ) {}
}
