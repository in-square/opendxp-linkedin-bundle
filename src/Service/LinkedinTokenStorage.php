<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Service;

use OpenDxp\Model\WebsiteSetting;

class LinkedinTokenStorage
{
    private const SETTING_PREFIX = 'linkedin_token_';

    public function __construct(private readonly string $environment)
    {
    }

    public function getToken(): ?array
    {
        $setting = WebsiteSetting::getByName($this->getSettingName());
        if (!$setting) {
            return null;
        }

        $data = $setting->getData();
        if (is_array($data)) {
            return $data;
        }

        if (!is_string($data) || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveToken(array $token): void
    {
        $setting = WebsiteSetting::getByName($this->getSettingName());
        if (!$setting) {
            $setting = (new WebsiteSetting())
                ->setName($this->getSettingName())
                ->setType('text');
        }

        $setting
            ->setData(json_encode($token, JSON_UNESCAPED_SLASHES))
            ->save();
    }

    public function clearToken(): void
    {
        $setting = WebsiteSetting::getByName($this->getSettingName());
        if ($setting) {
            $setting->setData('')->save();
        }
    }

    public function isExpired(array $token, int $leewaySeconds = 0): bool
    {
        $expiresAt = $this->getExpiresAt($token);
        if (!$expiresAt) {
            return false;
        }

        return $expiresAt <= time() + $leewaySeconds;
    }

    public function isRefreshExpired(array $token): bool
    {
        $refreshExpiresAt = $this->getRefreshExpiresAt($token);
        if (!$refreshExpiresAt) {
            return false;
        }

        return $refreshExpiresAt <= time();
    }

    public function getExpiresAt(array $token): ?int
    {
        if (isset($token['expires_at'])) {
            return (int) $token['expires_at'];
        }

        if (isset($token['created_at'], $token['expires_in'])) {
            return (int) $token['created_at'] + (int) $token['expires_in'];
        }

        return null;
    }

    public function getRefreshExpiresAt(array $token): ?int
    {
        if (isset($token['refresh_expires_at'])) {
            return (int) $token['refresh_expires_at'];
        }

        if (isset($token['created_at'], $token['refresh_token_expires_in'])) {
            return (int) $token['created_at'] + (int) $token['refresh_token_expires_in'];
        }

        return null;
    }

    private function getSettingName(): string
    {
        return self::SETTING_PREFIX . $this->environment;
    }
}
