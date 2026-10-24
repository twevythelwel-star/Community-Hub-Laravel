<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\User;
use App\Services\Credentials\GoogleWalletPass;
use App\Services\Credentials\WalletCredentialCode;
use App\Services\DigitalAccessWalletService;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Wallet passes (Apple, Google) carry a signed code; the gate accepts that
 * and refuses a bare pass ID or an unsigned NFC string. Apple and Google are
 * offered only once their credentials are configured; Samsung not at all.
 */
class WalletPassesTest extends TestCase
{
    use RefreshDatabase;

    private User $homeowner;

    private User $guard;

    private GatePass $pass;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Smith']);
        $this->guard = User::factory()->role(UserRole::Security)->create();
        $this->pass = app(GatePassEngine::class)->issuePassFor($this->homeowner);

        config(['wallet.apple' => ['organization_name' => 'Cypress Bay'] + array_fill_keys(
            ['pass_type_identifier', 'team_identifier', 'certificate', 'certificate_password', 'wwdr_certificate'], null
        )]);
        config(['wallet.google' => ['issuer_id' => null, 'service_account_key' => null, 'class_suffix' => 'estate_access']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ── The signed wallet code at the gate ──

    public function test_a_signed_wallet_code_checks_in_as_a_wallet_pass(): void
    {
        $code = app(WalletCredentialCode::class)->for($this->pass);

        $result = app(GateScanner::class)->scan($code, GateId::Gate01, $this->guard);

        $this->assertSame('CHECK_IN', $result['decision']);
        $this->assertDatabaseHas('access_log_entries', ['pass_id' => $this->pass->pass_id, 'method' => 'Mobile Wallet Pass', 'result' => 'ALLOW']);
    }

    public function test_the_nfc_message_checks_in_as_an_nfc_tap(): void
    {
        $message = app(WalletCredentialCode::class)->nfcMessageFor($this->pass);

        $result = app(GateScanner::class)->scan($message, GateId::Gate01, $this->guard);

        $this->assertSame('CHECK_IN', $result['decision']);
        $this->assertDatabaseHas('access_log_entries', ['pass_id' => $this->pass->pass_id, 'method' => 'NFC Contactless Tap']);
    }

    public function test_a_keycard_read_by_the_guard_is_checked_in_as_an_nfc_tap(): void
    {
        // What the wallet page writes to a keycard, and the guard's phone reads back.
        $keycard = app(DigitalAccessWalletService::class)->formatWalletPass($this->pass, 'Homeowner')['nfc']['payload'];

        $this->actingAs($this->guard)
            ->postJson('/dashboard/gate-pass/scan', ['token' => $keycard, 'gate' => 'GATE-01'])
            ->assertOk()
            ->assertJsonPath('decision', 'CHECK_IN');

        $this->assertDatabaseHas('access_log_entries', ['pass_id' => $this->pass->pass_id, 'method' => 'NFC Contactless Tap']);
    }

    public function test_a_bare_pass_id_is_refused(): void
    {
        $result = app(GateScanner::class)->scan($this->pass->pass_id, GateId::Gate01, $this->guard);

        $this->assertSame('REJECT', $result['decision']);
        $this->assertNull($result['scanId']);
    }

    public function test_an_unsigned_or_forged_nfc_string_is_refused(): void
    {
        $scanner = app(GateScanner::class);
        $passId = $this->pass->pass_id;

        foreach ([
            "CHUB-NFC-V2|{$passId}|anything",
            "NFC:{$passId}",
            "CHW1.{$passId}.forged-signature",
            "NFC:CHW1.{$passId}.",
        ] as $forged) {
            $this->assertSame('REJECT', $scanner->scan($forged, GateId::Gate01, $this->guard)['decision'], $forged);
        }
    }

    public function test_a_code_for_one_pass_cannot_be_moved_to_another(): void
    {
        $other = app(GatePassEngine::class)->issuePassFor(User::factory()->role(UserRole::Homeowner)->create());
        $code = app(WalletCredentialCode::class)->for($this->pass);
        $signature = substr($code, strrpos($code, '.') + 1);

        $moved = WalletCredentialCode::PREFIX.$other->pass_id.'.'.$signature;

        $this->assertNull(app(WalletCredentialCode::class)->passIdFrom($moved));
    }

    public function test_a_guard_override_may_still_name_a_pass_id(): void
    {
        $result = app(GateScanner::class)->scan(
            $this->pass->pass_id, GateId::Gate01, $this->guard, 'Digital Pass', 'in', true, 'Homeowner confirmed by phone.'
        );

        $this->assertSame('CHECK_IN', $result['decision']);
    }

    public function test_an_override_without_a_reason_does_not_accept_a_pass_id(): void
    {
        $result = app(GateScanner::class)->scan($this->pass->pass_id, GateId::Gate01, $this->guard, 'Digital Pass', 'in', true, '');

        $this->assertSame('REJECT', $result['decision']);
    }

    public function test_the_wallet_nfc_payload_is_the_signed_message(): void
    {
        $credential = app(DigitalAccessWalletService::class)->formatWalletPass($this->pass, 'Homeowner');

        $this->assertSame(app(WalletCredentialCode::class)->nfcMessageFor($this->pass), $credential['nfc']['payload']);
    }

    // ── Not configured: nothing offered ──

    public function test_wallets_are_not_offered_until_configured(): void
    {
        $credential = app(DigitalAccessWalletService::class)->formatWalletPass($this->pass, 'Homeowner');

        $this->assertFalse($credential['wallet_integrations']['apple_wallet']['available']);
        $this->assertFalse($credential['wallet_integrations']['google_wallet']['available']);
        $this->assertArrayNotHasKey('samsung_wallet', $credential['wallet_integrations']);

        $this->actingAs($this->homeowner);
        $this->get("/dashboard/wallet/apple-pass/{$this->pass->pass_id}")->assertNotFound();
        $this->getJson("/dashboard/wallet/google-pass/{$this->pass->pass_id}")->assertNotFound();
    }

    public function test_samsung_wallet_is_not_available(): void
    {
        $this->actingAs($this->homeowner)
            ->getJson("/dashboard/wallet/samsung-pass/{$this->pass->pass_id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Samsung Wallet is not available.');
    }

    // ── Apple Wallet ──

    public function test_apple_pass_is_signed_and_carries_the_wallet_code(): void
    {
        $this->configureApple();

        $response = $this->actingAs($this->homeowner)
            ->get("/dashboard/wallet/apple-pass/{$this->pass->pass_id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.pkpass');

        $zipPath = $this->tempFile('pkpass_', $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        foreach (['pass.json', 'manifest.json', 'signature', 'icon.png', 'icon@2x.png', 'logo.png'] as $required) {
            $this->assertArrayHasKey($required, $files);
        }

        // The manifest lists the SHA-1 of every other file.
        $manifest = json_decode($files['manifest.json'], true);
        foreach ($files as $name => $contents) {
            if (! in_array($name, ['manifest.json', 'signature'], true)) {
                $this->assertSame(sha1($contents), $manifest[$name] ?? null, $name);
            }
        }

        // The signature is a valid detached signature of the manifest. For a
        // detached signature PHP takes the signed data first and the
        // signature as $sigfile; $content would be an output file.
        $this->assertTrue(openssl_cms_verify(
            $this->tempFile('pkmanifest_', $files['manifest.json']),
            OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
            null, [], null, null, null,
            $this->tempFile('pksig_', $files['signature']),
            OPENSSL_ENCODING_DER,
        ), (string) openssl_error_string());

        // A changed manifest no longer matches it.
        $this->assertFalse(openssl_cms_verify(
            $this->tempFile('pkmanifest_', $files['manifest.json'].' '),
            OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
            null, [], null, null, null,
            $this->tempFile('pksig_', $files['signature']),
            OPENSSL_ENCODING_DER,
        ));

        $pass = json_decode($files['pass.json'], true);
        $this->assertSame('pass.test.communityhub', $pass['passTypeIdentifier']);
        $this->assertSame('TEAM123456', $pass['teamIdentifier']);
        $this->assertSame(app(WalletCredentialCode::class)->for($this->pass), $pass['barcodes'][0]['message']);
        $this->assertArrayNotHasKey('nfc', $pass);
    }

    public function test_apple_pass_is_offered_once_configured(): void
    {
        $this->configureApple();

        $credential = app(DigitalAccessWalletService::class)->formatWalletPass($this->pass, 'Homeowner');

        $this->assertTrue($credential['wallet_integrations']['apple_wallet']['available']);
    }

    public function test_someone_elses_apple_pass_cannot_be_downloaded(): void
    {
        $this->configureApple();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get("/dashboard/wallet/apple-pass/{$this->pass->pass_id}")
            ->assertForbidden();
    }

    // ── Google Wallet ──

    public function test_google_link_is_a_signed_save_to_wallet_jwt(): void
    {
        $publicKey = $this->configureGoogle();

        $saveUrl = $this->actingAs($this->homeowner)
            ->getJson("/dashboard/wallet/google-pass/{$this->pass->pass_id}")
            ->assertOk()
            ->json('save_url');

        $this->assertStringStartsWith(GoogleWalletPass::SAVE_URL, $saveUrl);

        [$header, $claims, $signature] = explode('.', substr($saveUrl, strlen(GoogleWalletPass::SAVE_URL)));
        $decode = fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $decode($signature), $publicKey, OPENSSL_ALGO_SHA256));

        $claims = json_decode($decode($claims), true);
        $this->assertSame('wallet@test-project.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('savetowallet', $claims['typ']);

        $object = $claims['payload']['genericObjects'][0];
        $this->assertSame('3388000000012345678.'.$this->pass->pass_id, $object['id']);
        $this->assertSame('3388000000012345678.estate_access', $object['classId']);
        $this->assertSame(app(WalletCredentialCode::class)->for($this->pass), $object['barcode']['value']);
    }

    public function test_someone_elses_google_pass_cannot_be_saved(): void
    {
        $this->configureGoogle();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->getJson("/dashboard/wallet/google-pass/{$this->pass->pass_id}")
            ->assertForbidden();
    }

    // ── Fixtures ──

    /**
     * A throwaway CA standing in for Apple's WWDR certificate, and a pass
     * certificate it issued, exported as a password-protected .p12.
     */
    private function configureApple(): void
    {
        $options = $this->openSslOptions();

        $caKey = openssl_pkey_new($options);
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test WWDR'], $caKey, $options), null, $caKey, 1, $options);

        $passKey = openssl_pkey_new($options);
        $passCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Pass Type ID: pass.test.communityhub'], $passKey, $options), $ca, $caKey, 1, $options, 2);

        openssl_pkcs12_export($passCert, $p12, $passKey, 'secret');
        openssl_x509_export($ca, $caPem);

        config(['wallet.apple' => [
            'pass_type_identifier' => 'pass.test.communityhub',
            'team_identifier' => 'TEAM123456',
            'certificate' => $this->tempFile('pass_', $p12),
            'certificate_password' => 'secret',
            'wwdr_certificate' => $this->tempFile('wwdr_', $caPem),
            'organization_name' => 'Cypress Bay',
        ]]);
    }

    /**
     * @return string The public key matching the configured service-account key.
     */
    private function configureGoogle(): string
    {
        $options = $this->openSslOptions();
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $privatePem, null, $options);

        config(['wallet.google' => [
            'issuer_id' => '3388000000012345678',
            'service_account_key' => $this->tempFile('gsa_', json_encode([
                'client_email' => 'wallet@test-project.iam.gserviceaccount.com',
                'private_key' => $privatePem,
            ])),
            'class_suffix' => 'estate_access',
        ]]);

        return openssl_pkey_get_details($key)['key'];
    }

    /**
     * Key generation needs an openssl.cnf; Windows builds of PHP ship one
     * beside the binary but do not point OpenSSL at it.
     *
     * @return array<string, mixed>
     */
    private function openSslOptions(): array
    {
        $options = ['private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';

        if (! getenv('OPENSSL_CONF') && is_file($bundled)) {
            $options['config'] = $bundled;
        }

        return $options;
    }

    private function tempFile(string $prefix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
