<?php

namespace Tests\Feature;

use App\Support\DomainNotice;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Modal "domain telah bertukar" (config/domain_notice.php) - dipaparkan hanya bila dihidupkan, URL baharu sah,
 * belum lepas tarikh akhir, dan hos semasa bukan domain baharu. Dirender melalui renderHook BODY_END panel admin.
 */
class DomainNoticeTest extends TestCase
{
    private function configure(array $overrides = []): void
    {
        config(['domain_notice' => $overrides + [
            'enabled' => true,
            'new_url' => 'https://os.merchant9.example',
            'until' => null,
        ]]);
    }

    private function requestFor(string $url): Request
    {
        return Request::create($url);
    }

    public function test_it_is_shown_on_the_old_domain_when_enabled(): void
    {
        $this->configure();

        $this->assertTrue(DomainNotice::shouldShow($this->requestFor('https://lama.example/admin')));
    }

    public function test_it_is_hidden_on_the_new_domain_regardless_of_case(): void
    {
        $this->configure(['new_url' => 'https://OS.Merchant9.example/']);

        $this->assertFalse(DomainNotice::shouldShow($this->requestFor('https://os.merchant9.example/admin')));
    }

    public function test_it_is_hidden_when_disabled_or_the_new_url_is_missing_or_invalid(): void
    {
        foreach ([
            ['enabled' => false],
            ['new_url' => null],
            ['new_url' => ''],
            ['new_url' => 'os.merchant9.example'],          // tiada skema
            ['new_url' => 'javascript:alert(1)'],           // skema berbahaya
            ['new_url' => 'ftp://os.merchant9.example'],
        ] as $override) {
            $this->configure($override);

            $this->assertFalse(DomainNotice::shouldShow($this->requestFor('https://lama.example/admin')), json_encode($override));
        }
    }

    public function test_the_until_date_is_inclusive_and_an_invalid_date_does_not_hide_the_notice(): void
    {
        $request = $this->requestFor('https://lama.example/admin');

        $this->configure(['until' => now()->toDateString()]);
        $this->assertTrue(DomainNotice::shouldShow($request), 'Hari terakhir masih dipaparkan');

        $this->configure(['until' => now()->subDay()->toDateString()]);
        $this->assertFalse(DomainNotice::shouldShow($request), 'Selepas tarikh akhir dihentikan');

        $this->configure(['until' => 'bukan-tarikh']);
        $this->assertTrue(DomainNotice::shouldShow($request));
    }

    public function test_the_target_url_keeps_the_current_path_and_query(): void
    {
        $this->configure(['new_url' => 'https://os.merchant9.example/']);

        $this->assertSame(
            'https://os.merchant9.example/admin/users?page=2',
            DomainNotice::targetUrl($this->requestFor('https://lama.example/admin/users?page=2')),
        );
    }

    public function test_the_admin_login_page_renders_the_modal_only_when_it_should(): void
    {
        $this->configure();

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Domain Merchant9 OS telah bertukar')
            ->assertSee('https://os.merchant9.example')
            ->assertSee('Pergi ke alamat baharu')
            ->assertSee('m9os-domain-notice:os.merchant9.example', false);

        $this->configure(['enabled' => false]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('Domain Merchant9 OS telah bertukar');
    }
}
