# Extraction — Mécanique de validation des checklists avec photos

Ce document isole **tout le code qui compose la validation d'une tâche de checklist avec photo**, couche par couche, pour pouvoir le déplacer (vers un autre module, une autre app ou un fichier dédié).

Chaîne complète du flux :

```
Tâche (requires_photo) → bouton « Wykonaj » (data-requires-photo)
    → modal Bootstrap (pracownik + PIN + section photo)
        → JS : caméra getUserMedia / input file / preview / validation photo obligatoire
            → POST multipart /checklists/tasks/{taskId}/complete (FormData avec `photo`)
                → ChecklistController::completeTask  ($_FILES['photo'])
                    → ChecklistService::completeTask  (vérif PIN, passe $photo)
                        → ChecklistRepository::markTaskDone  (files['photo'])
                            → ApiClient::postMultipart  (CURLFile)
                                → API backend POST /employees/{id}/tasks/{id}/mark-as-done
```

Deux catégories de code :

- **[PHOTO]** — blocs 100 % spécifiques à la photo : à déplacer tels quels.
- **[MIXTE]** — blocs partagés avec la validation PIN ; seules les lignes marquées `// [PHOTO]` appartiennent à la mécanique photo.

---

## 1. Vue — `src/app/Views/checklist/index.twig`

### 1a. [PHOTO] Icône « photo requise » sur la ligne de tâche (l. 203–206)

```twig
{% if task.requires_photo %}
    <i class="bi bi-camera-fill ms-1 text-info" style="font-size:.8rem;"
       title="{{ translations.photo_required | default('Wymagane zdjęcie') }}"></i>
{% endif %}
```

### 1b. [MIXTE] Bouton « Wykonaj » — attribut photo (l. 264–272)

Le bouton existe pour toute tâche ; seul l'attribut `data-requires-photo` est photo :

```twig
<button type="button"
        class="btn btn-sm btn-outline-success btn-complete-task"
        data-task-id="{{ task.task_id }}"
        data-task-name="{{ task.name }}"
        data-date="{{ selected_date }}"
        data-requires-photo="{{ task.requires_photo ? '1' : '0' }}">   {# [PHOTO] #}
    <i class="bi bi-check2 me-1"></i>{{ translations.btn_complete | default('Wykonaj') }}
</button>
```

### 1c. [PHOTO] Section photo du modal (l. 378–413)

Bloc entier à déplacer (dans `#completeTaskModal`, après le champ note) :

```twig
<div id="modalPhotoSection" class="mb-1 d-none">
    <label class="form-label fw-semibold">
        {{ translations.modal_photo_label | default('Zdjęcie') }}
        <span class="text-danger">*</span>
    </label>
    <div class="d-flex gap-2 mb-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="modalOpenCameraBtn">
            <i class="bi bi-camera me-1"></i>{{ 'Kamera' }}
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="modalClearPhotoBtn">
            <i class="bi bi-trash me-1"></i>{{ 'Wyczyść' }}
        </button>
    </div>
    <input type="file" id="modalPhotoInput" class="form-control form-control-sm"
           accept="image/*" capture="environment">
    <div id="modalCameraWrap" class="mt-2 d-none">
        <video id="modalCameraVideo" playsinline autoplay
               style="width:100%;border-radius:8px;background:#000;max-height:220px;object-fit:cover;"></video>
        <div class="d-flex gap-2 mt-1">
            <button type="button" class="btn btn-sm btn-primary" id="modalTakePhotoBtn">
                <i class="bi bi-camera me-1"></i>Zrób zdjęcie
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="modalCloseCameraBtn">
                <i class="bi bi-x-circle me-1"></i>Zamknij
            </button>
        </div>
        <canvas id="modalPhotoCanvas" style="display:none;"></canvas>
    </div>
    <div id="modalPhotoPreview" class="mt-2 d-none">
        <img id="modalPhotoPreviewImg" alt="Podgląd"
             style="max-width:100%;border-radius:8px;border:1px solid rgba(0,0,0,.1);">
    </div>
    <div class="text-muted small mt-1">
        {{ translations.modal_photo_hint | default('Zrób zdjęcie lub wybierz plik.') }}
    </div>
</div>
```

