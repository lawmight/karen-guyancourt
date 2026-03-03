<?php

// Karen Bot by @levelsio — adapted for Guyancourt, France
//
// save it as council.php and add a Nginx route /council on your server to use it
// make sure to add /council?key= and set a key in .local.env to use it privately
//
// more info: https://x.com/levelsio/status/2009011216132526407?s=20

// Load configuration from .local.env
$envFile = __DIR__ . '/.local.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        define(trim($key), trim($value));
    }
}

error_reporting(0);
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_file_uploads', '20');

if (($_GET['key'] ?? '') !== KEY_TO_ACCESS_THE_SCRIPT) {
    http_response_code(404);
    exit('Not found');
}

$config = [
    'postmark' => [
        'token' => POSTMARK_API_KEY,
        'from' => FROM_YOUR_EMAIL,
        'to' => TO_COUNCIL_EMAIL
    ],
    'openrouter' => [
        'key' => OPENROUTER_API_KEY
    ]
];

$message = '';
$messageType = '';

// Handle AJAX request for GPT expansion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'expand') {
    header('Content-Type: application/json');

    $complaint = trim($_POST['complaint'] ?? '');
    $hasAttachments = isset($_POST['hasAttachments']) && $_POST['hasAttachments'] === 'true';
    $address = trim($_POST['address'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');

    if (empty($complaint)) {
        echo json_encode(['success' => false, 'error' => 'Le texte du signalement est requis']);
        exit;
    }

    $systemPrompt = "Vous êtes un assistant qui transforme des signalements informels en lettres formelles en français pour la Mairie de Guyancourt (Yvelines, Île-de-France).

FORMAT DE LA LETTRE :
Commencez TOUJOURS par un bloc de données structurées pour lecture rapide (n'incluez que les champs pour lesquels vous avez des informations) :

---
Résumé : [une ligne résumant le problème, ex: 'Accumulation de déchets sauvages rue de la Mare depuis 3 jours']
Localisation : [adresse/rue mentionnée]
Google Maps : [lien si fourni]
---

Ensuite rédigez la lettre formelle :
- Formule d'appel : 'Madame, Monsieur,'
- Structurer le problème de manière claire et factuelle
- Inclure une demande d'action spécifique
- Terminer avec une formule de politesse formelle

IMPORTANT : N'ajoutez JAMAIS de placeholders, templates ou texte entre crochets comme [nom], [adresse], [Espace pour...], etc. La lettre doit être prête à envoyer sans aucun texte à compléter. Si vous n'avez pas une information, ne l'incluez simplement pas.

Signez toujours la lettre avec :
Veuillez agréer, Madame, Monsieur, l'expression de mes salutations distinguées.
".YOUR_NAME;

    $userPrompt = "Transformez le signalement suivant en une lettre formelle adressée à la Mairie de Guyancourt.\n\n";

    if (!empty($address)) {
        $userPrompt .= "Localisation du problème : $address\n";
        if (!empty($lat) && !empty($lng)) {
            $userPrompt .= "Google Maps : https://www.google.com/maps/@$lat,$lng,100m/data=!3m1!1e3\n";
        }
        $userPrompt .= "\n";
    }

    $userPrompt .= "Signalement :\n$complaint";

    if ($hasAttachments) {
        $userPrompt .= "\n\nNOTE : L'expéditeur va joindre des photographies comme preuve. Mentionnez-le dans la lettre (ex: 'Vous trouverez ci-joint des photographies attestant de la situation décrite.')";
    }

    $userPrompt .= "\n\nRédigez d'abord la lettre en français, puis écrivez ===ENGLISH=== et la traduction en anglais.";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://openrouter.ai/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENROUTER_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'meta-llama/llama-3.1-8b-instruct:free',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'max_tokens' => 2000,
        'temperature' => 0.7
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => "Impossible de se connecter à l'API OpenRouter"]);
        exit;
    }

    $data = json_decode($response, true);
    if (isset($data['choices'][0]['message']['content'])) {
        echo json_encode(['success' => true, 'expanded' => $data['choices'][0]['message']['content']]);
    } else {
        echo json_encode(['success' => false, 'error' => "Réponse invalide de l'API OpenRouter"]);
    }
    exit;
}

