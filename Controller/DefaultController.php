<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\EncoderBundle\Services\DTO\JobOptions;
use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectPicService;
use Pumukit\SchemaBundle\Services\PersonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('admin/hardvideoeditor')]
class DefaultController extends AbstractController
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ProfileService $profileService,
        private readonly FactoryService $factoryService,
        private readonly MultimediaObjectPicService $multimediaObjectPicService,
        private readonly PersonService $personService,
        private readonly JobCreator $jobCreator,
        private readonly TranslatorInterface $translator,
        private readonly array $pumukitLocales,
        private readonly string $defaultSetRole,
    ) {}

    #[Route('/{id}', name: 'pumukit_videocut')]
    public function indexAction(MultimediaObject $multimediaObject): Response
    {
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
            'role' => $this->getRole(),
            'langs' => $this->pumukitLocales,
            'video_broadcastable' => (bool) $this->profileService->getProfile('video_broadcastable'),
        ]);
    }

    #[Route('/{id}/cut', name: 'pumukit_videocut_action', methods: ['POST'])]
    public function cutAction(Request $request, MultimediaObject $originalmmobject): Response
    {
        $parameters = $request->request;
        $in = (int) $parameters->get('in_ms');
        $out = (int) $parameters->get('out_ms');

        $multimediaObject = $this->factoryService->createMultimediaObject($originalmmobject->getSeries(), true, $this->getUser());

        $multimediaObject->setRecordDate($originalmmobject->getRecordDate());

        $comments = $parameters->getString('comm');
        $comments .= "\n---\n CORTADO DE ".$originalmmobject->getTitle().'('.$originalmmobject->getId().') '.gmdate('H:i:s', $in).' - '.gmdate('H:i:s', $out);
        $multimediaObject->setComments($comments);

        foreach ($this->pumukitLocales as $lang) {
            $multimediaObject->setTitle($parameters->getString('title_'.$lang), $lang);
            $multimediaObject->setDescription($parameters->getString('descript_'.$lang), $lang);
        }

        $base64 = $parameters->getString('hidden_src_img');
        if ('' !== $base64) {
            $decodedData = substr($base64, 22, strlen($base64));
            $format = substr($base64, strpos($base64, '/') + 1, strpos($base64, ';') - 1 - strpos($base64, '/'));

            $this->multimediaObjectPicService->addPicMem($multimediaObject, base64_decode($decodedData), $format);
        }

        $person = null;
        if ('on' === $parameters->get('new_person') && '' !== $parameters->getString('person')) {
            $person = new Person();
            $person->setName($parameters->getString('person'));
            foreach ($this->pumukitLocales as $lang) {
                $person->setFirm($parameters->getString('firm_'.$lang), $lang);
                $person->setPost($parameters->getString('post_'.$lang), $lang);
            }

            $person = $this->personService->savePerson($person);
        } elseif ('0' !== $parameters->getString('person_id', '0')) {
            $person = $this->personService->findPersonById($parameters->getString('person_id'));
        }

        if ($person) {
            $multimediaObject = $this->personService->createRelationPerson($person, $this->getRole(), $multimediaObject);
        }

        $track = $originalmmobject->getTrackWithTag('master');
        $profile = $parameters->get('video_broadcastable') ? 'video_broadcastable_trimming' : 'video_master_trimming';

        $jobOptions = new JobOptions($profile, 2, $track->language(), $track->description()->toArray(), ['ss' => $in, 't' => $out - $in]);
        $this->jobCreator->fromPath($multimediaObject, Path::create($track->storage()->path()->path()), $jobOptions);

        $this->documentManager->persist($multimediaObject);
        $this->documentManager->flush();

        if ($request->isXmlHttpRequest()) {
            return new Response('DONE');
        }

        return $this->redirectToRoute('pumukitnewadmin_mms_shortener', ['id' => $multimediaObject->getId()]);
    }

    private function notReadyToCut(MultimediaObject $multimediaObject, string $msg): Response
    {
        return $this->render('@PumukitHardVideoEditor/Default/error.html.twig', [
            'multimediaObject' => $multimediaObject,
            'msg' => $this->translator->trans($msg),
        ]);
    }

    private function getRole(): ?Role
    {
        return $this->documentManager->getRepository(Role::class)->findOneBy(['cod' => $this->defaultSetRole]);
    }
}