---

## 2. JavaScript — `src/app/Views/checklist/index.twig` (bloc `<script>`, l. 430–681)

### 2a. [PHOTO] Message d'erreur (dans l'objet `translations`, l. 440)

```js
complete_error_photo_required: {{ (translations.complete_error_photo_required | default('To zadanie wymaga zdjęcia. Dodaj zdjęcie przed zatwierdzeniem.')) | json_encode | raw }},
```

### 2b. [PHOTO] État (l. 445–446)

```js
let currentRequiresPhoto = false;
let cameraStream        = null;
```

### 2c. [PHOTO] Références DOM (l. 458–469)

```js
// Photo elements
const photoSection   = document.getElementById('modalPhotoSection');
const photoInput     = document.getElementById('modalPhotoInput');
const photoPreview   = document.getElementById('modalPhotoPreview');
const photoPreviewImg = document.getElementById('modalPhotoPreviewImg');
const cameraWrap     = document.getElementById('modalCameraWrap');
const cameraVideo    = document.getElementById('modalCameraVideo');
const photoCanvas    = document.getElementById('modalPhotoCanvas');
const openCameraBtn  = document.getElementById('modalOpenCameraBtn');
const closeCameraBtn = document.getElementById('modalCloseCameraBtn');
const takePhotoBtn   = document.getElementById('modalTakePhotoBtn');
const clearPhotoBtn  = document.getElementById('modalClearPhotoBtn');
```

### 2d. [PHOTO] Fonctions utilitaires (l. 479–503)

```js
function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(t => t.stop());
        cameraStream = null;
    }
    if (cameraVideo) cameraVideo.srcObject = null;
    if (cameraWrap) cameraWrap.classList.add('d-none');
}

function showPhotoPreview(file) {
    if (file) {
        photoPreviewImg.src = URL.createObjectURL(file);
        photoPreview.classList.remove('d-none');
    } else {
        photoPreview.classList.add('d-none');
        photoPreviewImg.removeAttribute('src');
    }
}

function setInputFile(file) {
    const dt = new DataTransfer();
    dt.items.add(file);
    photoInput.files = dt.files;
    showPhotoPreview(file);
}
```

### 2e. [MIXTE] `resetModal()` — lignes photo (l. 505–515)

```js
function resetModal() {
    employeeSel.value = '';
    pinInput.value    = '';
    noteInput.value   = '';
    hideAlert();
    spinner.classList.add('d-none');
    confirmBtn.disabled = false;
    stopCamera();                              // [PHOTO]
    if (photoInput) photoInput.value = '';     // [PHOTO]
    showPhotoPreview(null);                    // [PHOTO]
}
```

### 2f. [MIXTE] Ouverture du modal — lignes photo (l. 517–539)

```js
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-complete-task');
    if (!btn) return;

    currentTaskId        = btn.dataset.taskId;
    currentTaskDate      = btn.dataset.date;
    currentRequiresPhoto = btn.dataset.requiresPhoto === '1';   // [PHOTO]
    const taskName       = btn.dataset.taskName || '';

    if (taskNameEl) taskNameEl.textContent = taskName;
    resetModal();

    if (photoSection) {                                          // [PHOTO]
        if (currentRequiresPhoto) {                              // [PHOTO]
            photoSection.classList.remove('d-none');             // [PHOTO]
        } else {                                                 // [PHOTO]
            photoSection.classList.add('d-none');                // [PHOTO]
        }                                                        // [PHOTO]
    }                                                            // [PHOTO]

    bsModal.show();
});
```

### 2g. [PHOTO] Écouteurs caméra / fichier / effacement (l. 541–600)

