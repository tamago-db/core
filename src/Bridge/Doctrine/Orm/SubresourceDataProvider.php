<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Bridge\Doctrine\Orm;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryResultItemExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\IdentifierManagerTrait;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Core\DataProvider\SubresourceDataProviderInterface;
use ApiPlatform\Core\Exception\ResourceClassNotSupportedException;
use ApiPlatform\Core\Exception\RuntimeException;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * Subresource data provider for the Doctrine ORM.
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class SubresourceDataProvider implements SubresourceDataProviderInterface
{
    use IdentifierManagerTrait;

    private $managerRegistry;
    private $collectionExtensions;
    private $itemExtensions;

    /**
     * @param ManagerRegistry                        $managerRegistry
     * @param PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory
     * @param PropertyMetadataFactoryInterface       $propertyMetadataFactory
     * @param QueryCollectionExtensionInterface[]    $collectionExtensions
     * @param QueryItemExtensionInterface[]          $itemExtensions
     */
    public function __construct(ManagerRegistry $managerRegistry, PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, array $collectionExtensions = [], array $itemExtensions = [])
    {
        $this->managerRegistry = $managerRegistry;
        $this->propertyNameCollectionFactory = $propertyNameCollectionFactory;
        $this->propertyMetadataFactory = $propertyMetadataFactory;
        $this->collectionExtensions = $collectionExtensions;
        $this->itemExtensions = $itemExtensions;
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function getSubresource(string $resourceClass, array $identifiers, array $context, string $operationName = null)
    {
        $manager = $this->managerRegistry->getManagerForClass($resourceClass);
        if (null === $manager) {
            throw new ResourceClassNotSupportedException();
        }

        $repository = $manager->getRepository($resourceClass);
        if (!method_exists($repository, 'createQueryBuilder')) {
            throw new RuntimeException('The repository class must have a "createQueryBuilder" method.');
        }

        if (!isset($context['identifiers']) || !isset($context['property'])) {
            throw new ResourceClassNotSupportedException('The given resource class is not a subresource.');
        }

        $originAlias = 'o';
        $queryBuilder = $repository->createQueryBuilder($originAlias);
        $queryNameGenerator = new QueryNameGenerator();
        $previousQueryBuilder = null;
        $previousAlias = null;

        $num = count($context['identifiers']);

        while ($num--) {
            list($identifier, $identifierResourceClass) = $context['identifiers'][$num];
            $previousAssociationProperty = $context['identifiers'][$num + 1][0] ?? $context['property'];

            $manager = $this->managerRegistry->getManagerForClass($identifierResourceClass);

            if (!$manager instanceof EntityManagerInterface) {
                throw new RuntimeException("The manager for $identifierResourceClass must be an EntityManager.");
            }

            $classMetadata = $manager->getClassMetadata($identifierResourceClass);

            if (!$classMetadata instanceof ClassMetadataInfo) {
                throw new RuntimeException("The class metadata for $identifierResourceClass must be an instance of ClassMetadataInfo.");
            }

            $qb = $manager->createQueryBuilder();
            $alias = $queryNameGenerator->generateJoinAlias($identifier);
            $relationType = $classMetadata->getAssociationMapping($previousAssociationProperty)['type'];
            $normalizedIdentifiers = $this->normalizeIdentifiers($identifiers[$identifier], $manager, $identifierResourceClass);

            switch ($relationType) {
                //MANY_TO_MANY relations need an explicit join so that the identifier part can be retrieved
                case ClassMetadataInfo::MANY_TO_MANY:
                    $joinAlias = $queryNameGenerator->generateJoinAlias($previousAssociationProperty);

                    $qb->select($joinAlias)
                        ->from($identifierResourceClass, $alias)
                        ->innerJoin("$alias.$previousAssociationProperty", $joinAlias);

                    break;
                case ClassMetadataInfo::ONE_TO_MANY:
                    $mappedBy = $classMetadata->getAssociationMapping($previousAssociationProperty)['mappedBy'];

                    // first pass, o.property instead of alias.property
                    if (null === $previousQueryBuilder) {
                        $originAlias = "$originAlias.$mappedBy";
                    } else {
                        $previousAlias = "$previousAlias.$mappedBy";
                    }

                    $qb->select($alias)
                        ->from($identifierResourceClass, $alias);
                    break;
                default:
                    $qb->select("IDENTITY($alias.$previousAssociationProperty)")
                        ->from($identifierResourceClass, $alias);
            }

            // Add where clause for identifiers
            foreach ($normalizedIdentifiers as $key => $value) {
                $placeholder = $queryNameGenerator->generateParameterName($key);
                $qb->andWhere("$alias.$key = :$placeholder");
                $queryBuilder->setParameter($placeholder, $value);
            }

            // recurse queries
            if (null === $previousQueryBuilder) {
                $previousQueryBuilder = $qb;
            } else {
                $previousQueryBuilder->andWhere($qb->expr()->in($previousAlias, $qb->getDQL()));
            }

            $previousAlias = $alias;
        }

        /*
         * The following translate to this pseudo-dql:
         *
         * SELECT thirdLevel WHERE thirdLevel IN (
         *   SELECT thirdLevel FROM relatedDummies WHERE relatedDummies = ? AND relatedDummies IN (
         *     SELECT relatedDummies FROM Dummy WHERE Dummy = ?
         *   )
         * )
         *
         * By using subqueries, we're forcing the SQL execution plan to go through indexes on doctrine identifiers.
         */
        $queryBuilder->where(
            $queryBuilder->expr()->in($originAlias, $previousQueryBuilder->getDQL())
        );

        if (true === $context['collection']) {
            foreach ($this->collectionExtensions as $extension) {
                $extension->applyToCollection($queryBuilder, $queryNameGenerator, $resourceClass, $operationName);

                if ($extension instanceof QueryResultCollectionExtensionInterface && $extension->supportsResult($resourceClass, $operationName)) {
                    return $extension->getResult($queryBuilder);
                }
            }
        } else {
            foreach ($this->itemExtensions as $extension) {
                $extension->applyToItem($queryBuilder, $queryNameGenerator, $resourceClass, $identifiers, $operationName, $context);

                if ($extension instanceof QueryResultItemExtensionInterface && $extension->supportsResult($resourceClass, $operationName)) {
                    return $extension->getResult($queryBuilder);
                }
            }
        }

        $query = $queryBuilder->getQuery();

        return $context['collection'] ? $query->getResult() : $query->getOneOrNullResult();
    }
}
