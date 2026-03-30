<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle;

use InSquare\OpendxpLinkedinBundle\DependencyInjection\InSquareOpendxpLinkedinExtension;
use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\Extension\Bundle\Installer\InstallerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class InSquareOpendxpLinkedinBundle extends AbstractOpenDxpBundle
{
    public function getRoutesPath(): ?string
    {
        return __DIR__ . '/Resources/config/routing.yaml';
    }

    public function getInstaller(): ?InstallerInterface
    {
        return $this->container->get(Installer::class);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new InSquareOpendxpLinkedinExtension();
        }

        return $this->extension;
    }
}
