<?php

namespace App\Kitchen\app\Http\Controllers\Me;


use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Me\DeviceService;
use App\Kitchen\core\Support\DeviceMode;
use App\Kitchen\core\Support\Route;

class ProfileController extends Controller
{

    public function __construct(
        private DeviceService $deviceService
    ) {}


    #[Route('GET', '/me')]
    public function index()
    {
        // KitchenService n'a jamais existé dans ce dépôt : le nom vient du
        // fork fournisseur, et /me répondait « Błąd DI » — donc l'onglet Profil
        // ne s'ouvrait pas. C'est aussi l'écran des réglages de la tablette :
        // il doit s'ouvrir même quand l'ERP ne répond pas, sinon on ne peut
        // plus changer le mode d'un appareil hors ligne.
        $data['supplier'] = $this->safeFetch(
            fn() => $this->deviceService->getMe(),
            $this->warnings,
            null,
            null
        );

        $this->view("me/about", $this->settingsData($data));
    }

    /**
     * Enregistre les réglages de l'appareil : mode et configuration WebShop.
     *
     * Rien ici ne touche à l'authentification — changer de mode ne doit pas
     * renvoyer l'équipe sur l'écran de connexion en plein service. On écrit le
     * réglage et on redirige vers l'accueil du nouveau mode : rester sur /me
     * conviendrait, mais laisserait la tablette sur un écran de paramètres
     * alors qu'on vient de lui dire à quoi elle sert.
     */
    #[Route('POST', '/me/settings')]
    public function save()
    {
        $mode = DeviceMode::remember((string)($_POST['device_mode'] ?? ''));

        // L'URL n'est écrite que si le formulaire l'a portée : un POST partiel
        // ne doit pas effacer une configuration posée par ailleurs.
        if (array_key_exists('webshop_url', $_POST)) {
            DeviceMode::rememberWebshopUrl($_POST['webshop_url'] ?? '');
        }

        // Le jeton suit une autre règle, et c'est voulu : un champ vide le
        // CONSERVE. Il n'est jamais rendu dans la page — le champ repart donc
        // vide à chaque affichage, et l'effacer sur un simple enregistrement
        // déconfigurerait la tablette au premier changement de mode. On ne
        // l'efface que si on le demande explicitement.
        if (!empty($_POST['webshop_token_clear'])) {
            DeviceMode::rememberWebshopToken('');
        } elseif (trim((string)($_POST['webshop_token'] ?? '')) !== '') {
            DeviceMode::rememberWebshopToken($_POST['webshop_token']);
        }

        redirect(DeviceMode::rules()->home($mode));
    }

    private function settingsData(array $data): array
    {
        $rules = DeviceMode::rules();

        $data['modes']               = $rules->modes();
        $data['webshop_base']        = DeviceMode::webshopBase();
        $data['webshop_base_local']  = trim((string)($_COOKIE[DeviceMode::COOKIE_URL] ?? ''));
        $data['webshop_base_global'] = DeviceMode::webshopBaseIsGlobal();
        // Quatre caractères, jamais le jeton : la page reste lisible par
        // n'importe qui passant devant le comptoir.
        $data['token_hint']          = $rules->tokenHint(DeviceMode::webshopToken());

        return $data;
    }
}
