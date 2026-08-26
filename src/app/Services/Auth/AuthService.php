<?php
namespace App\Kitchen\app\Services\Auth;


use App\Kitchen\core\Cookie\CookieManager;
use App\Kitchen\app\Models\Auth\JWTModel;
use App\Kitchen\app\Repositories\Auth\LoginRepository;
use App\Kitchen\app\Services\Auth\JwtService;
use App\Kitchen\core\Support\ShiftSession;
use DateTime;

class AuthService {
    private $loginRepository;
    private $cookieManager;
    private $jwtService;

    public function __construct(
        LoginRepository $loginRepository,
        CookieManager $cookieManager,
        JwtService $jwtService) {
        $this->loginRepository = $loginRepository;
        $this->cookieManager = $cookieManager;
        $this->jwtService = $jwtService;
    }

    public function login($data) {
        $data['device_type'] = 'kitchen_tablet';
        $response = $this->loginRepository->login($data);
        if(is_null($response)) return false;

        return $this->setCookiesProcedure($response);
    }

    private function setCookiesProcedure(array $response): bool
    {
        $expiryTokenDate = $this->jwtService->getExpiryDateStringFromToken($response['access_token']);

        if(!$this->cookieManager->setAuthCookie($response['access_token'], $expiryTokenDate)) return false;
        if(!$this->cookieManager->setRefreshCookie($response['refresh_token'])) return false;

        return true;
    }


    public function logout() {
        // La personne en poste est un contexte distinct du compte de la
        // tablette. En sortant du compte, on la retire aussi : sinon le
        // prochain écran pourrait encore lui attribuer des compteurs/tâches.
        ShiftSession::close();
        $this->cookieManager->unsetCookies();
    }
}
