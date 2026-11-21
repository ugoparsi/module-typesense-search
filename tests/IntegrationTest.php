<?php

/**
 * SPDX-FileCopyrightText: 2026 Typesense Search contributors
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Maho\Queue\QueueManager;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

uses(Tests\MahoBackendTestCase::class);

final class TypesenseTestTransport implements ClientInterface
{
    public array $collections = [];
    public array $aliases = [];
    public array $requests = [];
    public ?array $searchResponse = null;
    public bool $failImport = false;
    public bool $failSearch = false;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        $method = $request->getMethod();
        $body = (string) $request->getBody();
        $this->requests[] = [$method, $segments, $request->getUri()->getQuery()];

        if ($segments[0] === 'aliases') {
            $alias = $segments[1];
            if ($method === 'PUT') {
                $this->aliases[$alias] = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['collection_name'];
            }
            return isset($this->aliases[$alias])
                ? $this->json(['collection_name' => $this->aliases[$alias]])
                : $this->json(['message' => 'Alias unavailable'], 404);
        }

        if ($segments === ['collections'] && $method === 'POST') {
            $schema = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->collections[$schema['name']] = [];
            return $this->json($schema, 201);
        }

        if ($segments[0] === 'collections' && isset($segments[1])) {
            $collection = $this->aliases[$segments[1]] ?? $segments[1];
            if (!isset($this->collections[$collection])) {
                return $this->json(['message' => 'Collection unavailable'], 404);
            }
            if ($method === 'DELETE' && count($segments) === 2) {
                unset($this->collections[$collection]);
                return $this->json([]);
            }
            if (($segments[3] ?? null) === 'search') {
                return $this->failSearch
                    ? $this->json(['message' => 'Temporary failure'], 503)
                    : $this->json($this->searchResponse ?? ['found' => 0, 'hits' => []]);
            }
            if (($segments[3] ?? null) === 'import') {
                $outcomes = [];
                foreach (explode("\n", $body) as $line) {
                    $document = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (!$this->failImport) {
                        $this->collections[$collection][$document['id']] = $document;
                    }
                    $outcomes[] = json_encode(['success' => !$this->failImport], JSON_THROW_ON_ERROR);
                }
                return new Response(200, ['Content-Type' => 'text/plain'], implode("\n", $outcomes));
            }
            if ($method === 'POST' && $segments[2] === 'documents') {
                $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $this->collections[$collection][$document['id']] = $document;
                return $this->json($document);
            }
            if ($method === 'DELETE' && $segments[2] === 'documents') {
                if (!isset($this->collections[$collection][$segments[3]])) {
                    return $this->json(['message' => 'Document unavailable'], 404);
                }
                unset($this->collections[$collection][$segments[3]]);
                return $this->json([]);
            }
        }

        throw new LogicException('Unexpected Typesense fixture request.');
    }

    private function json(array $body, int $code = 200): ResponseInterface
    {
        return new Response($code, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}

final class TypesenseTestClient extends Community_TypesenseSearch_Service_Client
{
    public static TypesenseTestTransport $transport;

    #[\Override]
    protected function createTransport(bool $write): ClientInterface
    {
        return self::$transport;
    }
}

function typesenseConfigure(Mage_Core_Model_Store $store, bool $enabled = true): void
{
    $helper = Mage::helper('core');
    $config = Community_TypesenseSearch_Helper_Data::CONFIG;
    $store->setConfig($config . 'enabled', $enabled ? '1' : '0');
    $store->setConfig($config . 'endpoint', 'http://127.0.0.1:1');
    $store->setConfig($config . 'collection_prefix', 'test');
    $store->setConfig($config . 'search_key', $helper->encrypt('synthetic-search'));
    $store->setConfig($config . 'index_key', $helper->encrypt('synthetic-index'));
    $store->setConfig(Mage_CatalogInventory_Helper_Data::XML_PATH_SHOW_OUT_OF_STOCK, '1');
}

function typesenseCreateProduct(string $name, string $sku, array $websiteIds, int $status = 1, int $visibility = 4): Mage_Catalog_Model_Product
{
    $attributeSetId = Mage::getSingleton('eav/config')->getEntityType('catalog_product')->getDefaultAttributeSetId();
    $product = Mage::getModel('catalog/product')->setStoreId(0)->setName($name)->setSku($sku)->setTypeId('simple')
        ->setAttributeSetId((int) $attributeSetId)->setWebsiteIds($websiteIds)->setPrice(27.45)
        ->setStatus($status)->setVisibility($visibility);
    $product->save();
    return $product;
}

function typesenseCreateView(string $code, Mage_Core_Model_Store $base, bool $separateWebsite = false): Mage_Core_Model_Store
{
    $website = $base->getWebsite();
    $group = $base->getGroup();
    if ($separateWebsite) {
        $website = Mage::getModel('core/website')->setCode($code)->setName($code)->save();
        $group = Mage::getModel('core/store_group')->setName($code)->setWebsiteId((int) $website->getId())
            ->setRootCategoryId((int) $base->getRootCategoryId())->save();
        $website->setDefaultGroupId((int) $group->getId())->save();
    }
    $view = Mage::getModel('core/store')->setCode($code)->setName($code)
        ->setWebsiteId((int) $website->getId())->setGroupId((int) $group->getId())->setIsActive(true)->save();
    if ($separateWebsite) {
        $group->setDefaultStoreId((int) $view->getId())->save();
    }
    return $view;
}

function typesenseRequest(string $uri, string $controllerName, string $action): Mage_Core_Controller_Request_Http
{
    $request = new Mage_Core_Controller_Request_Http(Request::create($uri, 'GET', ['q' => 'Sample product']));
    $request->setPathInfo($uri)->setRouteName('typesensesearch')->setControllerName($controllerName)->setActionName($action)
        ->setControllerModule('Community_TypesenseSearch')->setDispatched(true);
    Mage::app()->setRequest($request);
    return $request;
}

function typesenseInstallTransport(): void
{
    Mage::getConfig()->setNode('global/services/typesensesearch/rewrite/client', TypesenseTestClient::class);
    expect(Mage::getService('typesensesearch/client'))->toBeInstanceOf(TypesenseTestClient::class);
}

function typesenseLocation(Mage_Core_Controller_Response_Http $response): string
{
    foreach ($response->getHeaders() as $header) {
        if (strcasecmp($header['name'], 'Location') === 0) {
            return $header['value'];
        }
    }
    return '';
}

/** @return list<int> */
function typesenseQueuedViews(Maho\Db\Adapter\AdapterInterface $adapter, int $productId): array
{
    $rows = $adapter->fetchCol($adapter->select()->from(QueueManager::tableName(), 'body')
        ->where('queue = ?', 'typesensesearch')->order('message_id'));
    $views = [];
    foreach ($rows as $body) {
        $message = QueueManager::serializer()->decode([
            'body' => $body,
            'headers' => ['type' => Community_TypesenseSearch_Queue_Message_ProductChanged::class],
        ])->getMessage();
        if ($message->productId === $productId) {
            $views[] = $message->storeId;
        }
    }
    return $views;
}

