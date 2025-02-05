<?php

namespace Oro\Bundle\ApiBundle\Processor\Subresource\Shared;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\ApiBundle\Config\EntityDefinitionConfig;
use Oro\Bundle\ApiBundle\Metadata\EntityIdMetadataInterface;
use Oro\Bundle\ApiBundle\Processor\Subresource\SubresourceContext;
use Oro\Bundle\ApiBundle\Util\DoctrineHelper;
use Oro\Bundle\ApiBundle\Util\EntityIdHelper;
use Oro\Component\ChainProcessor\ContextInterface;
use Oro\Component\ChainProcessor\ProcessorInterface;
use Oro\Component\EntitySerializer\QueryFactory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Loads the parent entity from the database and checks whether an VIEW access to it is granted.
 */
class ValidateParentEntityAccess implements ProcessorInterface
{
    /** @var DoctrineHelper */
    private $doctrineHelper;

    /** @var EntityIdHelper */
    private $entityIdHelper;

    /** @var QueryFactory */
    private $queryFactory;

    /**
     * @param DoctrineHelper $doctrineHelper
     * @param EntityIdHelper $entityIdHelper
     * @param QueryFactory   $queryFactory
     */
    public function __construct(
        DoctrineHelper $doctrineHelper,
        EntityIdHelper $entityIdHelper,
        QueryFactory $queryFactory
    ) {
        $this->doctrineHelper = $doctrineHelper;
        $this->entityIdHelper = $entityIdHelper;
        $this->queryFactory = $queryFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContextInterface $context)
    {
        /** @var SubresourceContext $context */

        $parentConfig = $context->getParentConfig();
        if (null === $parentConfig) {
            // unsupported API resource
            return;
        }

        if (null === $parentConfig->getField($context->getAssociationName())) {
            // skip sub-resources that do not associated with any field in the parent entity config
            return;
        }

        $parentEntityClass = $context->getManageableParentEntityClass($this->doctrineHelper);
        if (!$parentEntityClass) {
            // only manageable entities or resources based on manageable entities are supported
            return;
        }

        $parentMetadata = $context->getParentMetadata();
        if (null === $parentMetadata) {
            // unsupported API resource
            return;
        }

        $this->checkParentEntityAccess(
            $parentEntityClass,
            $context->getParentId(),
            $parentConfig,
            $parentMetadata
        );
    }

    /**
     * @param string                    $parentEntityClass
     * @param mixed                     $parentEntityId
     * @param EntityDefinitionConfig    $parentConfig
     * @param EntityIdMetadataInterface $parentMetadata
     */
    private function checkParentEntityAccess(
        string $parentEntityClass,
        $parentEntityId,
        EntityDefinitionConfig $parentConfig,
        EntityIdMetadataInterface $parentMetadata
    ): void {
        // try to get an entity by ACL protected query
        $data = $this->queryFactory
            ->getQuery($this->getQueryBuilder($parentEntityClass, $parentEntityId, $parentMetadata), $parentConfig)
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);
        if (!$data) {
            // use a query without ACL protection to check if an entity exists in DB
            $data = $this->getQueryBuilder($parentEntityClass, $parentEntityId, $parentMetadata)
                ->getQuery()
                ->getOneOrNullResult(Query::HYDRATE_ARRAY);
            if ($data) {
                throw new AccessDeniedException('No access to the parent entity.');
            }
            throw new NotFoundHttpException('The parent entity does not exist.');
        }
    }

    /**
     * @param string                    $parentEntityClass
     * @param mixed                     $parentEntityId
     * @param EntityIdMetadataInterface $parentMetadata
     *
     * @return QueryBuilder
     */
    private function getQueryBuilder(
        string $parentEntityClass,
        $parentEntityId,
        EntityIdMetadataInterface $parentMetadata
    ): QueryBuilder {
        $qb = $this->doctrineHelper->createQueryBuilder($parentEntityClass, 'e');
        $idFieldNames = $this->doctrineHelper->getEntityIdentifierFieldNamesForClass($parentEntityClass);
        if (\count($idFieldNames) !== 0) {
            $qb->select('e.' . \reset($idFieldNames));
        }
        $this->entityIdHelper->applyEntityIdentifierRestriction(
            $qb,
            $parentEntityId,
            $parentMetadata
        );

        return $qb;
    }
}
