<?php

namespace Oro\Bundle\SecurityBundle\Authentication\Token;

use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Role\Role;

/**
 * Organization aware token.
 */
class OrganizationToken extends AbstractToken implements OrganizationContextTokenInterface
{
    use OrganizationContextTokenSerializerTrait;
    /**
     * @param Organization             $organization The organization
     * @param Role[]|string[]          $roles        An array of roles
     */
    public function __construct(Organization $organization, array $roles = [])
    {
        parent::__construct($roles);

        $this->setOrganizationContext($organization);
        parent::setAuthenticated(true);
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials()
    {
        return ''; // anonymous credentials
    }
}
