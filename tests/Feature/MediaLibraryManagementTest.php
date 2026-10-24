<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Models\Warning;
use App\Services\Media\MediaManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_user_can_upload_profile_picture_avatar_with_thumbnails(): void
    {
        $service = app(MediaManagementService::class);
        $user = User::factory()->create(['name' => 'Officer Miller']);

        $file = UploadedFile::fake()->image('miller_photo.jpg', 600, 600);

        $media = $service->uploadAvatar($user, $file);

        $this->assertInstanceOf(Media::class, $media);
        $this->assertEquals('avatar', $media->collection_name);
        $this->assertTrue($media->isImage());
        $this->assertFalse($media->isPdf());
        $this->assertNotEmpty($media->human_readable_size);

        // Single file collection ensures only 1 avatar exists
        $newFile = UploadedFile::fake()->image('miller_photo_v2.png', 400, 400);
        $newMedia = $service->uploadAvatar($user, $newFile);

        $user->refresh();
        $this->assertCount(1, $user->getMedia('avatar'));
        $this->assertEquals($newMedia->id, $user->getFirstMedia('avatar')->id);

        // User avatar_url attribute returns media url
        $this->assertNotEmpty($user->avatar_url);
    }

    public function test_document_and_pdf_upload_collection(): void
    {
        $service = app(MediaManagementService::class);
        $user = User::factory()->create(['name' => 'Resident Sarah']);

        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $pdf = UploadedFile::fake()->createWithContent('lease_contract.pdf', $pdfContent);

        $media = $service->attachDocument($user, $pdf, 'documents', 'Lease Contract 2026');

        $this->assertNotNull($media);
        $this->assertEquals('documents', $media->collection_name);
        $this->assertEquals('Lease Contract 2026', $media->name);
        $this->assertEquals('application/pdf', $media->mime_type);
        $this->assertTrue($media->isPdf());
        $this->assertFalse($media->isImage());
    }

    public function test_warning_incident_evidence_attachments(): void
    {
        $service = app(MediaManagementService::class);
        $user = User::factory()->create(['name' => 'Patrol Officer']);

        $warning = Warning::create([
            'title' => 'Perimeter Breach Warning',
            'description' => 'Unidentified vehicle parked along perimeter',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'issued_at' => now(),
        ]);

        $photo1 = UploadedFile::fake()->image('evidence_car1.jpg', 800, 600);
        $photo2 = UploadedFile::fake()->image('evidence_car2.jpg', 800, 600);
        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $reportPdf = UploadedFile::fake()->createWithContent('incident_report.pdf', $pdfContent);

        $service->attachWarningEvidence($warning, $photo1);
        $service->attachWarningEvidence($warning, $photo2);
        $service->attachWarningEvidence($warning, $reportPdf);

        $warning->refresh();
        $this->assertCount(3, $warning->getMedia('evidence'));

        // Collection summary
        $summary = $service->getCollectionSummary($warning, 'evidence');
        $this->assertCount(3, $summary);
        $this->assertTrue($summary->first()['is_image']);
        $this->assertTrue($summary->last()['is_pdf']);
    }

    public function test_filesystem_disks_and_cloud_storage_configuration(): void
    {
        $service = app(MediaManagementService::class);
        $supportedDisks = $service->getSupportedDisks();

        $this->assertArrayHasKey('local', $supportedDisks);
        $this->assertArrayHasKey('public', $supportedDisks);
        $this->assertArrayHasKey('s3', $supportedDisks);
        $this->assertArrayHasKey('spaces', $supportedDisks);
        $this->assertArrayHasKey('gcs', $supportedDisks);
        $this->assertArrayHasKey('azure', $supportedDisks);

        $disksConfig = config('filesystems.disks');
        $this->assertEquals('s3', $disksConfig['s3']['driver']);
        $this->assertEquals('s3', $disksConfig['spaces']['driver']);
        $this->assertEquals('s3', $disksConfig['gcs']['driver']);
        $this->assertNotEmpty($disksConfig['public']['root']);
    }
}
