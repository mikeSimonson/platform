<?php

namespace Oro\Bundle\UserBundle\Tests\Unit\Entity;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Oro\Bundle\EntityExtendBundle\Provider\EnumValueProvider;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\UserBundle\Entity\Repository\RoleRepository;
use Oro\Bundle\UserBundle\Entity\Repository\UserApiRepository;
use Oro\Bundle\UserBundle\Entity\Role;
use Oro\Bundle\UserBundle\Entity\UserApi;
use Oro\Bundle\UserBundle\Entity\UserManager;
use Oro\Bundle\UserBundle\Mailer\Processor;
use Oro\Bundle\UserBundle\Security\UserLoaderInterface;
use Oro\Bundle\UserBundle\Tests\Unit\Stub\UserStub as User;
use Oro\Component\DependencyInjection\ServiceLink;
use Oro\Component\Testing\Unit\Entity\Stub\StubEnumValue;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Symfony\Component\Security\Core\Encoder\PasswordEncoderInterface;

class UserManagerTest extends \PHPUnit\Framework\TestCase
{
    /** @var UserLoaderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $userLoader;

    /** @var ManagerRegistry|\PHPUnit\Framework\MockObject\MockObject */
    private $doctrine;

    /** @var EncoderFactoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $encoderFactory;

    /** var Processor|\PHPUnit\Framework\MockObject\MockObject */
    private $emailProcessor;

    /** @var UserManager */
    private $userManager;

    protected function setUp()
    {
        $this->userLoader = $this->createMock(UserLoaderInterface::class);
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->encoderFactory = $this->createMock(EncoderFactoryInterface::class);
        $this->emailProcessor = $this->createMock(Processor::class);

        $this->userLoader->expects(self::any())
            ->method('getUserClass')
            ->willReturn(User::class);

        $enumValueProvider = $this->createMock(EnumValueProvider::class);
        $enumValueProvider->expects(self::any())
            ->method('getEnumValueByCode')
            ->willReturnCallback(function ($code, $id) {
                return new StubEnumValue($id, $id);
            });

        $emailProcessorLink = $this->createMock(ServiceLink::class);
        $emailProcessorLink->expects(self::any())
            ->method('getService')
            ->willReturn($this->emailProcessor);

        $this->userManager = new UserManager(
            $this->userLoader,
            $this->doctrine,
            $this->encoderFactory,
            $enumValueProvider,
            $emailProcessorLink
        );
    }

    /**
     * @return EntityManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectGetEntityManager()
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $this->doctrine->expects(self::atLeastOnce())
            ->method('getManagerForClass')
            ->with(User::class)
            ->willReturn($em);

        return $em;
    }

    /**
     * @param User $user
     *
     * @return PasswordEncoderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectGetPasswordEncoder(User $user)
    {
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $this->encoderFactory->expects(self::once())
            ->method('getEncoder')
            ->with($user)
            ->willReturn($encoder);

        return $encoder;
    }

    public function testGetApi()
    {
        $user = new User();
        $organization = new Organization();
        $userApi = new UserApi();

        $em = $this->expectGetEntityManager();
        $repository = $this->createMock(UserApiRepository::class);
        $em->expects(self::once())
            ->method('getRepository')
            ->with(UserApi::class)
            ->willReturn($repository);

        $repository->expects(self::once())
            ->method('getApi')
            ->with($user, $organization)
            ->willReturn($userApi);

        self::assertSame($userApi, $this->userManager->getApi($user, $organization));
    }

    public function testGetApiWhenApiKeyDoesNotExist()
    {
        $user = new User();
        $organization = new Organization();

        $em = $this->expectGetEntityManager();
        $repository = $this->createMock(UserApiRepository::class);
        $em->expects(self::once())
            ->method('getRepository')
            ->with(UserApi::class)
            ->willReturn($repository);

        $repository->expects(self::once())
            ->method('getApi')
            ->with($user, $organization)
            ->willReturn(null);

        self::assertNull($this->userManager->getApi($user, $organization));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Default user role not found
     */
    public function testUpdateUserUnsupported()
    {
        $user = new User();

        $em = $this->expectGetEntityManager();

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('getAssociationTargetClass')
            ->willReturn(Role::class);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $repository = $this->createMock(RoleRepository::class);
        $em->expects(self::once())
            ->method('getRepository')
            ->with(Role::class)
            ->willReturn($repository);

        $em->expects(self::never())
            ->method('persist');
        $em->expects(self::never())
            ->method('flush');

        $this->userManager->updateUser($user);
    }

    public function testUpdateUserWithPlainPasswordAndWithoutRoles()
    {
        $password = 'password';
        $encodedPassword = 'encodedPassword';
        $salt = 'salt';
        $defaultRole = new Role(User::ROLE_DEFAULT);

        $user = new User();
        $user->setPlainPassword($password);
        $user->setSalt($salt);

        $encoder = $this->expectGetPasswordEncoder($user);
        $encoder->expects(self::once())
            ->method('encodePassword')
            ->with($password, $salt)
            ->willReturn($encodedPassword);

        $em = $this->expectGetEntityManager();

        $em->expects(self::once())
            ->method('persist')
            ->with(self::identicalTo($user));
        $em->expects(self::once())
            ->method('flush');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('getAssociationTargetClass')
            ->willReturn(Role::class);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $repository = $this->createMock(RoleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with($this->equalTo(['role' => User::ROLE_DEFAULT]))
            ->willReturn($defaultRole);
        $em->expects(self::once())
            ->method('getRepository')
            ->with(Role::class)
            ->willReturn($repository);

        $this->userManager->updateUser($user);

        self::assertNull($user->getPlainPassword());
        self::assertEquals($encodedPassword, $user->getPassword());
        self::assertCount(1, $user->getRoles());
        self::assertSame($defaultRole, $user->getRole($defaultRole->getRole()));
    }

    public function testUpdateUserWithoutPlainPassword()
    {
        $user = new User();
        $user->addRole(new Role(User::ROLE_ADMINISTRATOR));

        $em = $this->expectGetEntityManager();
        $em->expects(self::once())
            ->method('persist')
            ->with(self::identicalTo($user));
        $em->expects(self::once())
            ->method('flush');

        $this->userManager->updateUser($user);

        self::assertNull($user->getPlainPassword());
        self::assertNull($user->getPassword());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Expected Symfony\Component\Security\Core\Role\Role, stdClass given
     */
    public function testNotSupportedRole()
    {
        $user = new User();

        $em = $this->expectGetEntityManager();

        $em->expects(self::never())
            ->method('persist');
        $em->expects(self::never())
            ->method('flush');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('getAssociationTargetClass')
            ->willReturn(\stdClass::class);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($metadata);

        $this->userManager->updateUser($user);
    }

    public function testSetAuthStatus()
    {
        $user = new User();
        self::assertNull($user->getAuthStatus());
        $this->userManager->setAuthStatus($user, UserManager::STATUS_EXPIRED);
        self::assertEquals(UserManager::STATUS_EXPIRED, $user->getAuthStatus()->getId());
    }
}