// Handle form submission (send email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    header('Content-Type: application/json');

    $complaint = trim($_POST['complaint'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');

    if (preg_match('/Résumé\s*:\s*(.+)/iu', $complaint, $matches)) {
        $subject = trim($matches[1]);
    } else {
        $subject = mb_substr(preg_replace('/\s+/', ' ', $complaint), 0, 60);
        if (mb_strlen($complaint) > 60) $subject .= '...';
    }

    if (empty($complaint)) {
        echo json_encode(['success' => false, 'message' => 'Veuillez remplir le signalement.']);
        exit;
    }

    $emailBody = $complaint;
    $emailBody .= "\n\nEnvoyé depuis mon iPhone";

    $attachments = [];
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['attachments']['tmp_name'][$i];
                $fileName = $_FILES['attachments']['name'][$i];
                $mimeType = mime_content_type($tmpName);

                $maxSize = 1024 * 1024;
                $fileSize = filesize($tmpName);

                if (strpos($mimeType, 'image/') === 0 && $fileSize > $maxSize) {
                    $imageData = resizeImage($tmpName, $mimeType, $maxSize);
                    $content = base64_encode($imageData);
                    if ($mimeType !== 'image/jpeg') {
                        $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.jpg';
                        $mimeType = 'image/jpeg';
                    }
                } else {
                    $content = base64_encode(file_get_contents($tmpName));
                }

                $attachments[] = [
                    'Name' => $fileName,
                    'Content' => $content,
                    'ContentType' => $mimeType
                ];
            }
        }
    }

    function resizeImage($filePath, $mimeType, $maxSize) {
        switch ($mimeType) {
            case 'image/jpeg':
                $img = imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $img = imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $img = imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                $img = imagecreatefromwebp($filePath);
                break;
            default:
                return file_get_contents($filePath);
        }

        if (!$img) return file_get_contents($filePath);

        $width = imagesx($img);
        $height = imagesy($img);

        $quality = 85;
        do {
            ob_start();
            imagejpeg($img, null, $quality);
            $data = ob_get_clean();
            $quality -= 10;
        } while (strlen($data) > $maxSize && $quality > 20);

        if (strlen($data) > $maxSize) {
            $scale = sqrt($maxSize / strlen($data));
            $newWidth = (int)($width * $scale);
            $newHeight = (int)($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($img);
            $img = $resized;

            ob_start();
            imagejpeg($img, null, 70);
            $data = ob_get_clean();
        }

        imagedestroy($img);
        return $data;
    }

    $filesUploaded = isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0]);
    if ($filesUploaded && empty($attachments)) {
        echo json_encode(['success' => false, 'message' => 'Échec du traitement des pièces jointes. Veuillez réessayer.']);
        exit;
    }

    $emailPayload = [
        'From' => $config['postmark']['from'],
        'To' => $config['postmark']['to'],
        'Cc' => CC_EMAILS,
        'Subject' => $subject,
        'TextBody' => $emailBody,
        'MessageStream' => 'outbound'
    ];

    if (!empty($attachments)) {
        $emailPayload['Attachments'] = $attachments;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.postmarkapp.com/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Postmark-Server-Token: ' . $config['postmark']['token']
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailPayload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpCode === 200 && isset($responseData['MessageID'])) {
        $attachmentCount = count($attachments);
        echo json_encode(['success' => true, 'message' => "Signalement envoyé avec succès ! ({$attachmentCount} photo" . ($attachmentCount != 1 ? 's' : '') . " en pièce jointe)"]);
    } else {
        $errorMsg = $responseData['Message'] ?? 'Erreur inconnue';
        echo json_encode(['success' => false, 'message' => "Erreur d'envoi : " . $errorMsg]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalement à la Mairie de Guyancourt (💁‍♀️ Karen Bot)</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🇫🇷</text></svg>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
            color: #333;
        }
        h1 {
            color: #000000;
            border-bottom: 3px solid #000000;
            padding-bottom: 10px;
        }
        .info {
            background: #e8f5e9;
            border-left: 4px solid #000000;
            padding: 15px;
            margin-bottom: 20px;
        }
        form {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input[type="text"], textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            margin-bottom: 15px;
        }
        textarea {
            min-height: 200px;
            resize: vertical;
            font-family: inherit;
        }
        textarea[readonly] {
            background: #f9f9f9;
            color: #555;
        }
        #map {
            height: 250px;
            width: 100%;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
        }
        .location-info {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
        }
        input[type="file"] {
            margin-bottom: 15px;
        }
        .buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        button {
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
        }
        button[type="submit"] {
            background: #000000;
            color: white;
        }
        button[type="submit"]:hover {
            background: #333333;
        }
        button[type="button"] {
            background: #2196F3;
            color: white;
        }
        button[type="button"]:hover {
            background: #1976D2;
        }
        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .recipient {
            color: #666;
            font-size: 14px;
            margin-top: -10px;
            margin-bottom: 20px;
        }
        .upload-box {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 0;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            margin-bottom: 10px;
        }
        .upload-box:hover {
            border-color: #2196F3;
            background: #f8f9fa;
        }
        .upload-box.dragover {
            border-color: #2196F3;
            background: #e3f2fd;
        }
        .upload-box svg {
            width: 48px;
            height: 48px;
            fill: #999;
            margin-bottom: 10px;
        }
        .upload-box:hover svg {
            fill: #2196F3;
        }
        .upload-box p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        .upload-box input[type="file"] {
            display: none;
        }
    </style>