beforeEach(function (): void {
    TypesenseTestClient::$transport = new TypesenseTestTransport();
    Mage::app()->setCurrentStore(Mage::app()->getDefaultStoreView());
    $this->store = Mage::app()->getStore();
    $this->adapter = Mage::getSingleton('core/resource')->getConnection('core_write');
    $this->adapter->beginTransaction();
});

afterEach(function (): void {
    if ($this->adapter->getTransactionLevel() > 0) {
        $this->adapter->rollBack();
    }
    Mage::app()->cleanCache([Mage_Core_Model_Store::CACHE_TAG, Mage_Core_Model_Store_Group::CACHE_TAG, Mage_Core_Model_Website::CACHE_TAG]);
    QueueManager::reset();
});

it('preserves the native routes and makes no requests when disabled', function (): void {
    expect(Mage::helper('catalogsearch')->getResultUrl())->toContain('catalogsearch/result')
        ->and(Mage::helper('catalogsearch')->getSuggestUrl())->toContain('catalogsearch/ajax/suggest');

    Mage::getModel('typesensesearch/indexer')->reindexAll();
    $request = typesenseRequest('/typesensesearch/result', 'result', 'index');
    $response = new Mage_Core_Controller_Response_Http();
    new Community_TypesenseSearch_ResultController($request, $response)->indexAction();

    expect(typesenseLocation($response))->toContain('catalogsearch/result')
        ->and(TypesenseTestClient::$transport->requests)->toBe([]);
});

