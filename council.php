<?php

// Karen Bot by @levelsio 
//
// ask Claude Code or Cursor to adapt it to your city
// mine is for Lisbon in Portuguese but it should work with any city and any language!
// save it as council.php and add a Nginx route /council on your server to use it
// make sure to add /council?key= and set a key below to use it privately
// 
// more info: https://x.com/levelsio/status/2009011216132526407?s=20

// <set vars here>
    // this is the key you add to the url like /council?key= so only you can access it
    define('KEY_TO_ACCESS_THE_SCRIPT', 'key');

    // I used Postmark but you can chance to other email services of coruse
    define('POSTMARK_API_KEY', 'INSERT_KEY_HERE');
    define('OPENAI_API_KEY', 'your-key-here');

    // email addresses
    define('YOUR_NAME', 'Your Name To Sign The Letter');
    define('FROM_YOUR_EMAIL', 'you@you.com');
    define('TO_COUNCIL_EMAIL', 'council@city.com');
    define('CC_EMAILS', 'gf@gf.com');

    // set to your city
    define('MAP_CENTER_LAT', 38.734674);
    define('MAP_CENTER_LNG', -9.16427);
// </set vars here>

error_reporting(0);
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_file_uploads', '20');

// Simple access key check
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
    'openai' => [
        'key' => OPENAI_API_KEY
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
        echo json_encode(['success' => false, 'error' => 'Complaint text is required']);
        exit;
    }

    $systemPrompt = "Você é um assistente que transforma reclamações informais em cartas formais em português de Portugal para enviar à Câmara Municipal.

FORMATO DA CARTA:
Começa SEMPRE com um bloco de dados estruturados para scan rápido (só inclui campos que tens informação):

---
Resumo: [uma linha resumindo o problema, ex: 'Acumulação de lixo junto ao contentor há 3 dias']
Localização: [morada/rua mencionada]
Google Maps: [link se fornecido]
---

Depois escreve a carta formal:
- Saudação formal gender-neutral: usa 'Ex.mos Senhores,' (dirigido à Câmara em geral, não ao presidente)
- Estruturar o problema de forma clara
- Incluir um pedido de ação específico
- Terminar com despedida formal

IMPORTANTE: NUNCA adicionar placeholders, templates, ou texto entre parênteses retos como [nome], [morada], [Espaço para...], etc. A carta deve estar pronta a enviar sem qualquer texto para preencher. Se não tens uma informação, simplesmente não incluas esse campo.

