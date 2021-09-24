<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\BasePlayerBundle\Services\TrackUrlService;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectPicService;
use Pumukit\SchemaBundle\Services\PersonService;
use Pumukit\SchemaBundle\Services\PicService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("admin/hardvideoeditor")
 */
class DefaultController extends AbstractController
{
    private $documentManager;
    private $profileService;
    private $trackUrlService;
    private $factoryService;
    private $multimediaObjectPicService;
    private $personService;
    private $jobService;
    private $translator;
    private $pumukitLocales;
    private $defaultSetRole;

    public function __construct(
        DocumentManager $documentManager,
        ProfileService $profileService,
        TrackUrlService $trackUrlService,
        FactoryService $factoryService,
        MultimediaObjectPicService $multimediaObjectPicService,
        PersonService $personService,
        JobService $jobService,
        TranslatorInterface $translator,
        array $pumukitLocales,
        string $defaultSetRole
    ) {
        $this->documentManager = $documentManager;
        $this->profileService = $profileService;
        $this->trackUrlService = $trackUrlService;
        $this->factoryService = $factoryService;
        $this->multimediaObjectPicService = $multimediaObjectPicService;
        $this->personService = $personService;
        $this->jobService = $jobService;
        $this->translator = $translator;
        $this->pumukitLocales = $pumukitLocales;
        $this->defaultSetRole = $defaultSetRole;
    }

    /**
     * @Route("/{id}", name="pumukit_videocut", defaults={"roleCod" = "actor"})
     * @ParamConverter("multimediaObject", class="PumukitSchemaBundle:MultimediaObject", options={"id" = "id"})
     * @Template()
     */
    public function indexAction(MultimediaObject $multimediaObject)
    {
        $role = $this->getRole();

        $master = $multimediaObject->getTrackWithTag('master');
        $track = $multimediaObject->getTrackWithTag('html5');
        if (!$track) {
            $track = $multimediaObject->getTrackWithTag('display');
        }

        if (!$master) {
            $msg = "There aren't master track";

            return $this->notReadyToCut($multimediaObject, $msg);
        }

        if (!$track) {
            $msg = "There aren't html5 track";

            return $this->notReadyToCut($multimediaObject, $msg);
        }

        if ($master->isOnlyAudio()) {
            $msg = 'The master is only audio';

            return $this->notReadyToCut($multimediaObject, $msg);
        }

        if ($track->isOnlyAudio()) {
            $msg = 'Upload video track to cut';

            return $this->notReadyToCut($multimediaObject, $msg);
        }

        if ($multimediaObject->getProperty('opencast') || $multimediaObject->isMultistream()) {
            $msg = "Can't cut multistream videos";

            return $this->notReadyToCut($multimediaObject, $msg);
        }

        $broadcastable_master = $this->profileService->getProfile('broadcastable_master');
        $direct_track_url_exists = method_exists($this->trackUrlService, 'generateDirectTrackFileUrl');

        return [
            'mm' => $multimediaObject,
            'track' => $track,
            'role' => $role,
            'langs' => $this->pumukitLocales,
            'broadcastable_master' => (($broadcastable_master) ? true : false),
            'direct_track_url_exists' => $direct_track_url_exists,
        ];
    }

    /**
     * @Route("/{id}/cut", name="pumukit_videocut_action", defaults={"roleCod" = "actor"})
     * @Method({"POST"})
     */
    public function cutAction(Request $request, MultimediaObject $originalmmobject)
    {
        $in = (float) ($request->get('in_ms'));
        $out = (float) ($request->get('out_ms'));

        $multimediaObject = $this->factoryService->createMultimediaObject(
            $originalmmobject->getSeries(),
            true,
            $this->getUser()
        );

        $multimediaObject->setRecordDate($originalmmobject->getRecordDate());

        $comments = $request->get('comm');
        $comments .= "\n---\n CORTADO DE ".$originalmmobject->getTitle().'('.$originalmmobject->getId().') '.gmdate(
            'H:i:s',
            $in
        ).' - '.gmdate('H:i:s', $out);
        $multimediaObject->setComments($comments);

        foreach ($this->pumukitLocales as $lang) {
            $multimediaObject->setTitle($request->get('title_'.$lang), $lang);
            $multimediaObject->setDescription($request->get('descript_'.$lang), $lang);
        }

        $base_64 = $request->request->get('hidden_src_img');
        if ($base_64) {
            $decodedData = substr($base_64, 22, strlen($base_64));
            $format = substr($base_64, strpos($base_64, '/') + 1, strpos($base_64, ';') - 1 - strpos($base_64, '/'));

            $data = base64_decode($decodedData);

            $this->multimediaObjectPicService->addPicMem($multimediaObject, $data, $format);
        }

        $person = false;
        if ('on' == $request->get('new_person') && strlen($request->get('person')) > 0) {
            $person = new Person();
            $person->setName($request->get('person'));
            foreach ($this->pumukitLocales as $lang) {
                $person->setFirm($request->get('firm_'.$lang), $lang);
                $person->setPost($request->get('post_'.$lang), $lang);
            }

            $person = $this->personService->savePerson($person);
        } elseif ('0' !== $request->get('person_id', '0')) {
            $person = $this->personService->findPersonById($request->get('person_id'));
        }

        if ($person) {
            $role = $this->getRole();
            $multimediaObject = $this->personService->createRelationPerson($person, $role, $multimediaObject);
        }

        $track = $originalmmobject->getTrackWithTag('master');
        $profile = $request->get('broadcastable_master') ? 'broadcastable_master_trimming' : 'master_trimming';
        $priority = 2;
        $newDuration = $out - $in;
        $parameters = ['ss' => $in, 't' => $newDuration];

        $this->jobService->addJob(
            $track->getPath(),
            $profile,
            $priority,
            $multimediaObject,
            $track->getLanguage(),
            $track->getI18nDescription(),
            $parameters,
            $newDuration,
            JobService::ADD_JOB_NOT_CHECKS
        );

        $this->documentManager->persist($multimediaObject);
        $this->documentManager->flush();

        if ($request->isXmlHttpRequest()) {
            return new Response('DONE');
        }

        return $this->redirectToRoute('pumukitnewadmin_mms_shortener', ['id' => $multimediaObject->getId()]);
    }

    protected function notReadyToCut(MultimediaObject $multimediaObject, ?string $msg = '')
    {
        $i18nMsg = $this->translator->trans($msg);

        return $this->render(
            'PumukitHardVideoEditorBundle:Default:error.html.twig',
            ['multimediaObject' => $multimediaObject, 'msg' => $i18nMsg]
        );
    }

    private function getRole()
    {
        return $this->documentManager->getRepository(Role::class)->findOneBy(['cod' => $this->defaultSetRole]);
    }
}
