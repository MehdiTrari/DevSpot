<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\OfferExpirationSubscriber;
use App\Repository\ConversationRepository;
use App\Repository\FavoriteProfileRepository;
use App\Repository\JobOfferRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationManager;
use App\Service\OfferLifecycleManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OfferExpirationSubscriberTest extends TestCase
{
    public function testSubscribedEventsAreDeclared(): void
    {
        self::assertSame([
            KernelEvents::REQUEST => 'onKernelRequest',
        ], OfferExpirationSubscriber::getSubscribedEvents());
    }

    public function testItTriggersOfferExpirationOnMainApplicationRequests(): void
    {
        $jobOfferRepository = $this->createMock(JobOfferRepository::class);
        $jobOfferRepository
            ->expects(self::once())
            ->method('findPublishedExpiredOffers')
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $subscriber = new OfferExpirationSubscriber($this->createOfferLifecycleManager($jobOfferRepository, $entityManager));
        $subscriber->onKernelRequest($this->createRequestEvent('/dashboard'));
    }

    public function testItSkipsProfilerAssetAndSubRequests(): void
    {
        $jobOfferRepository = $this->createMock(JobOfferRepository::class);
        $jobOfferRepository->expects(self::never())->method('findPublishedExpiredOffers');

        $subscriber = new OfferExpirationSubscriber(
            $this->createOfferLifecycleManager($jobOfferRepository, $this->createStub(EntityManagerInterface::class)),
        );

        $subscriber->onKernelRequest($this->createRequestEvent('/_profiler/abc'));
        $subscriber->onKernelRequest($this->createRequestEvent('/_wdt/abc'));
        $subscriber->onKernelRequest($this->createRequestEvent('/assets/app.css'));
        $subscriber->onKernelRequest($this->createRequestEvent('/dashboard', HttpKernelInterface::SUB_REQUEST));
    }

    private function createOfferLifecycleManager(JobOfferRepository $jobOfferRepository, EntityManagerInterface $entityManager): OfferLifecycleManager
    {
        return new OfferLifecycleManager(
            $jobOfferRepository,
            $this->createStub(ConversationRepository::class),
            new NotificationManager(
                $entityManager,
                $this->createStub(NotificationRepository::class),
                $this->createStub(FavoriteProfileRepository::class),
                $this->createStub(UserRepository::class),
                $this->createStub(UrlGeneratorInterface::class),
            ),
            $entityManager,
        );
    }

    private function createRequestEvent(string $path, int $requestType = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            $requestType,
        );
    }
}
