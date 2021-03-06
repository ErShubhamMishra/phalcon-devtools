<?php
declare(strict_types=1);

namespace Phalcon\DevTools\Tests\Unit\Providers;

use Codeception\Test\Unit;
use Phalcon\DevTools\Providers\EventsManagerProvider;
use Phalcon\Di\ServiceProviderInterface;

final class EventsManagerProviderTest extends Unit
{
    public function testImplementation(): void
    {
        $class = $this->createMock(EventsManagerProvider::class);

        $this->assertInstanceOf(ServiceProviderInterface::class, $class);
    }
}
