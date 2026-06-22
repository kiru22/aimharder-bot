<?php
namespace App\Services\Aimharder;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AimharderClient
{
    private CookieJar $jar;

    public function __construct(private string $subdomain, private int $boxId)
    {
        $this->jar = new CookieJar();
    }

    public function login(string $email, string $password, string $fingerprint): void
    {
        $res = $this->http()
            ->withHeaders([
                'Origin'  => 'https://login.aimharder.com',
                'Referer' => 'https://login.aimharder.com/',
            ])
            ->asJson()
            ->post('https://login.aimharder.com/api/login', [
                'username'    => $email,
                'password'    => $password,
                'fingerprint' => $fingerprint,
                'iniframe'    => 0,
            ]);

        $auth = $this->jar->getCookieByName('amhrdrauth')?->getValue()
            ?? $this->extractSetCookie($res, 'amhrdrauth');

        if ($auth === null) {
            throw new AuthException('Login fallido: no se recibió la cookie amhrdrauth (credenciales incorrectas).');
        }

        // Fijar la cookie al dominio padre para que viaje al subdominio del box.
        $this->jar->setCookie(new SetCookie([
            'Name' => 'amhrdrauth', 'Value' => $auth, 'Domain' => 'aimharder.com', 'Path' => '/',
        ]));
    }

    public function listClasses(string $day): array
    {
        return $this->boxHttp()
            ->get($this->boxUrl('/api/bookings'), [
                'day'      => $day,
                'familyId' => '',
                'box'      => $this->boxId,
                '_'        => (int) (microtime(true) * 1000),
            ])
            ->json() ?? [];
    }

    public function book(string $classId, string $day, bool $insist = false): array
    {
        return $this->boxHttp()
            ->asForm()
            ->post($this->boxUrl('/api/book'), [
                'id'       => $classId,
                'day'      => $day,
                'insist'   => $insist ? 1 : 0,
                'familyId' => '',
            ])
            ->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['cookies' => $this->jar])
            ->withHeaders(['User-Agent' => config('aimharder.user_agent')])
            ->retry(config('aimharder.retries'), 250, throw: false);
    }

    private function boxHttp(): PendingRequest
    {
        return $this->http()->withHeaders([
            'Origin'  => $this->boxUrl(''),
            'Referer' => $this->boxUrl('/schedule'),
        ]);
    }

    private function boxUrl(string $path): string
    {
        return "https://{$this->subdomain}.aimharder.com{$path}";
    }

    private function extractSetCookie(Response $res, string $name): ?string
    {
        foreach ($res->toPsrResponse()->getHeader('Set-Cookie') as $line) {
            if (str_contains($line, "$name=")) {
                return explode(';', explode("$name=", $line)[1])[0];
            }
        }

        return null;
    }
}