it('publishes only complete indexes, renders prices and filters simulated results in the active view', function (): void {
    $other = typesenseCreateView('test_other', $this->store, true);
    Mage::app()->cleanCache([Mage_Core_Model_Store::CACHE_TAG, Mage_Core_Model_Store_Group::CACHE_TAG, Mage_Core_Model_Website::CACHE_TAG]);
    Mage::app()->reinitStores();
    typesenseInstallTransport();
    $store = Mage::app()->getDefaultStoreView();
    Mage::app()->setCurrentStore($store);
    typesenseConfigure($store);
    $other = Mage::app()->getStore((int) $other->getId());
    typesenseConfigure($other);
    $first = typesenseCreateProduct('Sample first', 'test-first', [(int) $store->getWebsiteId()]);
    $second = typesenseCreateProduct('Sample second', 'test-second', [(int) $store->getWebsiteId()]);
    $foreign = typesenseCreateProduct('Sample foreign', 'test-foreign', [(int) $other->getWebsiteId()]);
    $disabled = typesenseCreateProduct('Sample disabled', 'test-disabled', [(int) $store->getWebsiteId()], 2);
    $hidden = typesenseCreateProduct('Sample hidden', 'test-hidden', [(int) $store->getWebsiteId()], 1, 2);

    $second->setStoreId((int) $store->getId())->setName('Sample second in this view');
    $second->getResource()->saveAttribute($second, 'name');
    $processes = Mage::getService('index/process');
    $processes->reindex($processes->getByCode('typesensesearch'));
    $index = Mage::getService('typesensesearch/index');
    $backend = TypesenseTestClient::$transport;
    $alias = Mage::helper('typesensesearch')->getCollection($store);
    $otherAlias = Mage::helper('typesensesearch')->getCollection($other);
    $active = $backend->aliases[$alias];

    expect(array_keys($backend->collections[$active]))->toBe([(int) $first->getId(), (int) $second->getId()])
        ->and($backend->collections[$active][(string) $second->getId()]['name'])->toBe('Sample second in this view')
        ->and(array_keys($backend->collections[$backend->aliases[$otherAlias]]))->toBe([(int) $foreign->getId()]);
    $backend->failImport = true;
    expect(fn() => $index->rebuild($store))->toThrow(Mage_Core_Exception::class, 'rejected a product index batch')
        ->and($backend->aliases[$alias])->toBe($active)
        ->and($backend->collections)->toHaveCount(2);
    $backend->failImport = false;
    $index->rebuild($store);
    expect($backend->aliases[$alias])->not->toBe($active)
        ->and($backend->collections)->not->toHaveKey($active)
        ->and($backend->collections)->toHaveCount(2);

    Mage::getModel('catalog/product_indexer_price')->reindexAll();
    $hits = array_map(fn($product): array => ['document' => ['id' => (string) $product->getId()]], [$foreign, $disabled, $second, $hidden, $first]);
    $backend->searchResponse = ['found' => count($hits), 'hits' => $hits];
    $response = new Mage_Core_Controller_Response_Http();
    $request = typesenseRequest('/typesensesearch/ajax/suggest', 'ajax', 'suggest');
    new Community_TypesenseSearch_AjaxController($request, $response)->suggestAction();

    $body = (string) $response->getBody();
    expect($body)->toContain('Sample second in this view', 'Sample first', '27.45')
        ->not->toContain('Sample foreign', 'Sample disabled', 'Sample hidden');
    expect(strpos($body, 'Sample second') < strpos($body, 'Sample first'))->toBeTrue();

    $request = typesenseRequest('/typesensesearch/result', 'result', 'index');
    $response = Mage::app()->getResponse();
    new Community_TypesenseSearch_ResultController($request, $response)->indexAction();
    expect((string) $response->getBody())->toContain('Sample second in this view', 'Sample first', '27.45')
        ->not->toContain('Sample foreign');
});

it('falls back on a failed search and keeps an empty response on a successful miss', function (): void {
    $store = $this->store;
    typesenseConfigure($store);
    typesenseInstallTransport();
    $backend = TypesenseTestClient::$transport;
    Mage::getService('typesensesearch/index')->rebuild($store);
    $request = typesenseRequest('/typesensesearch/ajax/suggest', 'ajax', 'suggest');
    $response = new Mage_Core_Controller_Response_Http();
    new Community_TypesenseSearch_AjaxController($request, $response)->suggestAction();
    expect($response->getHttpResponseCode())->toBe(200)
        ->and((string) $response->getBody())->toContain('Your search returns no results.');

    $request = typesenseRequest('/typesensesearch/result', 'result', 'index');
    $response = Mage::app()->getResponse();
    new Community_TypesenseSearch_ResultController($request, $response)->indexAction();
    expect($response->getHttpResponseCode())->toBe(200)
        ->and((string) $response->getBody())->toContain('Your search returns no results.');

    $backend->failSearch = true;
    $request = typesenseRequest('/typesensesearch/ajax/suggest', 'ajax', 'suggest');
    $response = new Mage_Core_Controller_Response_Http();
    new Community_TypesenseSearch_AjaxController($request, $response)->suggestAction();
    expect(typesenseLocation($response))->toContain('catalogsearch/ajax/suggest');
    $request = typesenseRequest('/typesensesearch/result', 'result', 'index');
    $response = new Mage_Core_Controller_Response_Http();
    new Community_TypesenseSearch_ResultController($request, $response)->indexAction();
    expect(typesenseLocation($response))->toContain('catalogsearch/result');
});

