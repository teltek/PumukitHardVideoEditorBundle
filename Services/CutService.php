<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\EncoderBundle\Services\DTO\JobOptions;
use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\HardVideoEditorBundle\ValueObject\Cut;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectPicService;
use Pumukit\SchemaBundle\Services\PersonService;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CutService
{
    public const PICTURE_EXTENSIONS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    private const JOB_PRIORITY = 2;
    private const DURATION_MARGIN = 1.0;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly FactoryService $factoryService,
        private readonly MultimediaObjectPicService $multimediaObjectPicService,
        private readonly PersonService $personService,
        private readonly JobCreator $jobCreator,
        private readonly TranslatorInterface $translator,
        private readonly string $defaultSetRole,
    ) {}

    public function assertValid(MultimediaObject $original, Cut $cut): void
    {
        $this->assertValidRange($original, $this->master($original), $cut);
        if (null !== $cut->picture) {
            $this->pictureExtension($cut->picture);
        }
    }

    public function cut(MultimediaObject $original, Cut $cut, ?Person $person = null, ?User $user = null): MultimediaObject
    {
        $this->assertValid($original, $cut);
        $master = $this->master($original);
        $pictureExtension = null === $cut->picture ? null : $this->pictureExtension($cut->picture);

        $multimediaObject = $this->factoryService->createMultimediaObject($original->getSeries(), true, $user);
        $multimediaObject->setRecordDate($original->getRecordDate());
        foreach ($cut->title as $locale => $title) {
            $multimediaObject->setTitle($title, $locale);
        }
        foreach ($cut->description as $locale => $description) {
            $multimediaObject->setDescription($description, $locale);
        }
        $multimediaObject->setComments(ltrim($cut->comments."\n---\n".$this->translator->trans('Cut from %title% (%id%): %start% - %end%', [
            '%title%' => $original->getTitle(),
            '%id%' => $original->getId(),
            '%start%' => self::formatTime($cut->start),
            '%end%' => self::formatTime($cut->end),
        ])));

        if (null !== $pictureExtension) {
            $this->multimediaObjectPicService->addPicMem($multimediaObject, $cut->picture, $pictureExtension);
        }

        $role = $person ? $this->documentManager->getRepository(Role::class)->findOneBy(['cod' => $this->defaultSetRole]) : null;
        if ($person && $role) {
            $multimediaObject = $this->personService->createRelationPerson($person, $role, $multimediaObject);
        }

        $parameters = ['ss' => sprintf('%.3F', $cut->start), 't' => sprintf('%.3F', $cut->end - $cut->start)];
        foreach ($cut->profiles() as $profile) {
            $jobOptions = new JobOptions($profile, self::JOB_PRIORITY, $master->language(), $master->description()->toArray(), $parameters);
            $this->jobCreator->fromPath($multimediaObject, Path::create($master->storage()->path()->path()), $jobOptions);
        }

        $this->documentManager->persist($multimediaObject);
        $this->documentManager->flush();

        return $multimediaObject;
    }

    public static function formatTime(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3600000), intdiv($milliseconds % 3600000, 60000), intdiv($milliseconds % 60000, 1000), $milliseconds % 1000);
    }

    private function master(MultimediaObject $original): Track
    {
        $master = $original->getTrackWithTag('master');
        if (!$master instanceof Track) {
            throw new \InvalidArgumentException('The multimedia object has no master track');
        }

        return $master;
    }

    private function assertValidRange(MultimediaObject $original, Track $master, Cut $cut): void
    {
        if ($cut->start < 0 || $cut->end <= $cut->start) {
            throw new \InvalidArgumentException('The end of the cut must be after its start');
        }

        $duration = max($original->getDuration(), $master->metadata()->duration());
        if ($duration > 0 && $cut->end > $duration + self::DURATION_MARGIN) {
            throw new \InvalidArgumentException('The cut ends after the video');
        }
    }

    private function pictureExtension(string $picture): string
    {
        $info = getimagesizefromstring($picture);
        if (false === $info || !isset(self::PICTURE_EXTENSIONS[$info[2]])) {
            throw new \InvalidArgumentException('The thumbnail must be a JPEG, PNG or WebP image');
        }

        return self::PICTURE_EXTENSIONS[$info[2]];
    }
}
