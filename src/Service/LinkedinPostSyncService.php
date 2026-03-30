<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Service;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use OpenDxp\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use OpenDxp\Model\Asset;
use OpenDxp\Model\Asset\Folder as AssetFolder;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\Folder;
use OpenDxp\Model\DataObject\LinkedinPost;
use OpenDxp\Model\Element\Service as ElementService;
use Psr\Log\LoggerInterface;

class LinkedinPostSyncService
{
    private const DEFAULT_OBJECT_FOLDER_PATH = '/LinkedIn';
    private const DEFAULT_ASSET_FOLDER_PATH = '/linkedin';

    private string $objectFolderPath;
    private string $assetFolderPath;

    public function __construct(
        private readonly LinkedinApiClient $apiClient,
        private readonly LinkedinTokenStorage $tokenStorage,
        private readonly LinkedinOAuthService $oauthService,
        private readonly LoggerInterface $logger,
        private readonly string $organizationUrn,
        string $objectFolderPath = self::DEFAULT_OBJECT_FOLDER_PATH,
        string $assetFolderPath = self::DEFAULT_ASSET_FOLDER_PATH
    ) {
        $this->objectFolderPath = $this->normalizeFolderPath($objectFolderPath, self::DEFAULT_OBJECT_FOLDER_PATH);
        $this->assetFolderPath = $this->normalizeFolderPath($assetFolderPath, self::DEFAULT_ASSET_FOLDER_PATH);
    }

