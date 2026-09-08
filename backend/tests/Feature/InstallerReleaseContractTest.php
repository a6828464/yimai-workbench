<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallerReleaseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_installer_accepts_six_character_password_and_verifies_enabled_admin(): void
    {
        $source = file_get_contents(public_path('install.php'));

        $this->assertStringContainsString('mb_strlen($adminPass) < 6', $source);
        $this->assertStringContainsString("'status' => '启用'", $source);
        $this->assertStringContainsString('Hash::check($adminPass, $admin->password)', $source);
        $this->assertStringContainsString('minlength="6"', $source);
    }

    public function test_release_builders_replace_the_installer_landing_page_only_in_fresh_packages(): void
    {
        $root = dirname(base_path());
        $localBuilder = file_get_contents($root.'/make-release.sh');
        $ciBuilder = file_get_contents($root.'/.github/workflows/release-latest.yml');
        $landingPage = file_get_contents(resource_path('install-index.html'));

        $this->assertStringContainsString('url=/install.php', $landingPage);
        $this->assertStringContainsString('public/app.html', $localBuilder);
        $this->assertStringContainsString('resources/install-index.html', $localBuilder);
        $this->assertStringContainsString('public/app.html', $ciBuilder);
        $this->assertStringContainsString('resources/install-index.html', $ciBuilder);
    }

    public function test_installed_six_character_admin_is_enabled_and_can_log_in(): void
    {
        User::create([
            'username' => 'admin',
            'name' => 'old-admin',
            'email' => 'old-admin@local.invalid',
            'password' => 'old-password',
            'role' => 'R_SUPER',
            'status' => '停用',
        ]);

        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'admin',
                'password' => '123456',
                'role' => 'R_SUPER',
                'status' => '启用',
                'venue' => null,
                'venues' => ['绿地店', '东部店'],
                'email' => 'admin@local.invalid',
                'email_verified_at' => now(),
            ]
        );

        $this->assertTrue(Hash::check('123456', $admin->refresh()->password));
        $this->postJson('/api/auth/login', [
            'userName' => 'admin',
            'password' => '123456',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.userInfo.roles.0', 'R_SUPER')
            ->assertJsonStructure(['data' => ['token']]);
    }
}
