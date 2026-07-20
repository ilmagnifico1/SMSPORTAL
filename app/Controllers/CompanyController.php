<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\CompanyService;

final class CompanyController
{
    public function __construct(private CompanyService $companies)
    {
    }

    public function index(): void
    {
        if (empty($_SESSION['logged'])) {
            header('Location: ' . app_url('login'));
            exit;
        }
        \require_permission('manage_companies');

        $message = '';
        $openModal = false;
        $submittedData = [];
        $isSaveRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && ($_POST['action'] ?? '') === 'save_company';

        if ($isSaveRequest) {
            $submittedData = $_POST;
            if (!\verify_csrf_token($_POST['csrf_token'] ?? '')) {
                $message = 'Token di sicurezza non valido.';
                $openModal = true;
            } else {
                $saved = $this->companies->save($_POST);
                $message = $saved
                    ? 'Azienda salvata.'
                    : 'Impossibile salvare l’azienda. Verifica che il nome non sia già utilizzato.';
                $openModal = !$saved;
            }
        }

        $editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
        $data = $this->companies->pageData($editingId, $submittedData);
        if ($data['editingCompany']) {
            $openModal = true;
        }

        View::render('companies/index', $data + [
            'message' => $message,
            'openModal' => $openModal,
            'flashMessage' => \flash_message(),
        ]);
    }
}
