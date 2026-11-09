<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\HardVideoEditorBundle\Services\CutService;
use Pumukit\HardVideoEditorBundle\ValueObject\Cut;
use Pumukit\NewAdminBundle\Controller\NewAdminControllerInterface;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Services\MultimediaObjectVoter;
use Pumukit\SchemaBundle\Services\PersonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/hardvideoeditor')]
#[IsGranted('ROLE_ACCESS_MULTIMEDIA_SERIES')]
class DefaultController extends AbstractController implements NewAdminControllerInterface
{
    public const CSRF_TOKEN = 'hard_video_editor_cut';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly PersonService $personService,
        private readonly CutService $cutService,
        private readonly array $pumukitLocales,
        private readonly string $defaultSetRole,
    ) {}

    #[Route('/{id}', name: 'pumukit_videocut', methods: ['GET'])]
    public function indexAction(MultimediaObject $multimediaObject): Response
    {
        $this->assertCanCut($multimediaObject);

        $track = $multimediaObject->getTrackWithTag('html5') ?? $multimediaObject->getTrackWithTag('display');
        $error = $this->notReadyToCut($multimediaObject, $track);
        if (null !== $error) {
            return $this->render('@PumukitHardVideoEditor/Default/error.html.twig', [
                'multimediaObject' => $multimediaObject,
                'msg' => $error,
            ]);
        }

        return $this->render('@PumukitHardVideoEditor/Default/index.html.twig', [
            'mm' => $multimediaObject,
            'track' => $track,
            'role' => $this->documentManager->getRepository(Role::class)->findOneBy(['cod' => $this->defaultSetRole]),
            'locales' => $this->pumukitLocales,
        ]);
    }

    #[Route('/{id}/cut', name: 'pumukit_videocut_action', methods: ['POST'])]
    public function cutAction(Request $request, MultimediaObject $originalmmobject): Response
    {
        $this->assertCanCut($originalmmobject);

        $parameters = $request->request;
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $parameters->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        try {
            $cut = new Cut(
                (float) $parameters->getString('start'),
                (float) $parameters->getString('end'),
                $this->localized($parameters, 'title'),
                $this->localized($parameters, 'description'),
                $parameters->getString('comments'),
                $parameters->getBoolean('master'),
                $parameters->getBoolean('broadcastable'),
                $this->picture($request->files->get('picture')),
            );
            $this->cutService->assertValid($originalmmobject, $cut);
            $multimediaObject = $this->cutService->cut($originalmmobject, $cut, $this->person($parameters), $this->getUser());
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        $url = $this->generateUrl('pumukitnewadmin_mms_shortener', ['id' => $multimediaObject->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        if ('json' === $request->getPreferredFormat()) {
            return new JsonResponse(['id' => $multimediaObject->getId(), 'url' => $url], Response::HTTP_CREATED);
        }

        return $this->redirect($url);
    }

    private function assertCanCut(MultimediaObject $multimediaObject): void
    {
        $this->denyAccessUnlessGranted(MultimediaObjectVoter::EDIT, $multimediaObject);
    }

    private function notReadyToCut(MultimediaObject $multimediaObject, ?Track $track): ?string
    {
        $master = $multimediaObject->getTrackWithTag('master');

        return match (true) {
            !$master => "There aren't master track",
            !$track => "There aren't html5 track",
            $master->metadata()->isOnlyAudio() => 'The master is only audio',
            $track->metadata()->isOnlyAudio() => 'Upload video track to cut',
            $multimediaObject->getProperty('opencast') || $multimediaObject->isMultistream() => "Can't cut multistream videos",
            default => null,
        };
    }

    private function localized(InputBag $parameters, string $name): array
    {
        $values = $parameters->all($name);
        $localized = [];
        foreach ($this->pumukitLocales as $locale) {
            $localized[$locale] = is_string($values[$locale] ?? null) ? $values[$locale] : '';
        }

        return $localized;
    }

    private function picture(mixed $file): ?string
    {
        if (null === $file) {
            return null;
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new \InvalidArgumentException('The thumbnail could not be uploaded');
        }

        return (string) file_get_contents($file->getPathname());
    }

    private function person(InputBag $parameters): ?Person
    {
        if ($parameters->getBoolean('new_person')) {
            $name = trim($parameters->getString('person_name'));
            if ('' === $name) {
                return null;
            }

            $person = new Person();
            $person->setName($name);
            foreach ($this->localized($parameters, 'post') as $locale => $post) {
                $person->setPost($post, $locale);
            }
            foreach ($this->localized($parameters, 'firm') as $locale => $firm) {
                $person->setFirm($firm, $locale);
            }

            return $this->personService->savePerson($person);
        }

        $personId = $parameters->getString('person');
        if ('' === $personId) {
            return null;
        }

        $person = $this->documentManager->getRepository(Person::class)->find($personId);

        return $person instanceof Person ? $person : null;
    }
}
