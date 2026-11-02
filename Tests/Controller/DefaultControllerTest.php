<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Tests\Controller;

use MongoDB\BSON\ObjectId;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\EncoderBundle\Document\Job;
use Pumukit\HardVideoEditorBundle\ValueObject\Cut;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\PermissionProfile;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\SchemaBundle\Services\FactoryService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
    private Role $participant;

    public function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->dm = self::getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->clearBBDD();

        $owner = new Role();
        $owner->setCod('owner');
        $this->dm->persist($owner);
        $this->participant = new Role();
        $this->participant->setCod('Participant');
        $this->dm->persist($this->participant);
        $this->dm->flush();

        $factory = self::getContainer()->get(FactoryService::class);
        $this->multimediaObject = $factory->createMultimediaObject($factory->createSeries());
        $this->multimediaObject->setTitle('Lecture', 'en');
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
        $form = $crawler->filter('form[data-controller="hard-video-editor--cutter"]');
        $this->assertCount(1, $form);
        $this->assertCount(1, $form->filter('input[name="_token"]'));
        $this->assertCount(1, $form->filter('input[name="broadcastable"][checked]'));
        $this->assertCount(0, $form->filter('input[name="master"][checked]'));
        $this->assertSame('Lecture', $form->filter('input[name="title[en]"]')->attr('value'));
        $this->assertStringContainsString('/trackfile/'.$this->multimediaObject->getTrackWithTag('display')->id().'.mp4', $form->filter('video')->attr('src'));
        $this->assertCount(0, $form->filter('script, [onclick], [onchange], [onkeypress], [onloadedmetadata]'));
    }

    public function testThePersonalScopeCannotCutSomeoneElsesVideo(): void
    {
        $this->login(PermissionProfile::SCOPE_PERSONAL);

        $this->client->request('GET', $this->url());
        $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);

        $this->client->request('POST', $this->url('/cut'), ['start' => '0', 'end' => '10', 'broadcastable' => '1']);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testACutWithoutTheCsrfTokenIsRefused(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->client->request('POST', $this->url('/cut'), ['start' => '0', 'end' => '10', 'broadcastable' => '1']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testTheCutKeepsTheMilliseconds(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '10.25', 'end' => '20.5', 'title' => ['en' => 'Lecture (part 1)'], 'comments' => 'Highlights', 'master' => '1']);

        $this->assertResponseRedirects();
        $cut = $this->lastCut();
        $this->assertSame('Lecture (part 1)', $cut->getTitle('en'));
        $this->assertSame($this->multimediaObject->getSeries()->getId(), $cut->getSeries()->getId());
        $this->assertStringStartsWith('Highlights', $cut->getComments());
        $this->assertStringContainsString('00:00:10.250 - 00:00:20.500', $cut->getComments());
        $jobs = $this->jobs($cut);
        $this->assertCount(1, $jobs);
        $this->assertSame(Cut::PROFILE_MASTER, $jobs[0]->getProfile());
        $this->assertSame(['ss' => '10.250', 't' => '10.250'], $jobs[0]->getInitVars());
    }

    public function testTheChosenTracksDecideTheProfiles(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1']);
        $this->assertSame([Cut::PROFILE_MASTER_BROADCASTABLE], $this->profiles($this->lastCut()));
        $this->removeCut($this->lastCut());

        $this->cut(['start' => '0', 'end' => '10', 'master' => '1', 'broadcastable' => '1']);
        $profiles = $this->profiles($this->lastCut());
        sort($profiles);
        $this->assertSame([Cut::PROFILE_BROADCASTABLE, Cut::PROFILE_MASTER], $profiles);
        $this->removeCut($this->lastCut());

        $this->cut(['start' => '0', 'end' => '10']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testTheCutInheritsTheMetadataButNotThePublication(): void
    {
        $root = $this->tag('ROOT', 'ROOT|');
        $channels = $this->tag('PUBCHANNELS', 'ROOT|PUBCHANNELS|', $root);
        $webTv = $this->tag('PUCHWEBTV', 'ROOT|PUBCHANNELS|PUCHWEBTV|', $channels);
        $unesco = $this->tag('UNESCO', 'ROOT|UNESCO|', $root);
        $subject = $this->tag('U310000', 'ROOT|UNESCO|U310000|', $unesco);
        $this->dm->flush();

        $speaker = new Person();
        $speaker->setName('Speaker');
        $this->dm->persist($speaker);
        $this->multimediaObject->addPersonWithRole($speaker, $this->participant);
        $this->multimediaObject->addTag($webTv);
        $this->multimediaObject->addTag($subject);
        $this->multimediaObject->setKeywords(['physics', 'waves'], 'en');
        $this->multimediaObject->setCopyright('University');
        $this->multimediaObject->setLicense('CC BY');
        $broadcast = new EmbeddedBroadcast();
        $broadcast->setType(EmbeddedBroadcast::TYPE_PASSWORD);
        $broadcast->setName(EmbeddedBroadcast::NAME_PASSWORD);
        $broadcast->setPassword('secret');
        $this->multimediaObject->setEmbeddedBroadcast($broadcast);
        $this->dm->flush();
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '0', 'end' => '10', 'title' => ['en' => ''], 'broadcastable' => '1']);

        $cut = $this->lastCut();
        $this->assertSame('Lecture', $cut->getTitle('en'));
        $this->assertSame(['physics', 'waves'], $cut->getKeywords('en'));
        $this->assertSame('University', $cut->getCopyright());
        $this->assertSame('CC BY', $cut->getLicense());
        $this->assertTrue($cut->containsTagWithCod('U310000'));
        $this->assertFalse($cut->containsTagWithCod('PUCHWEBTV'));
        $this->assertFalse($cut->containsTagWithCod('PUBCHANNELS'));
        $this->assertCount(1, $cut->getPeopleByRoleCod('Participant', true));
        $this->assertSame(EmbeddedBroadcast::TYPE_PASSWORD, $cut->getEmbeddedBroadcast()->getType());
        $this->assertSame('secret', $cut->getEmbeddedBroadcast()->getPassword());
    }

    public function testTagsWhoseDocumentWasRecreatedOrRemovedDoNotBreakTheCut(): void
    {
        $root = $this->tag('ROOT', 'ROOT|');
        $subject = $this->tag('U310000', 'ROOT|U310000|', $root);
        $removed = $this->tag('REMOVED', 'ROOT|REMOVED|', $root);
        $this->dm->flush();
        $this->multimediaObject->addTag($root);
        $this->multimediaObject->addTag($subject);
        $this->multimediaObject->addTag($removed);
        $this->dm->flush();
        $this->dm->getDocumentCollection(Tag::class)->deleteMany(['_id' => ['$in' => [new ObjectId($root->getId()), new ObjectId($removed->getId())]]]);
        $this->dm->clear();
        $newRoot = $this->tag('ROOT', 'ROOT|');
        $this->dm->flush();
        $this->dm->getRepository(Tag::class)->findOneBy(['cod' => 'U310000'])->setParent($newRoot);
        $this->dm->flush();
        $this->multimediaObject = $this->dm->getRepository(MultimediaObject::class)->find($this->multimediaObject->getId());
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1']);

        $this->assertResponseRedirects();
        $cut = $this->lastCut();
        $this->assertTrue($cut->containsTagWithCod('U310000'));
        $this->assertTrue($cut->containsTagWithCod('ROOT'));
        $this->assertFalse($cut->containsTagWithCod('REMOVED'));
    }

    public function testThePersonChosenInTheEditorIsAddedWithTheConfiguredRole(): void
    {
        $person = new Person();
        $person->setName('Guest');
        $this->dm->persist($person);
        $this->dm->flush();
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1', 'person' => $person->getId()]);
        $this->assertSame(['Guest'], array_map(static fn ($p) => $p->getName(), $this->lastCut()->getPeopleByRoleCod('Participant', true)));
        $this->removeCut($this->lastCut());

        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1', 'new_person' => '1', 'person_name' => 'New guest', 'post' => ['en' => 'Professor']]);
        $people = $this->lastCut()->getPeopleByRoleCod('Participant', true);
        $this->assertSame(['New guest'], array_map(static fn ($p) => $p->getName(), $people));
    }

    public function testCutAndContinueAnswersWithTheNewVideo(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1'], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertResponseStatusCodeSame(201);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->lastCut()->getId(), $response['id']);
    }

    public function testACutOutsideTheVideoIsABadRequest(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);

        $this->cut(['start' => '50', 'end' => '500', 'broadcastable' => '1']);
        $this->assertResponseStatusCodeSame(400);

        $this->cut(['start' => '50', 'end' => '40', 'broadcastable' => '1']);
        $this->assertResponseStatusCodeSame(400);

        $this->assertSame(1, $this->countMultimediaObjects());
    }

    public function testTheThumbnailMustBeAnImage(): void
    {
        $this->login(PermissionProfile::SCOPE_GLOBAL);
        $html = tempnam(sys_get_temp_dir(), 'hve');
        file_put_contents($html, '<script>alert(1)</script>');

        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1'], ['picture' => new UploadedFile($html, 'frame.png', 'image/png', null, true)]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(1, $this->countMultimediaObjects());

        $png = tempnam(sys_get_temp_dir(), 'hve');
        copy(self::getContainer()->getParameter('kernel.project_dir').'/tests/files/pumukit.png', $png);
        $this->cut(['start' => '0', 'end' => '10', 'broadcastable' => '1'], ['picture' => new UploadedFile($png, 'frame.html', 'text/html', null, true)]);
        $this->assertResponseRedirects();
        $pics = $this->lastCut()->getPics();
        $this->assertCount(1, $pics);
        $this->assertStringEndsWith('.png', $pics[0]->getPath());
    }

    private function cut(array $parameters, array $files = [], array $server = []): void
    {
        $crawler = $this->client->request('GET', $this->url());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url('/cut'), ['_token' => $token] + $parameters, $files, $server);
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

    private function tag(string $cod, string $path, ?Tag $parent = null): Tag
    {
        $tag = new Tag();
        $tag->setCod($cod);
        $tag->setPath($path);
        $tag->setTitle($cod, 'en');
        if ($parent) {
            $tag->setParent($parent);
        }
        $this->dm->persist($tag);

        return $tag;
    }

    private function countMultimediaObjects(): int
    {
        return $this->dm->getDocumentCollection(MultimediaObject::class)->countDocuments(['status' => ['$ne' => MultimediaObject::STATUS_PROTOTYPE]]);
    }

    private function lastCut(): MultimediaObject
    {
        $this->dm->clear();
        $this->dm->getFilterCollection()->isEnabled('backoffice') && $this->dm->getFilterCollection()->disable('backoffice');
        $cuts = $this->dm->getRepository(MultimediaObject::class)->findBy(['_id' => ['$ne' => $this->multimediaObject->getId()], 'status' => ['$ne' => MultimediaObject::STATUS_PROTOTYPE]]);
        $this->assertCount(1, $cuts);

        return $cuts[0];
    }

    private function removeCut(MultimediaObject $multimediaObject): void
    {
        $this->dm->getDocumentCollection(MultimediaObject::class)->deleteOne(['_id' => new ObjectId($multimediaObject->getId())]);
        $this->dm->getDocumentCollection(Job::class)->deleteMany([]);
    }

    private function jobs(MultimediaObject $multimediaObject): array
    {
        return $this->dm->getRepository(Job::class)->findBy(['mm_id' => $multimediaObject->getId()]);
    }

    private function profiles(MultimediaObject $multimediaObject): array
    {
        return array_map(static fn (Job $job) => $job->getProfile(), $this->jobs($multimediaObject));
    }
}
