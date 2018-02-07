<?php

namespace Pumukit\HardVideoEditorBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\EncoderBundle\Services\JobService;

/**
 * @Route("admin/hardvideoeditor")
 */
class DefaultController extends Controller
{
    /**
     * @param MultimediaObject $multimediaObject
     *
     * @return array|Response
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

        if (!$master && !$track) {
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

        if ($multimediaObject->getProperty('opencast')) {
            $msg = "Can't cut multistream videos";

            return $this->notReadyToCut($multimediaObject, $msg);
        }

        $profileService = $this->get('pumukitencoder.profile');
        $broadcastable_master = $profileService->getProfile('broadcastable_master');

        return array(
            'mm' => $multimediaObject,
            'track' => $track,
            'role' => $role,
            'langs' => $this->container->getParameter('pumukit2.locales'),
            'broadcastable_master' => (($broadcastable_master) ? true : false),
        );
    }

    protected function notReadyToCut($multimediaObject, $msg)
    {
        $translator = $this->get('translator');
        $i18nMsg = $translator->trans($msg);

        return $this->render(
            'PumukitHardVideoEditorBundle:Default:error.html.twig',
            array('multimediaObject' => $multimediaObject, 'msg' => $i18nMsg)
        );
    }

    /**
     * @param MultimediaObject $originalmmobject
     * @param Request          $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     * @Route("/{id}/cut", name="pumukit_videocut_action", defaults={"roleCod" = "actor"})
     * @Method({"POST"})
     */
    public function cutAction(MultimediaObject $originalmmobject, Request $request)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $factoryService = $this->get('pumukitschema.factory');
        $picService = $this->get('pumukitschema.mmspic');
        $personService = $this->get('pumukitschema.person');
        $jobService = $this->get('pumukitencoder.job');

        // data
        $in = floatval($request->get('in_ms'));
        $out = floatval($request->get('out_ms'));

        // object
        $multimediaObject = $factoryService->createMultimediaObject(
            $originalmmobject->getSeries(),
            true,
            $this->getUser()
        );

        // Comment
        // TODO translate
        $comments = $request->get('comm');
        $comments .= "\n---\n CORTADO DE ".$originalmmobject->getTitle().'('.$originalmmobject->getId().') '.gmdate(
                'H:i:s',
                $in
            ).' - '.gmdate('H:i:s', $out);
        $multimediaObject->setComments($comments);

        // Add i18n
        $langs = $this->container->getParameter('pumukit2.locales');
        foreach ($langs as $lang) {
            $multimediaObject->setTitle($request->get('title_'.$lang), $lang);
            $multimediaObject->setDescription($request->get('descript_'.$lang), $lang);
        }

        // Pic
        $base_64 = $request->request->get('hidden_src_img');
        if ($base_64) {
            $decodedData = substr($base_64, 22, strlen($base_64));
            $format = substr($base_64, strpos($base_64, '/') + 1, strpos($base_64, ';') - 1 - strpos($base_64, '/'));

            $data = base64_decode($decodedData);

            $picService->addPicMem($multimediaObject, $data, $format);
        }

        // Person
        $person = false;
        if ('on' == $request->get('new_person') && strlen($request->get('person')) > 0) {
            $person = new Person();
            $person->setName($request->get('person'));
            foreach ($langs as $lang) {
                $person->setFirm($request->get('firm_'.$lang), $lang);
                $person->setPost($request->get('post_'.$lang), $lang);
            }

            $person = $personService->savePerson($person);
        } elseif ('0' !== $request->get('person_id', '0')) {
            $person = $personService->findPersonById($request->get('person_id'));
        }

        if ($person) {
            $role = $this->getRole();
            $multimediaObject = $personService->createRelationPerson($person, $role, $multimediaObject);
        }

        // PubChannel
        // TODO

        // Job
        $track = $originalmmobject->getTrackWithTag('master');
        $profile = $request->get('broadcastable_master') ? 'broadcastable_master_trimming' : 'master_trimming';
        $priority = 2;
        $newDuration = $out - $in;
        $parameters = array('ss' => $in, 't' => $newDuration);

        $jobService->addJob(
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

        $dm->persist($multimediaObject);
        $dm->flush();

        // If not ajax return series list
        if ($request->isXmlHttpRequest()) {
            return new Response('DONE');
        } else {
            return $this->redirectToRoute('pumukitnewadmin_mms_shortener', array('id' => $multimediaObject->getId()));
        }
    }

    private function getRole()
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $repo = $dm->getRepository('PumukitSchemaBundle:Role');

        $defaultRole = $this->getParameter('pumukit_hard_video_editor.default_set_role');

        $role = $repo->findOneByCod($defaultRole);

        return $role;
    }
}
