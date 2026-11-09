<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\HardVideoEditorBundle\Controller\DefaultController;
use Pumukit\HardVideoEditorBundle\DependencyInjection\PumukitHardVideoEditorExtension;
use Pumukit\HardVideoEditorBundle\Routing\RouteLoader;
use Pumukit\HardVideoEditorBundle\Services\CutService;
use Pumukit\HardVideoEditorBundle\Services\MenuService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 *
 * @coversNothing
 */
class PumukitHardVideoEditorExtensionTest extends TestCase
{
    private const OTHER_PROFILES = ['master_copy' => ['master' => true]];

    public function testTheDisabledBundleOnlyRegistersTheRouteLoaderAndAddsNoProfile(): void
    {
        $container = $this->load(['enable' => false]);

        $this->assertSame([RouteLoader::class], $this->bundleServices($container));
        $this->assertSame(self::OTHER_PROFILES, $container->getParameter('pumukitencode.profilelist'));
        $this->assertFalse($container->hasParameter('pumukit_hard_video_editor.profilelist'));
    }

    public function testTheEnabledBundleRegistersItsServicesAndProfiles(): void
    {
        $container = $this->load([]);

        foreach ([RouteLoader::class, DefaultController::class, CutService::class, MenuService::class] as $id) {
            $this->assertTrue($container->hasDefinition($id), $id);
        }
        $this->assertSame(['master_copy', 'video_master_trimming', 'video_broadcastable_trimming', 'video_master_broadcastable_trimming'], array_keys($container->getParameter('pumukitencode.profilelist')));
    }

    public function testNoRouteIsLoadedWhenItIsDisabled(): void
    {
        $this->assertCount(0, (new RouteLoader(false))->load('@PumukitHardVideoEditorBundle/Controller/', RouteLoader::TYPE));
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('pumukitencode.profilelist', self::OTHER_PROFILES);
        (new PumukitHardVideoEditorExtension())->load([$config], $container);

        return $container;
    }

    private function bundleServices(ContainerBuilder $container): array
    {
        return array_values(array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_starts_with($id, 'Pumukit\HardVideoEditorBundle\\')));
    }
}
