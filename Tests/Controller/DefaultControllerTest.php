<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Tests\Controller;

use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\EncoderBundle\Document\Job;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\PermissionProfile;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\SchemaBundle\Services\FactoryService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 *
 * @coversNothing
 */
class DefaultControllerTest extends PumukitTestCase
{
    private const METADATA = '{"streams":[{"codec_type":"video","width":1280,"height":720},{"codec_type":"audio"}],"format":{"duration":"100.000000"}}';

    private KernelBrowser $client;
    private MultimediaObject $multimediaObject;

    public function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->dm = self::getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->clearBBDD();

        foreach (['owner', 'Participant'] as $code) {
            $role = new Role();
            $role->setCod($code);
            $this->dm->persist($role);
        }
        $this->dm->flush();

        $factory = self::getContainer()->get(FactoryService::class);
        $this->multimediaObject = $factory->createMultimediaObject($factory->createSeries());
        $this->multimediaObject->setTitle('Lecture');
        $this->multimediaObject->addTrack(Track::create(
            'video.mp4',
            i18nText::create(['en' => '']),
            'en',
            Tags::create(['master', 'display', 'html5']),
            false,
            true,
            0,
            Storage::create(StorageUrl::create('/storage/video.mp4'), Path::create(self::getContainer()->getParameter('kernel.project_dir').'/tests/files/pumukit.mp4')),
            VideoAudio::create(self::METADATA)
        ));
        $this->dm->flush();
    }

    public function testTheEditorRendersForAnEditor(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $crawler = $this->client->request('GET', $this->url());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('form input[name="_token"]'));
    }

    public function testThePersonalScopeCannotCutSomeoneElsesVideo(): void
    {
        $this->login(PermissionProfile::SCOPE_PERSONAL);

        $this->client->request('GET', $this->url());
        $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);

        $this->client->request('POST', $this->url('/cut'), ['in_ms' => '0', 'out_ms' => '10']);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testACutWithoutTheCsrfTokenIsRefused(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->client->request('POST', $this->url('/cut'), ['in_ms' => '0', 'out_ms' => '10']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testTheCutKeepsTheMilliseconds(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['in_ms' => '10.25', 'out_ms' => '20.5', 'title_en' => 'Lecture (part 1)', 'comm' => 'Highlights']);

        $this->assertResponseRedirects();
        $cut = $this->lastCut();
        $this->assertSame('Lecture (part 1)', $cut->getTitle('en'));
        $this->assertSame($this->multimediaObject->getSeries()->getId(), $cut->getSeries()->getId());
        $this->assertStringStartsWith('Highlights', $cut->getComments());
        $this->assertStringContainsString('00:00:10.250 - 00:00:20.500', $cut->getComments());
        $jobs = $this->jobs($cut);
        $this->assertCount(1, $jobs);
        $this->assertSame('video_master_trimming', $jobs[0]->getProfile());
        $this->assertSame(['ss' => '10.250', 't' => '10.250'], $jobs[0]->getInitVars());
    }

    public function testTheBroadcastableOptionUsesTheBroadcastableProfile(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['in_ms' => '0', 'out_ms' => '10', 'video_broadcastable' => 'on']);

        $this->assertSame(['video_broadcastable_trimming'], array_map(static fn (Job $job) => $job->getProfile(), $this->jobs($this->lastCut())));
    }

    public function testACutOutsideTheVideoIsABadRequest(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['in_ms' => '50', 'out_ms' => '500']);
        $this->assertResponseStatusCodeSame(400);

        $this->cut(['in_ms' => '50', 'out_ms' => '40']);
        $this->assertResponseStatusCodeSame(400);

        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testTheThumbnailMustBeAnImage(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['in_ms' => '0', 'out_ms' => '10', 'hidden_src_img' => 'data:text/html;base64,'.base64_encode('<script>alert(1)</script>')]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(1, $this->countMultimediaObjects());

        $png = file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/tests/files/pumukit.png');
        $this->cut(['in_ms' => '0', 'out_ms' => '10', 'hidden_src_img' => 'data:image/png;base64,'.base64_encode($png)]);
        $this->assertResponseRedirects();
        $pics = $this->lastCut()->getPics();
        $this->assertCount(1, $pics);
        $this->assertStringEndsWith('.png', $pics[0]->getPath());
    }

    private function cut(array $parameters): void
    {
        $crawler = $this->client->request('GET', $this->url());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url('/cut'), ['_token' => $token] + $parameters);
    }

    private function url(string $suffix = ''): string
    {
        return '/admin/hardvideoeditor/'.$this->multimediaObject->getId().$suffix;
    }

    private function login(string $scope): void
    {
        $user = new User();
        $user->setUsername('editor');
        $user->setEmail('editor@example.com');
        $user->addRole($scope);
        $user->addRole('ROLE_ACCESS_MULTIMEDIA_SERIES');
        $this->dm->persist($user);
        $this->dm->flush();

        $this->client->loginUser($user, 'pumukit');
    }

    private function countMultimediaObjects(): int
    {
        return $this->dm->getDocumentCollection(MultimediaObject::class)->countDocuments(['status' => ['$ne' => MultimediaObject::STATUS_PROTOTYPE]]);
    }

    private function lastCut(): MultimediaObject
    {
        $this->dm->clear();
        $cuts = $this->dm->getRepository(MultimediaObject::class)->findBy(['_id' => ['$ne' => $this->multimediaObject->getId()], 'status' => ['$ne' => MultimediaObject::STATUS_PROTOTYPE]]);
        $this->assertCount(1, $cuts);

        return $cuts[0];
    }

    private function jobs(MultimediaObject $multimediaObject): array
    {
        return $this->dm->getRepository(Job::class)->findBy(['mm_id' => $multimediaObject->getId()]);
    }
}