```js
// Zmiana pliku przez file input
if (photoInput) {
    photoInput.addEventListener('change', function () {
        const file = photoInput.files && photoInput.files[0];
        showPhotoPreview(file || null);
    });
}

// Kamera — otwórz
if (openCameraBtn) {
    openCameraBtn.addEventListener('click', async function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            photoInput.click();
            return;
        }
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
            cameraVideo.srcObject = cameraStream;
            cameraWrap.classList.remove('d-none');
        } catch (err) {
            photoInput.click();
        }
    });
}

// Kamera — zamknij
if (closeCameraBtn) {
    closeCameraBtn.addEventListener('click', stopCamera);
}

// Kamera — zrób zdjęcie
if (takePhotoBtn) {
    takePhotoBtn.addEventListener('click', function () {
        const w = cameraVideo.videoWidth;
        const h = cameraVideo.videoHeight;
        if (!w || !h || !cameraStream) return;
        photoCanvas.width  = w;
        photoCanvas.height = h;
        const ctx = photoCanvas.getContext('2d', { alpha: false });
        ctx.drawImage(cameraVideo, 0, 0, w, h);
        photoCanvas.toBlob(function (blob) {
            if (!blob) return;
            const file = new File([blob], 'task_photo_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            setInputFile(file);
            stopCamera();
        }, 'image/jpeg', 0.9);
    });
}

// Wyczyść zdjęcie
if (clearPhotoBtn) {
    clearPhotoBtn.addEventListener('click', function () {
        if (photoInput) photoInput.value = '';
        showPhotoPreview(null);
        stopCamera();
    });
}
```

### 2h. [MIXTE] Handler de confirmation — lignes photo (l. 604–642)

```js
confirmBtn.addEventListener('click', async function () {
    hideAlert();

    const employeeId = employeeSel.value;
    const pin        = pinInput.value.trim();
    const note       = noteInput.value.trim();
    const photoFile  = photoInput && photoInput.files && photoInput.files[0]   // [PHOTO]
                        ? photoInput.files[0] : null;                          // [PHOTO]

    if (!employeeId) {
        showAlert(translations.complete_error_employee_required, 'warning');
        return;
    }
    if (!pin) {
        showAlert(translations.complete_error_pin_required, 'warning');
        return;
    }
    if (currentRequiresPhoto && !photoFile) {                                  // [PHOTO]
        showAlert(translations.complete_error_photo_required, 'warning');      // [PHOTO]
        return;                                                                // [PHOTO]
    }                                                                          // [PHOTO]

    spinner.classList.remove('d-none');
    confirmBtn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('employee_id', employeeId);
        formData.append('pin',         pin);
        formData.append('date',        currentTaskDate);
        formData.append('note',        note);
        if (photoFile) {                                                       // [PHOTO]
            formData.append('photo', photoFile);                               // [PHOTO]
        }                                                                      // [PHOTO]

        const response = await fetch(ROOT + '/checklists/tasks/' + currentTaskId + '/complete', {
            method: 'POST',
            body:   formData,
        });
        // ... suite du handler (gestion réponse JSON) : commune PIN + photo
    } catch (err) { /* ... */ }
});
```

### 2i. [MIXTE] Fermeture du modal — ligne photo (l. 674–679)

```js
if (modal) {
    modal.addEventListener('hidden.bs.modal', function () {
        stopCamera();      // [PHOTO]
        resetModal();
    });
}
```

---

## 3. Route — `src/core/Bootstrap/Routes/ChecklistRoutes.php` (l. 12–15)

[MIXTE] La route sert au complètement avec ou sans photo :

```php
$r->addRoute('POST', '/checklists/tasks/{taskId}/complete', [
    'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
    'method'     => 'completeTask',
]);
```

---

## 4. Contrôleur — `src/app/Http/Controllers/Checklist/ChecklistController.php` (l. 65–95)

[MIXTE] Méthode complète ; lignes photo marquées :

```php
public function completeTask(string $taskId): \Symfony\Component\HttpFoundation\JsonResponse
{
    $taskId = (int)$taskId;
    if ($taskId <= 0) {
        return $this->json(['success' => false, 'message' => 'invalid_task'], 400);
    }

    $body       = $_POST;
    $employeeId = isset($body['employee_id']) ? (int)$body['employee_id'] : 0;
    $pin        = trim($body['pin'] ?? '');
    $date       = $body['date'] ?? date('Y-m-d');
    $note       = trim($body['note'] ?? '');
    $photo      = $_FILES['photo'] ?? null;                                    // [PHOTO]

    if ($employeeId <= 0) {
        return $this->json(['success' => false, 'message' => 'employee_required'], 400);
    }

    if ($pin === '') {
        return $this->json(['success' => false, 'message' => 'pin_required'], 400);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $result = $this->checklistService->completeTask($taskId, $employeeId, $pin, $date, $note, $photo);  // [PHOTO: 6e argument]

    $status = ($result['success'] ?? false) ? 200 : 422;
    return $this->json($result, $status);
}
```

