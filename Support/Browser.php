<?php

declare(strict_types=1);

namespace Characterization;

use RuntimeException;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

final class Browser
{
    public HttpBrowser $client;
    private Config $config;
    private Fixtures $fixtures;

    public function __construct(Config $config, Fixtures $fixtures)
    {
        $this->config = $config;
        $this->fixtures = $fixtures;
        $options = [
            'timeout' => 20, 'max_duration' => 30, 'max_redirects' => 0, 'http_version' => '1.1',
        ];
        $ip = getenv('CHAR_HTTP_IP');
        if ($ip !== false && $ip !== '') {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('CHAR_HTTP_IP must be an IP address.');
            }
            $options['resolve'] = [parse_url($config->baseUrl, PHP_URL_HOST) => $ip];
        }
        $this->client = new HttpBrowser(HttpClient::create($options));
        $this->client->followRedirects(false);
    }

    public function get(string $path): Crawler
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $parameters): Crawler
    {
        return $this->request('POST', $path, $parameters);
    }

    private function request(string $method, string $path, array $parameters = []): Crawler
    {
        if (!preg_match('#^/(?!/)[a-zA-Z0-9_/?=&%.+-]*$#D', $path)) {
            throw new RuntimeException('Requests must use a relative application path.');
        }
        $crawler = $this->client->request($method, $this->config->baseUrl.$path, $parameters);
        $cookie = $this->client->getCookieJar()->get('waf_session_idp');
        if ($cookie !== null) {
            $this->fixtures->rememberSession($cookie->getValue());
        }

        return $crawler;
    }

    public function csrf(string $formPath): array
    {
        $crawler = $this->get($formPath);
        $input = $crawler->filter('input[name="csrf_token_idp"], input[name="ci_csrf_token"]');
        if ($this->status() !== 200 || $input->count() === 0) {
            throw new RuntimeException('No CSRF form at '.$formPath.' (HTTP '.$this->status().').');
        }
        $name = $input->first()->attr('name');
        return [$name => $input->first()->attr('value')];
    }

    public function login(string $kind = 'allowed', ?string $password = null): void
    {
        $token = $this->csrf('/admin');
        $this->post('/pbc_login/do_login_admin', $token + [
            'email_admin' => $this->fixtures->username($kind),
            'password_admin' => $password ?? $this->config->password,
        ]);
    }

    public function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    public function body(): string
    {
        return $this->client->getResponse()->getContent();
    }

    public function redirect(): ?string
    {
        $response = $this->client->getResponse();
        $target = $response->getHeader('Location');
        if ($target === null && preg_match('/^\d+;\s*url=(.+)$/i', (string) $response->getHeader('Refresh'), $match)) {
            $target = $match[1];
        }
        if ($target === null) {
            return null;
        }
        if (strpos($target, $this->config->baseUrl.'/') === 0) {
            return substr($target, strlen($this->config->baseUrl));
        }

        return $target;
    }
}
