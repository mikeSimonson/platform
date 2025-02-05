<?php

namespace Oro\Bundle\ApiBundle\Tests\Unit\Processor\CollectSubresources;

use Oro\Bundle\ApiBundle\Processor\CollectSubresources\CollectSubresourcesContext;
use Oro\Bundle\ApiBundle\Processor\CollectSubresources\LoadFromConfigBag;
use Oro\Bundle\ApiBundle\Processor\CollectSubresources\RemoveNotAvailableSubresources;
use Oro\Bundle\ApiBundle\Request\ApiActions;
use Oro\Bundle\ApiBundle\Request\ApiResource;
use Oro\Bundle\ApiBundle\Request\ApiResourceSubresources;
use Oro\Bundle\ApiBundle\Request\ApiResourceSubresourcesCollection;
use Oro\Bundle\ApiBundle\Request\RequestType;

class RemoveNotAvailableSubresourcesTest extends \PHPUnit\Framework\TestCase
{
    /** @var CollectSubresourcesContext */
    private $context;

    /** @var LoadFromConfigBag */
    private $processor;

    protected function setUp()
    {
        $this->context = new CollectSubresourcesContext();
        $this->context->getRequestType()->add(RequestType::REST);
        $this->context->setVersion('1.1');

        $this->processor = new RemoveNotAvailableSubresources();
    }

    /**
     * @param ApiResource $resource
     *
     * @return ApiResourceSubresourcesCollection
     */
    private function getApiResourceSubresources(ApiResource $resource)
    {
        $entitySubresources = new ApiResourceSubresources($resource->getEntityClass());
        $subresources = new ApiResourceSubresourcesCollection();
        $subresources->add($entitySubresources);

        return $subresources;
    }

    public function testNotAccessibleSubresource()
    {
        $entityClass = 'Test\Class';
        $targetEntityClass = 'Test\TargetClass';
        $resource = new ApiResource($entityClass);
        $subresources = $this->getApiResourceSubresources($resource);
        $subresource = $subresources->get($entityClass)->addSubresource('subresource1');
        $subresource->setTargetClassName($targetEntityClass);
        $subresource->setIsCollection(false);

        $this->context->setResources([$resource]);
        $this->context->setAccessibleResources([]);
        $this->context->setResult($subresources);
        $this->processor->process($this->context);

        self::assertEquals(
            [$entityClass => new ApiResourceSubresources($entityClass)],
            $this->context->getResult()->toArray()
        );
    }

    public function testAccessibleSubresourceWithoutExcludedActions()
    {
        $entityClass = 'Test\Class';
        $targetEntityClass = 'Test\TargetClass';
        $resource = new ApiResource($entityClass);
        $subresources = $this->getApiResourceSubresources($resource);
        $subresource = $subresources->get($entityClass)->addSubresource('subresource1');
        $subresource->setTargetClassName($targetEntityClass);
        $subresource->setIsCollection(false);

        $this->context->setResources([$resource]);
        $this->context->setAccessibleResources([$targetEntityClass]);
        $this->context->setResult($subresources);
        $this->processor->process($this->context);

        $expectedSubresources = new ApiResourceSubresources($entityClass);
        $expectedSubresources->addSubresource('subresource1', $subresource);

        self::assertEquals(
            [$entityClass => $expectedSubresources],
            $this->context->getResult()->toArray()
        );
    }

    public function testAccessibleSubresourceWhenAllActionsAreExcluded()
    {
        $entityClass = 'Test\Class';
        $targetEntityClass = 'Test\TargetClass';
        $resource = new ApiResource($entityClass);
        $subresources = $this->getApiResourceSubresources($resource);
        $subresource = $subresources->get($entityClass)->addSubresource('subresource1');
        $subresource->setTargetClassName($targetEntityClass);
        $subresource->setIsCollection(false);
        $subresource->setExcludedActions([
            ApiActions::GET_SUBRESOURCE,
            ApiActions::UPDATE_SUBRESOURCE,
            ApiActions::ADD_SUBRESOURCE,
            ApiActions::DELETE_SUBRESOURCE,
            ApiActions::GET_RELATIONSHIP,
            ApiActions::UPDATE_RELATIONSHIP,
            ApiActions::ADD_RELATIONSHIP,
            ApiActions::DELETE_RELATIONSHIP
        ]);

        $this->context->setResources([$resource]);
        $this->context->setAccessibleResources([$targetEntityClass]);
        $this->context->setResult($subresources);
        $this->processor->process($this->context);

        self::assertEquals(
            [$entityClass => new ApiResourceSubresources($entityClass)],
            $this->context->getResult()->toArray()
        );
    }

    public function testAccessibleSubresourceWhenNotAllActionsAreExcluded()
    {
        $entityClass = 'Test\Class';
        $targetEntityClass = 'Test\TargetClass';
        $resource = new ApiResource($entityClass);
        $subresources = $this->getApiResourceSubresources($resource);
        $subresource = $subresources->get($entityClass)->addSubresource('subresource1');
        $subresource->setTargetClassName($targetEntityClass);
        $subresource->setIsCollection(false);
        $subresource->setExcludedActions([
            ApiActions::GET_SUBRESOURCE,
            ApiActions::ADD_SUBRESOURCE,
            ApiActions::DELETE_SUBRESOURCE,
            ApiActions::GET_RELATIONSHIP,
            ApiActions::UPDATE_RELATIONSHIP,
            ApiActions::ADD_RELATIONSHIP,
            ApiActions::DELETE_RELATIONSHIP
        ]);

        $this->context->setResources([$resource]);
        $this->context->setAccessibleResources([$targetEntityClass]);
        $this->context->setResult($subresources);
        $this->processor->process($this->context);

        $expectedSubresources = new ApiResourceSubresources($entityClass);
        $expectedSubresources->addSubresource('subresource1', $subresource);

        self::assertEquals(
            [$entityClass => $expectedSubresources],
            $this->context->getResult()->toArray()
        );
    }
}
