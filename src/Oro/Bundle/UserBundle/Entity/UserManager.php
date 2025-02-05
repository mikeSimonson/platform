<?php

namespace Oro\Bundle\UserBundle\Entity;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Util\ClassUtils;
use Oro\Bundle\EntityExtendBundle\Provider\EnumValueProvider;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\UserBundle\Mailer\Processor;
use Oro\Bundle\UserBundle\Security\UserLoaderInterface;
use Oro\Component\DependencyInjection\ServiceLink;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Symfony\Component\Security\Core\Role\Role;

/**
 * Provides a set of methods to simplify manage of the User entity.
 */
class UserManager extends BaseUserManager
{
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_EXPIRED = 'expired';

    private const AUTH_STATUS_ENUM_CODE = 'auth_status';

    /** @var EnumValueProvider */
    private $enumValueProvider;

    /** @var ServiceLink */
    private $emailProcessorLink;

    /**
     * @param UserLoaderInterface     $userLoader
     * @param ManagerRegistry         $doctrine
     * @param EncoderFactoryInterface $encoderFactory
     * @param EnumValueProvider       $enumValueProvider
     * @param ServiceLink             $emailProcessor
     */
    public function __construct(
        UserLoaderInterface $userLoader,
        ManagerRegistry $doctrine,
        EncoderFactoryInterface $encoderFactory,
        EnumValueProvider $enumValueProvider,
        ServiceLink $emailProcessor
    ) {
        parent::__construct($userLoader, $doctrine, $encoderFactory);
        $this->enumValueProvider = $enumValueProvider;
        $this->emailProcessorLink = $emailProcessor;
    }

    /**
     * Return UserApi entity for the given user and organization
     *
     * @param User         $user
     * @param Organization $organization
     *
     * @return UserApi|null
     */
    public function getApi(User $user, Organization $organization): ?UserApi
    {
        return $this->getEntityManager()->getRepository(UserApi::class)->getApi($user, $organization);
    }

    /**
     * Sets the given authentication status for a user
     *
     * @param User   $user
     * @param string $authStatus
     */
    public function setAuthStatus(User $user, string $authStatus): void
    {
        $user->setAuthStatus($this->enumValueProvider->getEnumValueByCode(self::AUTH_STATUS_ENUM_CODE, $authStatus));
    }

    /**
     * {@inheritdoc}
     */
    public function updateUser(UserInterface $user, bool $flush = true): void
    {
        // make sure user has a default status
        if ($user instanceof User && null === $user->getAuthStatus()) {
            $defaultStatus = $this->enumValueProvider->getDefaultEnumValuesByCode(self::AUTH_STATUS_ENUM_CODE);
            if (is_array($defaultStatus)) {
                $defaultStatus = reset($defaultStatus);
            }

            $user->setAuthStatus($defaultStatus);
        }

        parent::updateUser($user, $flush);
    }

    /**
     * @param User $user
     */
    public function sendResetPasswordEmail(User $user): void
    {
        $user->setConfirmationToken($user->generateToken());
        $this->getEmailProcessor()->sendResetPasswordEmail($user);
        $user->setPasswordRequestedAt(new \DateTime('now', new \DateTimeZone('UTC')));
    }

    /**
     * {@inheritdoc}
     */
    protected function assertRoles(UserInterface $user): void
    {
        if (count($user->getRoles()) === 0) {
            $em = $this->getEntityManager();

            $roleClassName = $em->getClassMetadata(ClassUtils::getClass($user))
                ->getAssociationTargetClass('roles');
            if (!is_a($roleClassName, Role::class, true)) {
                throw new \RuntimeException(
                    sprintf('Expected %s, %s given', Role::class, $roleClassName)
                );
            }

            /** @var Role|null $role */
            $role = $em->getRepository($roleClassName)
                ->findOneBy(['role' => User::ROLE_DEFAULT]);
            if (!$role) {
                throw new \RuntimeException('Default user role not found');
            }

            $user->addRole($role);
        }
    }

    /**
     * @return Processor
     */
    private function getEmailProcessor(): Processor
    {
        return $this->emailProcessorLink->getService();
    }
}
