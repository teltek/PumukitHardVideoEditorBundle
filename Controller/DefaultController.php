<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\HardVideoEditorBundle\Services\CutService;
use Pumukit\HardVideoEditorBundle\ValueObject\Cut;
use Pumukit\NewAdminBundle\Controller\NewAdminControllerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Services\MultimediaObjectVoter;
use Pumukit\SchemaBundle\Services\PersonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/hardvideoeditor')]
#[IsGranted('ROLE_ACCESS_MULTIMEDIA_SERIES')]
class DefaultController extends AbstractController implements NewAdminControllerInterface
{
    public const CSRF_TOKEN = 'hard_video_editor_cut';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ProfileService $profileService,
        private readonly PersonService $personService,
        private readonly CutService $cutService,
        private readonly TranslatorInterface $translator,
        private readonly array $pumukitLocales,
        private readonly string $defaultSetRole,
    ) {}

    #[Route('/{id}', name: 'pumukit_videocut', methods: ['GET'])]
    public function indexAction(MultimediaObject $multimediaObject): Response
    {
        $this->denyAccessUnlessGranted(MultimediaObjectVoter::EDIT, $multimediaObject);

        $master = $multimediaObject->getTrackWithTag('master');
        $track = $multimediaObject->getTrackWithTag('html5') ?? $multimediaObject->getTrackWithTag('display');

        if (!$master) {
            return $this->notReadyToCut($multimediaObject, "There aren't master track");
        }

        if (!$track) {
            return $this->notReadyToCut($multimediaObject, "There aren't html5 track");
        }

        if ($master->metadata()->isOnlyAudio()) {
            return $this->notReadyToCut($multimediaObject, 'The master is only audio');
        }

        if ($track->metadata()->isOnlyAudio()) {
            return $this->notReadyToCut($multimediaObject, 'Upload video track to cut');
        }

        if ($multimediaObject->getProperty('opencast') || $multimediaObject->isMultistream()) {
            return $this->notReadyToCut($multimediaObject, "Can't cut multistream videos");
        }

        return $this->render('@PumukitHardVideoEditor/Default/index.html.twig', [
            'mm' => $multimediaObject,
            'track' => $track,
            'role' => $this->documentManager->getRepository(Role::class)->findOneBy(['cod' => $this->defaultSetRole]),
            'langs' => $this->pumukitLocales,
            'video_broadcastable' => (bool) $this->profileService->getProfile('video_broadcastable'),
        ]);
    }

    #[Route('/{id}/cut', name: 'pumukit_videocut_action', methods: ['POST'])]
    public function cutAction(Request $request, MultimediaObject $originalmmobject): Response
    {
        $this->denyAccessUnlessGranted(MultimediaObjectVoter::EDIT, $originalmmobject);

        $parameters = $request->request;
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $parameters->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $title = [];
        $description = [];
        foreach ($this->pumukitLocales as $locale) {
            $title[$locale] = $parameters->getString('title_'.$locale);
            $description[$locale] = $parameters->getString('descript_'.$locale);
        }

        try {
            $cut = new Cut(
                (float) $parameters->getString('in_ms'),
                (float) $parameters->getString('out_ms'),
                $title,
                $description,
                $parameters->getString('comm'),
                $parameters->has('video_broadcastable'),
                $this->picture($parameters->getString('hidden_src_img')),
            );
            $this->cutService->assertValid($originalmmobject, $cut);
            $multimediaObject = $this->cutService->cut($originalmmobject, $cut, $this->person($parameters), $this->getUser());
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        if ($request->isXmlHttpRequest()) {
            return new Response('DONE');
        }

        return $this->redirectToRoute('pumukitnewadmin_mms_shortener', ['id' => $multimediaObject->getId()]);
    }

    private function picture(string $dataUrl): ?string
    {
        if ('' === $dataUrl) {
            return null;
        }

        if (!preg_match('#^data:image/[a-z]+;base64,(.+)$#s', $dataUrl, $matches) || false === $picture = base64_decode($matches[1], true)) {
            throw new \InvalidArgumentException('The thumbnail is not a valid image');
        }

        return $picture;
    }

    private function person(InputBag $parameters): ?Person
    {
        if ('on' === $parameters->get('new_person') && '' !== trim($parameters->getString('person'))) {
            $person = new Person();
            $person->setName(trim($parameters->getString('person')));
            foreach ($this->pumukitLocales as $locale) {
                $person->setFirm($parameters->getString('firm_'.$locale), $locale);
                $person->setPost($parameters->getString('post_'.$locale), $locale);
            }

            return $this->personService->savePerson($person);
        }

        $personId = $parameters->getString('person_id', '0');
        if ('0' === $personId || '' === $personId) {
            return null;
        }

        $person = $this->documentManager->getRepository(Person::class)->find($personId);

        return $person instanceof Person ? $person : null;
    }

    private function notReadyToCut(MultimediaObject $multimediaObject, string $msg): Response
    {
        return $this->render('@PumukitHardVideoEditor/Default/error.html.twig', [
            'multimediaObject' => $multimediaObject,
            'msg' => $this->translator->trans($msg),
        ]);
    }
}
