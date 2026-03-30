<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Service;

final readonly class LinkedinSettings
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public function getObjectFolderPath(): string
    {
        return (string) $this->config['object_folder'];
    }

    public function getAssetsFolderPath(): string
    {
        return (string) $this->config['assets_folder'];
    }

    public function getItemsLimit(): int
    {
        return (int) $this->config['items_limit'];
    }
}