---

## 5. Service — `src/app/Services/Checklist/ChecklistService.php` (l. 47–86)

[MIXTE] Méthode complète ; la photo n'est qu'un passage de paramètre :

```php
/**
 * Weryfikuje PIN pracownika po stronie serwera, a następnie oznacza zadanie jako wykonane.
 * Zwraca ['success' => bool, 'message' => string].
 */
public function completeTask(int $taskId, int $employeeId, string $pin, string $date, string $note, ?array $photo = null): array   // [PHOTO: param $photo]
{
    $shopId = $this->getShopId();
    if ($shopId <= 0) {
        return ['success' => false, 'message' => 'shop_not_found'];
    }

    // Pobierz pracowników ze szczegółami (z PIN) do weryfikacji
    $employees = $this->checklistRepository->getEmployeesForShop($shopId);
    $employee  = null;
    foreach ($employees as $e) {
        if ((int)$e['id'] === $employeeId) {
            $employee = $e;
            break;
        }
    }

    if ($employee === null) {
        return ['success' => false, 'message' => 'employee_not_found'];
    }

    if (($employee['pin'] ?? '') !== $pin) {
        return ['success' => false, 'message' => 'invalid_pin'];
    }

    $fields = [
        'task_id'            => $taskId,
        'status'             => 'DONE',
        'scheduled_for_date' => $date,
        'employee_id'        => $employeeId,
        'note'               => $note,
    ];

    $result = $this->checklistRepository->markTaskDone($employeeId, $taskId, $fields, $photo);   // [PHOTO: 4e argument]

    return [
        'success' => $result['success'] ?? false,
        'message' => $result['message'] ?? ($result['description'] ?? 'error'),
    ];
}
```

---

## 6. Repository — `src/app/Repositories/Checklist/ChecklistRepository.php` (l. 38–54)

[MIXTE] Méthode entière à déplacer avec la mécanique :

```php
/**
 * Oznacza zadanie jako wykonane przez pracownika.
 */
public function markTaskDone(int $employeeId, int $taskId, array $fields, ?array $photo = null): array
{
    $files = [];                                                               // [PHOTO]
    if ($photo && isset($photo['tmp_name']) && $photo['error'] === UPLOAD_ERR_OK) {  // [PHOTO]
        $files['photo'] = $photo;                                              // [PHOTO]
    }                                                                          // [PHOTO]

    $res = $this->apiClient->postMultipart(
        "/employees/{$employeeId}/tasks/{$taskId}/mark-as-done",
        $fields,
        $files                                                                 // [PHOTO]
    );
    return $res;
}
```

---

## 7. Cœur HTTP — `src/core/Http/ApiClient.php` (l. 287–354)

[PHOTO — dépendance] `postMultipart` est la brique qui transporte le fichier vers l'API (aussi utilisée par `ComplaintRepository`, l. 60 : si vous la déplacez, gardez-la disponible pour les réclamations ou dupliquez-la) :