it('keeps updates made during indexing and reconciles the latest product state on retry', function (): void {
    $store = $this->store;
    typesenseConfigure($store);
    typesenseInstallTransport();
    $website = (int) $store->getWebsiteId();
    $product = typesenseCreateProduct('Sample original', 'test-queued', [$website]);
    $storeId = (int) $store->getId();
    $productId = (int) $product->getId();
    $index = Mage::getService('typesensesearch/index');
    $index->rebuild($store);
    $queue = $this->adapter->select()->from(QueueManager::tableName(), 'COUNT(*)')->where('queue = ?', 'typesensesearch');
    $queued = (int) $this->adapter->fetchOne($queue);
    $lock = Mage::getSingleton('core/lock');
    $lockName = 'typesense.index.' . $storeId;
    $handler = new Community_TypesenseSearch_Queue_Handler();
    $message = new Community_TypesenseSearch_Queue_Message_ProductChanged($storeId, $productId);
    expect($lock->acquire($lockName))->toBeTrue();
    try {
        $product->setName('Sample earlier')->save();
        $product->setName('Sample latest')->save();
        expect((int) $this->adapter->fetchOne($queue))->toBe($queued + 2)
            ->and(fn() => $handler->refreshProduct($message))->toThrow(Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException::class, 'already updating');
    } finally {
        $lock->release($lockName);
    }

    $handler->refreshProduct($message);
    $handler->refreshProduct($message);
    $backend = TypesenseTestClient::$transport;
    $collection = $backend->aliases[Mage::helper('typesensesearch')->getCollection($store)];
    expect($backend->collections[$collection][$productId]['name'])->toBe('Sample latest');
    $product->delete();
    $handler->refreshProduct($message);
    $handler->refreshProduct($message);
    expect($backend->collections[$collection])->toBe([]);
});

it('queues the views affected by a view, website and global product edit', function (): void {
    $storeId = (int) $this->store->getId();
    $siblingId = (int) typesenseCreateView('test_sibling', $this->store)->getId();
    $otherId = (int) typesenseCreateView('test_other', $this->store, true)->getId();
    Mage::app()->cleanCache([Mage_Core_Model_Store::CACHE_TAG, Mage_Core_Model_Store_Group::CACHE_TAG, Mage_Core_Model_Website::CACHE_TAG]);
    Mage::app()->reinitStores();
    $store = Mage::app()->getStore($storeId);
    $other = Mage::app()->getStore($otherId);
    Mage::app()->setCurrentStore($store);
    foreach ([$store, Mage::app()->getStore($siblingId), $other] as $view) {
        typesenseConfigure($view);
    }
    typesenseInstallTransport();

    $product = typesenseCreateProduct('Sample original', 'test-scopes', [(int) $store->getWebsiteId(), (int) $other->getWebsiteId()]);
    $productId = (int) $product->getId();
    $adapter = $this->adapter;
    $edit = function (string $field, string|int $value) use ($adapter, $storeId, $productId): array {
        $before = count(typesenseQueuedViews($adapter, $productId));
        Mage::getModel('catalog/product')->setStoreId($storeId)->load($productId)->setData($field, $value)->save();
        $views = array_slice(typesenseQueuedViews($adapter, $productId), $before);
        sort($views);
        return $views;
    };

    expect($edit('name', 'Sample in first view'))->toBe([$storeId])
        ->and($edit('status', Mage_Catalog_Model_Product_Status::STATUS_DISABLED))->toBe([$storeId, $siblingId])
        ->and($edit('sku', 'test-global-sku'))->toBe([$storeId, $siblingId, $otherId]);
});

it('rolls back a queued refresh when the product transaction rolls back', function (): void {
    typesenseConfigure($this->store);
    typesenseInstallTransport();
    $product = typesenseCreateProduct('Sample rolled back', 'test-rollback', [(int) $this->store->getWebsiteId()]);
    $productId = (int) $product->getId();
    expect(typesenseQueuedViews($this->adapter, $productId))->toBe([(int) $this->store->getId()]);

    $this->adapter->rollBack();
    expect(typesenseQueuedViews($this->adapter, $productId))->toBe([])
        ->and(Mage::getModel('catalog/product')->load($productId)->getId())->toBeNull();
});
