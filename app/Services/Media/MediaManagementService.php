<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use App\Models\Warning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;

class MediaManagementService
{
    /**
     * Supported storage drivers in Community Hub filesystem architecture.
     *
     * @return array<string, array{driver: string, label: string, is_cloud: bool}>
     */
    public function getSupportedDisks(): array
    {
        return [
            'local' => ['driver' => 'local', 'label' => 'Local Private Storage', 'is_cloud' => false],
            'public' => ['driver' => 'local', 'label' => 'Local Public Web Storage', 'is_cloud' => false],
            's3' => ['driver' => 's3', 'label' => 'Amazon Web Services S3', 'is_cloud' => true],
            'spaces' => ['driver' => 's3', 'label' => 'DigitalOcean Spaces', 'is_cloud' => true],
            'gcs' => ['driver' => 's3', 'label' => 'Google Cloud Storage', 'is_cloud' => true],
            'azure' => ['driver' => 'local', 'label' => 'Microsoft Azure Blob Storage', 'is_cloud' => true],
        ];
    }

    /**
     * Upload and assign an avatar profile picture to a User.
     */
    public function uploadAvatar(User $user, UploadedFile $file): Media
    {
        /** @var Media $media */
        $media = $user->addMedia($file)
            ->usingName($user->name.' Avatar')
            ->usingFileName('avatar_'.$user->id.'_'.time().'.'.$file->getClientOriginalExtension())
            ->toMediaCollection('avatar');

        return $media;
    }

    /**
     * Attach a document (PDF, Doc, ID) to any media-capable model.
     */
    public function attachDocument(
        HasMedia $model,
        UploadedFile $file,
        string $collection = 'documents',
        ?string $customName = null
    ): Media {
        $name = $customName ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        /** @var Media $media */
        $media = $model->addMedia($file)
            ->usingName($name)
            ->toMediaCollection($collection);

        return $media;
    }

    /**
     * Attach incident evidence (photo, video, PDF) to a community Warning.
     */
    public function attachWarningEvidence(Warning $warning, UploadedFile $file): Media
    {
        return $this->attachDocument($warning, $file, 'evidence', 'Evidence '.now()->toDateTimeString());
    }

    /**
     * Get a structured summary of media in a collection.
     *
     * @return Collection<int, array{
     *     id: int,
     *     name: string,
     *     file_name: string,
     *     mime_type: string,
     *     size: int,
     *     human_size: string,
     *     url: string,
     *     thumb_url: ?string,
     *     is_image: bool,
     *     is_pdf: bool,
     *     is_video: bool,
     * }>
     */
    public function getCollectionSummary(HasMedia $model, string $collection): Collection
    {
        return $model->getMedia($collection)->map(function (Media $media) {
            return [
                'id' => $media->id,
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type ?? 'application/octet-stream',
                'size' => (int) $media->size,
                'human_size' => $media->human_readable_size,
                'url' => $media->getUrl(),
                'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
                'is_image' => $media->isImage(),
                'is_pdf' => $media->isPdf(),
                'is_video' => $media->isVideo(),
            ];
        });
    }
}