    public function syncLatest(int $limit): array
    {
        $counts = [
            'added' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $token = $this->tokenStorage->getToken();
        if (!$token || empty($token['access_token'])) {
            $this->logAppError('LinkedIn token missing. Run /admin/linkedin/connect to authorize.');
            return $counts;
        }

        $token = $this->refreshTokenIfNeeded($token);
        if (!$token) {
            return $counts;
        }

        if ($this->organizationUrn === '') {
            $this->logAppError('LINKEDIN_ORGANIZATION_URN is missing.');
            return $counts;
        }

        $posts = $this->apiClient->fetchLatestPosts($this->organizationUrn, $limit, $token['access_token']);
        $folder = $this->getOrCreateObjectFolder();

        foreach ($posts as $post) {
            if (!is_array($post)) {
                $counts['skipped']++;
                continue;
            }

            $mapped = $this->mapPost($post);
            if (!$mapped['externalId']) {
                $counts['skipped']++;
                continue;
            }

            $hash = $this->computeHash($mapped);
            $existing = $this->findExisting($mapped['externalId']);

            if ($existing) {
                $existingHash = $this->getStoredHash($existing);
                if ($existingHash === $hash) {
                    $counts['skipped']++;
                    continue;
                }

                $this->applyMappedData($existing, $mapped, $token['access_token']);
                $this->storeHash($existing, $hash);
                $existing->save();
                $counts['updated']++;
                continue;
            }

            $object = new LinkedinPost();
            $object->setParent($folder);
            $object->setKey($this->buildObjectKey($mapped['externalId']));
            $object->setPublished(true);
            $this->applyMappedData($object, $mapped, $token['access_token']);
            $this->storeHash($object, $hash);
            $object->save();
            $counts['added']++;
        }

        return $counts;
    }

    private function mapPost(array $post): array
    {
        $externalId = $post['id'] ?? $post['urn'] ?? $post['shareUrn'] ?? null;
        if (is_array($externalId)) {
            $externalId = $externalId['id'] ?? null;
        }

        $text = $this->extractText($post);
        $permalink = $post['permalink'] ?? $post['permalinkUrl'] ?? $post['url'] ?? null;

        if (!$permalink && is_string($externalId)) {
            $permalink = $this->buildPermalink($externalId);
        }

        return [
            'externalId' => is_string($externalId) ? $externalId : null,
            'text' => $text,
            'permalink' => is_string($permalink) ? $permalink : null,
            'publishedAt' => $this->extractPublishedAt($post),
            'imageUrl' => $this->extractImageUrl($post),
            'organizationUrn' => $this->organizationUrn,
        ];
    }

    private function applyMappedData(LinkedinPost $object, array $mapped, string $accessToken): void
    {
        $object->setExternalId($mapped['externalId']);
        $object->setOrganizationUrn($mapped['organizationUrn']);
        $object->setText($mapped['text']);
        $object->setPermalink($mapped['permalink']);

        if ($mapped['publishedAt'] instanceof DateTimeInterface) {
            $object->setPublishedAt(Carbon::instance($mapped['publishedAt']));
        }

        if ($mapped['imageUrl']) {
            $resolvedUrl = $this->resolveImageUrl($mapped['imageUrl'], $accessToken);
            if ($resolvedUrl) {
                $asset = $this->downloadImageAsset($resolvedUrl, $accessToken);
                if ($asset) {
                    $object->setImage($asset);
                }
            }
        }
    }

    private function computeHash(array $mapped): string
    {
        $data = [
            'externalId' => $mapped['externalId'],
            'text' => $this->normalizeString($mapped['text']),
            'permalink' => $this->normalizeString($mapped['permalink']),
            'publishedAt' => $mapped['publishedAt'] instanceof DateTimeInterface ? $mapped['publishedAt']->format(DATE_ATOM) : null,
            'imageUrl' => $this->normalizeString($mapped['imageUrl']),
        ];

        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function findExisting(string $externalId): ?LinkedinPost
    {
        $listing = new LinkedinPost\Listing();
        $listing->setCondition('externalId = ?', [$externalId]);
        $listing->setLimit(1);
        $existing = $listing->current();

        return $existing instanceof LinkedinPost ? $existing : null;
    }

    private function getOrCreateObjectFolder(): Folder
    {
        $folder = DataObject\Service::createFolderByPath($this->objectFolderPath);
        if (!$folder instanceof Folder) {
            throw new \RuntimeException(sprintf('Invalid object folder path: %s', $this->objectFolderPath));
        }

        return $folder;
    }

    private function getOrCreateAssetFolder(): AssetFolder
    {
        $folder = Asset\Service::createFolderByPath($this->assetFolderPath);
        if (!$folder instanceof AssetFolder) {
            throw new \RuntimeException(sprintf('Invalid assets folder path: %s', $this->assetFolderPath));
        }

        return $folder;
    }

    private function downloadImageAsset(string $imageUrl, string $accessToken): ?Asset\Image
    {
        $hash = hash('sha1', $imageUrl);
        $extension = $this->guessExtension($imageUrl);
        $filename = ElementService::getValidKey('linkedin-' . $hash . '.' . $extension, 'asset');
        $path = rtrim($this->assetFolderPath, '/') . '/' . $filename;

        $existing = Asset::getByPath($path);
        if ($existing instanceof Asset\Image) {
            return $existing;
        }

        $download = $this->apiClient->downloadBinary($imageUrl, $accessToken);
        if (!$download || $download['content'] === '') {
            return null;
        }

        $contentType = $download['content_type'] ?? '';
        if ($contentType) {
            $extension = $this->guessExtension($imageUrl, $contentType);
            $filename = ElementService::getValidKey('linkedin-' . $hash . '.' . $extension, 'asset');
        }

        $folder = $this->getOrCreateAssetFolder();
        $asset = Asset::create($folder->getId(), [
            'filename' => $filename,
            'data' => $download['content'],
        ], true);

        return $asset instanceof Asset\Image ? $asset : null;
    }

    private function resolveImageUrl(string $reference, string $accessToken): ?string
    {
        if (str_starts_with($reference, 'http')) {
            return $reference;
        }

        if (str_starts_with($reference, 'urn:li:image:')) {
            return $this->apiClient->resolveImageUrl($reference, $accessToken);
        }

        return null;
    }

    private function guessExtension(string $url, string $contentType = ''): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        $extension = strtolower($extension);

        if ($contentType) {
            if (str_contains($contentType, 'png')) {
                return 'png';
            }
            if (str_contains($contentType, 'gif')) {
                return 'gif';
            }
        }

        return $extension !== '' ? $extension : 'jpg';
    }

    private function normalizeFolderPath(string $path, string $default): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = $default;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function buildObjectKey(string $externalId): string
    {
        $key = str_replace(['urn:li:', ':'], ['','-'], $externalId);
        $key = trim($key);
        if ($key === '') {
            $key = 'linkedin-post';
        }

        return ElementService::getValidKey($key, 'object');
    }

    private function extractPublishedAt(array $post): ?DateTimeImmutable
    {
        $candidates = [
            $post['publishedAt'] ?? null,
            $post['createdAt'] ?? null,
            $post['created'] ?? null,
            $post['lastModifiedAt'] ?? null,
            $post['firstPublishedAt'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['time'] ?? $candidate['timestamp'] ?? null;
            }

            if (is_numeric($candidate)) {
                $timestamp = (int) $candidate;
                if ($timestamp > 20000000000) {
                    $timestamp = (int) round($timestamp / 1000);
                }

                return (new DateTimeImmutable())->setTimestamp($timestamp);
            }

            if (is_string($candidate) && $candidate !== '') {
                try {
                    return new DateTimeImmutable($candidate);
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return null;
    }

    private function extractText(array $post): ?string
    {
        $text = $post['text'] ?? $post['commentary'] ?? null;

        if (is_array($text)) {
            $text = $text['text'] ?? $text['message'] ?? null;
        }

        if (!$text && isset($post['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'])) {
            $text = $post['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'];
        }

        return is_string($text) ? trim($text) : null;
    }

    private function extractImageUrl(array $post): ?string
    {
        $direct = $post['imageUrl'] ?? $post['image'] ?? $post['thumbnail'] ?? $post['thumbnailUrl'] ?? null;
        if (is_string($direct) && str_starts_with($direct, 'http')) {
            return $direct;
        }

        $content = $post['content'] ?? null;
        if (is_array($content)) {
            $contentMedia = $content['media'] ?? null;
            $candidate = $this->extractImageReferenceFromMedia($contentMedia);
            if ($candidate) {
                return $candidate;
            }

            if (isset($content['multiImage']['images']) && is_array($content['multiImage']['images'])) {
                foreach ($content['multiImage']['images'] as $image) {
                    if (!is_array($image)) {
                        continue;
                    }
                    $candidate = $this->extractImageReferenceFromMedia($image);
                    if ($candidate) {
                        return $candidate;
                    }
                }
            }

            if (isset($content['article']['thumbnail']) && is_string($content['article']['thumbnail'])) {
                return $content['article']['thumbnail'];
            }
        }

        $media = $post['media'] ?? null;
        $candidate = $this->extractImageReferenceFromMedia($media);
        if ($candidate) {
            return $candidate;
        }

        if (isset($post['specificContent']['com.linkedin.ugc.ShareContent']['media'])
            && is_array($post['specificContent']['com.linkedin.ugc.ShareContent']['media'])) {
            $candidate = $this->extractImageReferenceFromMedia($post['specificContent']['com.linkedin.ugc.ShareContent']['media']);
            if ($candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractImageReferenceFromMedia(mixed $media): ?string
    {
        if (!is_array($media)) {
            return null;
        }

        if ($this->isAssocArray($media)) {
            return $this->extractImageReferenceFromMediaItem($media);
        }

        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = $this->extractImageReferenceFromMediaItem($item);
            if ($candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractImageReferenceFromMediaItem(array $item): ?string
    {
        $url = $item['url'] ?? $item['originalUrl'] ?? $item['contentUrl'] ?? $item['downloadUrl'] ?? null;
        if (is_string($url) && str_starts_with($url, 'http')) {
            return $url;
        }

        $thumbnail = $item['thumbnail'] ?? null;
        if (is_string($thumbnail) && (str_starts_with($thumbnail, 'http') || str_starts_with($thumbnail, 'urn:li:image:'))) {
            return $thumbnail;
        }
        if (is_array($thumbnail)) {
            $thumbUrl = $thumbnail['url'] ?? $thumbnail['resolvedUrl'] ?? $thumbnail['downloadUrl'] ?? null;
            if (is_string($thumbUrl) && str_starts_with($thumbUrl, 'http')) {
                return $thumbUrl;
            }
        }

        $id = $item['id'] ?? $item['image'] ?? $item['media'] ?? null;
        if (is_string($id)) {
            return $id;
        }

        if (isset($item['images']) && is_array($item['images'])) {
            foreach ($item['images'] as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $candidate = $this->extractImageReferenceFromMediaItem($image);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        if (isset($item['thumbnails']) && is_array($item['thumbnails'])) {
            foreach ($item['thumbnails'] as $thumb) {
                if (!is_array($thumb)) {
                    continue;
                }
                $thumbUrl = $thumb['url'] ?? $thumb['resolvedUrl'] ?? $thumb['downloadUrl'] ?? null;
                if (is_string($thumbUrl) && str_starts_with($thumbUrl, 'http')) {
                    return $thumbUrl;
                }
                $thumbId = $thumb['id'] ?? null;
                if (is_string($thumbId)) {
                    return $thumbId;
                }
            }
        }

        return null;
    }

    private function isAssocArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function buildPermalink(string $externalId): string
    {
        return 'https://www.linkedin.com/feed/update/' . rawurlencode($externalId) . '/';
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function getStoredHash(LinkedinPost $post): ?string
    {
        $value = $post->getContentHash();
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $property = $post->getProperty('contentHash');
        return is_string($property) ? $property : null;
    }

    private function storeHash(LinkedinPost $post, string $hash): void
    {
        $post->setContentHash($hash);
        $post->setProperty('contentHash', 'text', $hash);
    }

    private function logAppError(string $message): void
    {
        ApplicationLogger::getInstance('linkedin')->error($message);
        $this->logger->error($message);
    }

    private function refreshTokenIfNeeded(array $token): ?array
    {
        if (!$this->tokenStorage->isExpired($token, 300)) {
            return $token;
        }

        $refreshToken = $token['refresh_token'] ?? null;
        if (!$refreshToken || $this->tokenStorage->isRefreshExpired($token)) {
            $this->logAppError('LinkedIn token expired. Re-authorize via /admin/linkedin/connect.');
            return null;
        }

        try {
            $refreshed = $this->oauthService->refreshAccessToken($refreshToken);
        } catch (\Throwable $exception) {
            $this->logAppError('LinkedIn token refresh failed: ' . $exception->getMessage());
            return null;
        }

        $merged = array_merge($token, $refreshed);
        if (empty($merged['refresh_token'])) {
            $merged['refresh_token'] = $refreshToken;
        }
        if (!isset($merged['refresh_expires_at']) && isset($token['refresh_expires_at'])) {
            $merged['refresh_expires_at'] = $token['refresh_expires_at'];
        }
        if (!isset($merged['refresh_token_expires_in']) && isset($token['refresh_token_expires_in'])) {
            $merged['refresh_token_expires_in'] = $token['refresh_token_expires_in'];
        }

        $this->tokenStorage->saveToken($merged);
        $this->logger->info('LinkedIn access token refreshed.');

        return $merged;
    }
}
