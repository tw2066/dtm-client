<?php

namespace DtmClientTest\Cases;

use DtmClient\Api\ApiInterface;
use DtmClient\Constants\Protocol;
use DtmClient\Constants\TransType;
use DtmClient\Context\Context;
use DtmClient\Grpc\Message\DtmBranchRequest;
use DtmClient\Saga;
use DtmClient\TransContext;

class SagaTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 清理 Context，避免测试间状态泄漏
        Context::set('DtmClient\Saga.concurrent', false);
        Context::set('DtmClient\Saga.orders', []);
    }

    public function testInit()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $api->shouldReceive('generateGid')->andReturn('GidStub');

        $saga = new Saga($api);
        $saga->init();
        $this->assertSame('GidStub', TransContext::getGid());
        $this->assertSame(TransType::SAGA, TransContext::getTransType());
        $this->assertSame('', TransContext::getBranchId());

        $saga->init('test');
        $this->assertSame('test', TransContext::getGid());
        $this->cleanTransContext();
    }

    public function testAddUseHttp()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $api->shouldReceive('getProtocol')->andReturn(Protocol::HTTP);

        $saga = new Saga($api);

        $result = $saga->add('testAction', 'compensate', ['test' => 'message']);
        $this->assertEquals($saga, $result);
        $this->assertEquals(TransContext::getSteps(), [['action' => 'testAction', 'compensate' => 'compensate']]);
        $this->assertEquals(TransContext::getPayloads(), [json_encode(['test' => 'message'])]);
        $this->cleanTransContext();
    }

    public function testAddUseJsonRpcHttp()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $api->shouldReceive('getProtocol')->andReturn(Protocol::JSONRPC_HTTP);

        $saga = new Saga($api);

        $result = $saga->add('testAction', 'compensate', ['test' => 'message']);
        $this->assertEquals($saga, $result);
        $this->assertEquals(TransContext::getSteps(), [['action' => 'testAction', 'compensate' => 'compensate']]);
        $this->assertEquals(TransContext::getPayloads(), [json_encode(['test' => 'message'])]);
        $this->cleanTransContext();
    }

    public function testAddUseGrpc()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $api->shouldReceive('getProtocol')->andReturn(Protocol::GRPC);

        $saga = new Saga($api);

        $payload = new DtmBranchRequest();
        $payload->setData(['test' => 'message']);
        $result = $saga->add('testAction', 'compensate', $payload);
        $this->assertEquals($saga, $result);
        $this->assertEquals(TransContext::getSteps(), [['action' => 'testAction', 'compensate' => 'compensate']]);
        $this->assertEquals(TransContext::getBinPayloads(), [$payload->serializeToString()]);
    }

    public function testAddBranchOrder()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $saga = new Saga($api);

        $saga->addBranchOrder(1, ['preBranches']);
        $saga->addBranchOrder(2, ['preBranches1']);

        $orders = Context::get('DtmClient\Saga.orders');
        $this->assertEquals([1 => ['preBranches'], 2 => ['preBranches1']], $orders);
    }

    public function testEnableConcurrent()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $saga = new Saga($api);

        $saga->enableConcurrent();

        $concurrent = Context::get('DtmClient\Saga.concurrent');
        $this->assertTrue($concurrent);
    }

    public function testEnableConcurrentWithoutAddBranchOrders()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $saga = new Saga($api);
        $saga->enableConcurrent();

        $orders = Context::get('DtmClient\Saga.orders');
        $this->assertSame([], $orders);
    }

    public function testSubmit()
    {
        $api = \Mockery::mock(ApiInterface::class);

        $api->shouldReceive('submit')->andReturnTrue();

        $saga = new Saga($api);

        $result = $saga->submit();
        $this->assertTrue($result);
        $this->assertSame(null, TransContext::getCustomData());

        $saga->enableConcurrent();
        $saga->addBranchOrder(0, ['test' => 'test']);
        $result = $saga->submit();
        $this->assertTrue($result);
        $this->assertSame(json_encode([
            'concurrent' => true,
            'orders' => [
                ['test' => 'test']
            ],
        ]), TransContext::getCustomData());
    }

    public function cleanTransContext()
    {
        // Clean TransContext
        TransContext::setSteps([]);
        TransContext::setPayloads([]);
        TransContext::setGid('');
        TransContext::setBinPayloads([]);
    }

}