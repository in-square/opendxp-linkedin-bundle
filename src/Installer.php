<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle;

use OpenDxp\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Layout\Panel;

final class Installer extends SettingsStoreAwareInstaller
{
    private const CLASS_NAME = 'LinkedinPost';

    public function install(): void
    {
        $this->installClassDefinition();
        parent::install();
    }

    private function installClassDefinition(): void
    {
        $existing = ClassDefinition::getByName(self::CLASS_NAME);
        if ($existing) {
            if ($this->hasFields($existing)) {
                return;
            }

            $definition = $this->buildClassDefinition($existing);
            $definition->save();
            return;
        }

        $definition = $this->buildClassDefinition();

        if (!$definition instanceof ClassDefinition) {
            throw new \RuntimeException('Invalid LinkedinPost class definition.');
        }

        $definition->save();
    }

    private function buildClassDefinition(?ClassDefinition $class = null): ClassDefinition
    {
        $class = $class ?? new ClassDefinition();

        $rootPanel = new Panel();
        $rootPanel->setName('pimcore_root');

        $mainPanel = new Panel();
        $mainPanel->setName('Wystrój');
        $mainPanel->setTitle('');

        $externalId = (new Data\Input())
            ->setName('externalId')
            ->setTitle('externalId')
            ->setMandatory(true)
            ->setColumnLength(190);
        $externalId->setUnique(true);

        $text = (new Data\Textarea())
            ->setName('text')
            ->setTitle('text');

        $permalink = (new Data\Input())
            ->setName('permalink')
            ->setTitle('Redirect')
            ->setColumnLength(190);

        $image = (new Data\Image())
            ->setName('image')
            ->setTitle('Image')
            ->setUploadPath('');

        $publishedAt = (new Data\Datetime())
            ->setName('publishedAt')
            ->setTitle('publishedAt');
        $publishedAt->setColumnType('datetime');
        $publishedAt->setUseCurrentDate(false);
        $publishedAt->setRespectTimezone(false);

        $organizationUrn = (new Data\Input())
            ->setName('organizationUrn')
            ->setTitle('organizationUrn')
            ->setColumnLength(190);

        $contentHash = (new Data\Input())
            ->setName('contentHash')
            ->setTitle('Content Hash')
            ->setColumnLength(190);

        $mainPanel->setChildren([
            $externalId,
            $text,
            $permalink,
            $image,
            $publishedAt,
            $organizationUrn,
            $contentHash,
        ]);
        $rootPanel->setChildren([$mainPanel]);

        $class->setName(self::CLASS_NAME);
        if (!$class->getId()) {
            $class->setId('linkedin_post');
        }
        $class->setTitle('');
        $class->setGroup('');
        $class->setLayoutDefinitions($rootPanel);

        return $class;
    }

    private function hasFields(ClassDefinition $definition): bool
    {
        $fields = $definition->getFieldDefinitions();

        return is_array($fields) && count($fields) > 0;
    }
}