</head>
<body>
    <h1>🇫🇷 Signalement à la Mairie de Guyancourt (💁‍♀️ Karen Bot)</h1>

    <?php if ($message): ?>
        <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="complaintForm">
        <input type="hidden" name="action" value="send">
        <input type="hidden" id="selectedLat" name="lat" value="">
        <input type="hidden" id="selectedLng" name="lng" value="">

        <button type="button" onclick="useMyLocation()" style="margin-bottom: 10px;">Me localiser</button>
        <label>Cliquez sur la carte pour placer le marqueur</label>
        <div id="map"></div>
        <div class="location-info" id="locationInfo">Aucun emplacement sélectionné</div>

        <label>Joindre des photos (optionnel)</label>
        <div class="upload-box" id="uploadBox" onclick="document.getElementById('attachments').click()">
            <div id="uploadPlaceholder">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                </svg>
                <p>Cliquez pour ajouter des photos ou glissez-déposez</p>
            </div>
            <div id="imagePreview" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;"></div>
            <input type="file" id="attachments" name="attachments[]" multiple accept="image/*" onchange="previewImages(this)">
        </div>

        <label for="input">Votre signalement</label>
        <textarea id="input" required placeholder="Décrivez votre problème ici..." oninput="localStorage.setItem('complaint', this.value)" style="min-height: 100px;"></textarea>

        <button type="button" id="expandBtn" onclick="expandToFormalLetter()">
            Rédiger la lettre
        </button>

        <label for="complaint" style="margin-top: 15px;">Lettre formelle en français (celle-ci sera envoyée)</label>
        <textarea id="complaint" name="complaint" placeholder="La lettre en français apparaîtra ici..." style="min-height: 400px;"><?= htmlspecialchars($complaint ?? '') ?></textarea>

        <label for="expanded" style="margin-top: 15px;">Traduction en anglais (pour votre référence)</label>
        <textarea id="expanded" readonly placeholder="La traduction en anglais apparaîtra ici..." style="min-height: 400px;"><?= htmlspecialchars($expanded ?? '') ?></textarea>

        <div class="buttons">
            <button type="button" onclick="sendComplaint()">Envoyer le signalement</button>
        </div>

        <div id="statusMessage" style="margin-top: 15px;"></div>
    </form>

    <script>
        const map = L.map('map').setView([<?= MAP_CENTER_LAT ?>, <?= MAP_CENTER_LNG ?>], 13);

        const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        });

        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '© Esri'
        });

        satellite.addTo(map);

        L.control.layers({ 'Plan': streets, 'Satellite': satellite }).addTo(map);

        function useMyLocation() {
            if (!navigator.geolocation) {
                alert("La géolocalisation n'est pas supportée par votre navigateur");
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    map.setView([pos.coords.latitude, pos.coords.longitude], 16);
                },
                (err) => {
                    alert("Impossible d'obtenir votre position. Veuillez autoriser la géolocalisation.");
                },
                { enableHighAccuracy: true }
            );
        }

        let marker = null;
        let selectedAddress = '';

        map.on('click', async function(e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;

            document.getElementById('selectedLat').value = lat;
            document.getElementById('selectedLng').value = lng;

            if (marker) {
                marker.setLatLng(e.latlng);
            } else {
                marker = L.marker(e.latlng).addTo(map);
            }

            document.getElementById('locationInfo').textContent = "Récupération de l'adresse...";

            try {
                const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=fr`, {
                    headers: { 'User-Agent': 'GuyancourtSignalements/1.0' }
                });
                const data = await resp.json();
                selectedAddress = data.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('locationInfo').textContent = '📍 ' + selectedAddress;
            } catch (err) {
                selectedAddress = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('locationInfo').textContent = '📍 ' + selectedAddress;
            }
        });

        document.getElementById('input').value = localStorage.getItem('complaint') || '';

        const uploadBox = document.getElementById('uploadBox');
        const fileInput = document.getElementById('attachments');

        uploadBox.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadBox.classList.add('dragover');
        });

        uploadBox.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadBox.classList.remove('dragover');
        });

        uploadBox.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadBox.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                previewImages(fileInput);
            }
        });

        function previewImages(input) {
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('uploadPlaceholder');
            preview.innerHTML = '';

            if (input.files && input.files.length > 0) {
                placeholder.style.display = 'none';
                Array.from(input.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.style.cssText = 'width: 100%; border-radius: 4px; border: 1px solid #ddd;';
                        preview.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                });
            } else {
                placeholder.style.display = 'block';
            }
        }

        async function expandToFormalLetter() {
            const input = document.getElementById('input').value;
            const attachments = document.getElementById('attachments');
            const hasAttachments = attachments.files && attachments.files.length > 0;
            const btn = document.getElementById('expandBtn');
            const lat = document.getElementById('selectedLat').value;
            const lng = document.getElementById('selectedLng').value;

            if (!lat || !lng) {
                alert("Veuillez d'abord cliquer sur la carte pour définir la localisation du problème.");
                return;
            }

            if (!input.trim()) {
                alert("Veuillez d'abord décrire votre problème.");
                return;
            }

            btn.disabled = true;
            btn.innerHTML = 'Rédaction en cours...<span class="loading"></span>';

            try {
                const formData = new FormData();
                formData.append('action', 'expand');
                formData.append('complaint', input);
                formData.append('hasAttachments', hasAttachments);
                formData.append('address', selectedAddress);
                formData.append('lat', document.getElementById('selectedLat').value);
                formData.append('lng', document.getElementById('selectedLng').value);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    const parts = data.expanded.split('===ENGLISH===');
                    document.getElementById('complaint').value = parts[0].trim();
                    document.getElementById('expanded').value = parts[1] ? parts[1].trim() : '';
                } else {
                    alert('Erreur : ' + (data.error || 'Échec de la rédaction'));
                }
            } catch (error) {
                alert('Erreur de connexion. Veuillez réessayer.');
                console.error(error);
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Rédiger la lettre';
            }
        }

        async function sendComplaint() {
            const complaint = document.getElementById('complaint').value;
            const statusDiv = document.getElementById('statusMessage');
            const attachments = document.getElementById('attachments');
            const hasAttachments = attachments.files && attachments.files.length > 0;

            if (!complaint.trim()) {
                alert("Veuillez d'abord rédiger la lettre formelle.");
                return;
            }

            if (!hasAttachments) {
                if (!confirm('Aucune photo jointe. Envoyer quand même ?')) {
                    return;
                }
            }

            if (!confirm('Êtes-vous sûr de vouloir envoyer ce signalement ?')) {
                return;
            }

            const formData = new FormData(document.getElementById('complaintForm'));
            formData.set('action', 'send');

            statusDiv.innerHTML = '<span style="color: #666;">Envoi en cours...</span>';

            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    statusDiv.innerHTML = '<span style="color: green;">' + data.message + '</span>';
                    document.getElementById('input').value = '';
                    document.getElementById('complaint').value = '';
                    document.getElementById('expanded').value = '';
                    localStorage.removeItem('complaint');
                } else {
                    statusDiv.innerHTML = '<span style="color: red;">' + data.message + '</span>';
                }
            } catch (error) {
                statusDiv.innerHTML = '<span style="color: red;">Erreur de connexion. Veuillez réessayer.</span>';
                console.error(error);
            }
        }
    </script>
</body>
</html>