Assina sempre a carta com:
Com os melhores cumprimentos,
".YOUR_NAME;

    $userPrompt = "Transforma a seguinte reclamação numa carta formal para a Câmara Municipal de Lisboa.\n\n";

    if (!empty($address)) {
        $userPrompt .= "Localização do problema: $address\n";
        if (!empty($lat) && !empty($lng)) {
            $userPrompt .= "Google Maps: https://www.google.com/maps/@$lat,$lng,100m/data=!3m1!1e3\n";
        }
        $userPrompt .= "\n";
    }

    $userPrompt .= "Report:\n$complaint";

    if ($hasAttachments) {
        $userPrompt .= "\n\nNOTA: O remetente vai anexar fotografias como prova. Menciona isso na carta (ex: 'Em anexo envio fotografias que comprovam a situação descrita.')";
    }

    $userPrompt .= "\n\nEscreve primeiro a carta em português, depois escreve ===ENGLISH=== e a tradução em inglês.";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-4o-mini',
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
        echo json_encode(['success' => false, 'error' => 'Failed to connect to OpenAI API']);
        exit;
    }

    $data = json_decode($response, true);
    if (isset($data['choices'][0]['message']['content'])) {
        echo json_encode(['success' => true, 'expanded' => $data['choices'][0]['message']['content']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid response from OpenAI']);
    }
    exit;
}

// Handle form submission (send email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    header('Content-Type: application/json');

    $complaint = trim($_POST['complaint'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');

    // Extract Resumo from the letter for subject, or fallback to first 60 chars
    if (preg_match('/Resumo:\s*(.+)/i', $complaint, $matches)) {
        $subject = trim($matches[1]);
    } else {
        $subject = mb_substr(preg_replace('/\s+/', ' ', $complaint), 0, 60);
        if (mb_strlen($complaint) > 60) $subject .= '...';
    }

    if (empty($complaint)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in the complaint.']);
        exit;
    }

    // Build email body
    $emailBody = $complaint;
    $emailBody .= "\n\nSent from my iPhone";

    // Prepare attachments (resize images to ~1MB max)
    $attachments = [];
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['attachments']['tmp_name'][$i];
                $fileName = $_FILES['attachments']['name'][$i];
                $mimeType = mime_content_type($tmpName);

                // Resize image if it's too large
                $maxSize = 1024 * 1024; // 1MB
                $fileSize = filesize($tmpName);

                if (strpos($mimeType, 'image/') === 0 && $fileSize > $maxSize) {
                    $imageData = resizeImage($tmpName, $mimeType, $maxSize);
                    $content = base64_encode($imageData);
                    // Update filename to jpg if converted
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
        // Load image
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

        // Get dimensions
        $width = imagesx($img);
        $height = imagesy($img);

        // Start with quality 85 and reduce until under maxSize
        $quality = 85;
        do {
            ob_start();
            imagejpeg($img, null, $quality);
            $data = ob_get_clean();
            $quality -= 10;
        } while (strlen($data) > $maxSize && $quality > 20);

        // If still too large, also reduce dimensions
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

    // Check if files were uploaded but none processed (attachment failure)
    $filesUploaded = isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0]);
    if ($filesUploaded && empty($attachments)) {
        echo json_encode(['success' => false, 'message' => 'Failed to process attachments. Please try again.']);
        exit;
    }

    // Send via Postmark
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
        echo json_encode(['success' => true, 'message' => "Complaint sent successfully! ({$attachmentCount} photo" . ($attachmentCount != 1 ? 's' : '') . " attached)"]);
    } else {
        $errorMsg = $responseData['Message'] ?? 'Unknown error';
        echo json_encode(['success' => false, 'message' => 'Error sending: ' . $errorMsg]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report to Lisbon City Council (💁‍♀️ Karen Bot)</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🇵🇹</text></svg>">
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
    <h1>🇵🇹 Report to Lisbon City Council (💁‍♀️ Karen Bot)</h1>


    <?php if ($message): ?>
        <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="complaintForm">
        <input type="hidden" name="action" value="send">
        <input type="hidden" id="selectedLat" name="lat" value="">
        <input type="hidden" id="selectedLng" name="lng" value="">

        <button type="button" onclick="useMyLocation()" style="margin-bottom: 10px;">Center on my location</button>
        <label>Then click on map to place marker</label>
        <div id="map"></div>
        <div class="location-info" id="locationInfo">No location selected</div>

        <label>Attach images (optional)</label>
        <div class="upload-box" id="uploadBox" onclick="document.getElementById('attachments').click()">
            <div id="uploadPlaceholder">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                </svg>
                <p>Click to upload photos or drag & drop</p>
            </div>
            <div id="imagePreview" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;"></div>
            <input type="file" id="attachments" name="attachments[]" multiple accept="image/*" onchange="previewImages(this)">
        </div>

        <label for="input">Your complaint</label>
        <textarea id="input" required placeholder="Write your complaint here..." oninput="localStorage.setItem('complaint', this.value)" style="min-height: 100px;"></textarea>

        <button type="button" id="expandBtn" onclick="expandToFormalLetter()">
            Write letter
        </button>

        <label for="complaint" style="margin-top: 15px;">Portuguese formal letter (this will be sent)</label>
        <textarea id="complaint" name="complaint" placeholder="Portuguese letter will appear here..." style="min-height: 400px;"><?= htmlspecialchars($complaint ?? '') ?></textarea>

        <label for="expanded" style="margin-top: 15px;">English translation (for your reference)</label>
        <textarea id="expanded" readonly placeholder="English translation will appear here..." style="min-height: 400px;"><?= htmlspecialchars($expanded ?? '') ?></textarea>

        <div class="buttons">
            <button type="button" onclick="sendComplaint()">Send complaint</button>
        </div>

        <div id="statusMessage" style="margin-top: 15px;"></div>
    </form>

    <script>
        // Initialize map centered on New York City (default)
        const map = L.map('map').setView([MAP_CENTER_LAT, MAP_CENTER_LNG], 13);

        const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        });

        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '© Esri'
        });

        satellite.addTo(map); // Default to satellite

        L.control.layers({ 'Streets': streets, 'Satellite': satellite }).addTo(map);

        function useMyLocation() {
            if (!navigator.geolocation) {
                alert('Geolocation not supported');
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    map.setView([pos.coords.latitude, pos.coords.longitude], 16);
                },
                (err) => {
                    alert('Could not get location. Please allow location access.');
                },
                { enableHighAccuracy: true }
            );
        }

        let marker = null;
        let selectedAddress = '';

        // User must click "Use my location" button to center map

        map.on('click', async function(e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;

            // Update hidden fields
            document.getElementById('selectedLat').value = lat;
            document.getElementById('selectedLng').value = lng;

            // Place/move marker
            if (marker) {
                marker.setLatLng(e.latlng);
            } else {
                marker = L.marker(e.latlng).addTo(map);
            }

            // Show loading
            document.getElementById('locationInfo').textContent = 'Getting address...';

            // Reverse geocode
            try {
                const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`, {
                    headers: { 'User-Agent': 'LisbonComplaints/1.0' }
                });
                const data = await resp.json();
                selectedAddress = data.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('locationInfo').textContent = '📍 ' + selectedAddress;
            } catch (err) {
                selectedAddress = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('locationInfo').textContent = '📍 ' + selectedAddress;
            }
        });

        // Load saved complaint from localStorage
        document.getElementById('input').value = localStorage.getItem('complaint') || '';

        // Drag and drop support
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
                alert('Please click on the map to set the problem location first.');
                return;
            }

            if (!input.trim()) {
                alert('Please write your complaint first.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = 'Writing...<span class="loading"></span>';

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
                    alert('Error: ' + (data.error || 'Failed to expand'));
                }
            } catch (error) {
                alert('Connection error. Please try again.');
                console.error(error);
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Write letter';
            }
        }

        async function sendComplaint() {
            const complaint = document.getElementById('complaint').value;
            const statusDiv = document.getElementById('statusMessage');
            const attachments = document.getElementById('attachments');
            const hasAttachments = attachments.files && attachments.files.length > 0;

            if (!complaint.trim()) {
                alert('Please expand your complaint to a formal letter first.');
                return;
            }

            if (!hasAttachments) {
                if (!confirm('No photo attached. Send anyway?')) {
                    return;
                }
            }

            if (!confirm('Are you sure you want to send this complaint?')) {
                return;
            }

            const formData = new FormData(document.getElementById('complaintForm'));
            formData.set('action', 'send');

            statusDiv.innerHTML = '<span style="color: #666;">Sending...</span>';

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
                statusDiv.innerHTML = '<span style="color: red;">Connection error. Please try again.</span>';
                console.error(error);
            }
        }
    </script>
</body>
</html>