```php
public function postMultipart(string $endpoint, array $fields, array $files = [])
{
    $url = $this->baseUrl . $endpoint;
    $headers = $this->getHeaders();

    // WAŻNE: nie ustawiaj Content-Type ręcznie na multipart.
    // cURL sam doda boundary.
    $headers = array_filter($headers, fn($h) => stripos($h, 'Content-Type:') !== 0);

    $payload = $fields;

    // pliki: ['photo' => $_FILES['photo']]
    foreach ($files as $fieldName => $fileArr) {
        if (!$fileArr) continue;

        $err = $fileArr['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            // możesz też rzucić wyjątek albo zwrócić błąd
            continue;
        }

        $tmp = $fileArr['tmp_name'] ?? '';
        if (!$tmp || !is_file($tmp)) continue;

        $mime = $fileArr['type'] ?? 'application/octet-stream';
        $name = $fileArr['name'] ?? ('upload_' . time());

        $payload[$fieldName] = new \CURLFile($tmp, $mime, $name);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);

    $response_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $response = [
        'message' => null,
        'inserted_id' => null,
        'success' => false,
        'error' => [],
        'code' => $response_code,
        'description' => null,
    ];

    if ($result === false) {
        $response['description'] = 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return $response;
    }

    if ($response_code === 200 || $response_code === 201 || $response_code === 204) {
        $decoded_data = json_decode($result, true);
        $response['message'] = $decoded_data['message'] ?? null;
        $response['inserted_id'] = $decoded_data['inserted_id'] ?? null;
        $response['success'] = true;
    } else {
        $decoded_data = json_decode($result, true);
        $response['description'] = $decoded_data['description'] ?? $result;
    }

    curl_close($ch);
    return $response;
}
```

---

## 8. Traductions — `src/core/I18n/translations/page/{fr,en,pl,it,nl}/checklists.json`

[PHOTO] Les 4 clés à déplacer, dans les 5 langues :

| Clé | fr | en | pl | it | nl |
|---|---|---|---|---|---|
| `photo_required` | Photo requise | Photo required | Wymagane zdjęcie | Foto richiesta | Foto vereist |
| `modal_photo_label` | Photo | Photo | Zdjęcie | Foto | Foto |
| `modal_photo_hint` | Prenez une photo ou sélectionnez un fichier. | Take a photo or select a file. | Zrób zdjęcie lub wybierz plik. | Scatta una foto o seleziona un file. | Maak een foto of selecteer een bestand. |
| `complete_error_photo_required` | Cette tâche nécessite une photo. Veuillez ajouter une photo avant de confirmer. | This task requires a photo. Please add a photo before confirming. | To zadanie wymaga zdjęcia. Dodaj zdjęcie przed zatwierdzeniem. | Questo compito richiede una foto. Aggiungi una foto prima di confermare. | Deze taak vereist een foto. Voeg een foto toe voor de bevestiging. |

---

## 9. Contrat avec l'API backend (à respecter à destination)

- **Entrée** : le flag `requires_photo` arrive sur chaque tâche via
  `GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=...`
  (`ChecklistRepository::getChecklistProgress`).
- **Sortie** : la photo part en multipart vers
  `POST /employees/{employeeId}/tasks/{taskId}/mark-as-done`
  avec les champs `task_id`, `status=DONE`, `scheduled_for_date`, `employee_id`, `note`
  et le fichier sous la clé **`photo`**.
- La vérification « photo obligatoire » n'existe que côté client (JS, section 2h).
  Le contrôleur PHP n'impose pas la photo pour les tâches `requires_photo` —
  si la destination doit être stricte, ajouter la vérification serveur au déplacement.

## Récapitulatif des emplacements

| Couche | Fichier | Lignes | Nature |
|---|---|---|---|
| Icône tâche | `src/app/Views/checklist/index.twig` | 203–206 | [PHOTO] |
| Bouton | `src/app/Views/checklist/index.twig` | 270 | [MIXTE] |
| Modal photo | `src/app/Views/checklist/index.twig` | 378–413 | [PHOTO] |
| JS photo | `src/app/Views/checklist/index.twig` | 440, 445–446, 458–469, 479–503, 512–514, 524, 530–536, 541–600, 610–611, 621–624, 635–637, 676 | [PHOTO]/[MIXTE] |
| Route | `src/core/Bootstrap/Routes/ChecklistRoutes.php` | 12–15 | [MIXTE] |
| Contrôleur | `src/app/Http/Controllers/Checklist/ChecklistController.php` | 77, 91 | [MIXTE] |
| Service | `src/app/Services/Checklist/ChecklistService.php` | 47, 80 | [MIXTE] |
| Repository | `src/app/Repositories/Checklist/ChecklistRepository.php` | 41–54 | [MIXTE] |
| Transport | `src/core/Http/ApiClient.php` | 287–354 | dépendance |
| i18n | `.../page/*/checklists.json` | 4 clés × 5 langues | [PHOTO] |
