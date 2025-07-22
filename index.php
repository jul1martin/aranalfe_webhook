<?php
require __DIR__ . '/vendor/autoload.php';

try {
     // Cargar variables de entorno
     $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/secret_tokko_webhook');
     $dotenv->load();

     $input = file_get_contents('php://input');

     // Crear directorios si no existen
     if (!file_exists('log_inputs')) {
          mkdir('log_inputs', 0755, true);
     }
     if (!file_exists('log_fails')) {
          mkdir('log_fails', 0755, true);
     }

     // Guardar input en archivo del día
     $day = date('d');
     $inputLogFile = "log_inputs/day_{$day}.log";
     file_put_contents($inputLogFile, date('Y-m-d H:i:s') . " - Input: " . $input . PHP_EOL, FILE_APPEND);

     if ($input === false) {
         throw new Exception('No se pudo leer la entrada PHP');
     }
     
     $data = json_decode($input, true);

     if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
         throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
     }
     
     if (empty($data)) {
         throw new Exception('Los datos están vacíos');
     }
     
     $apiKey = $_ENV['TOKKO_API_KEY'];

     // Resources
     // https://www.tokkobroker.com/api/v1/property/7174910?format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a
     // https://www.tokkobroker.com/api/v1/development/62216?format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a
     // https://www.tokkobroker.com/api/v1/contact/52918638?format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a
     

     // Forma webhook
     /*
     {
          "type": "property/development/contact",
          "id": ID,
     }
     */

     $sheetData = [];

     switch ($data['type']) {
          case 'property':
               $sheetData = getProperty($data['id'], $apiKey);
               break;
          case 'development':
               $sheetData = getDevelopment($data['id'], $apiKey);
               break;
          case 'contact':
               $sheetData = getContact($data['id'], $apiKey);
               break;
          default:
               throw new Exception('Tipo de recurso no válido');
     }

     if(empty($sheetData)) {
          throw new Exception('No se encontraron datos');
     }

     $values = [array_values($sheetData['sheet'])];

     // Configurar Google Client
     $client = new Google_Client();
     $client->setAuthConfig(dirname(__DIR__, 2) . '/secret_tokko_webhook/credenciales.json');
     $client->addScope([
          Google_Service_Sheets::SPREADSHEETS,
          Google_Service_Drive::DRIVE
     ]);

     $service = new Google_Service_Sheets($client);

     $spreadsheetId = $_ENV['GOOGLE_SHEETS_ID'];
     $range = $sheetData['page'] . '!A2';

     $body = new Google_Service_Sheets_ValueRange([
          'values' => $values
     ]);

     $params = ['valueInputOption' => 'USER_ENTERED'];
     $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

     echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
     // Loguear error en archivo del día
     $day = date('d');
     $errorLogFile = "log_fails/day_{$day}.log";
     file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
     
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

function getProperty($id, $apiKey) {
     $data = getTokko('property', $id, $apiKey);
     $body = [];

     if(!$data) return $body;

     $body = [
          'id' => $data['id'],
          'Ref Code' => $data['id'],
          'Address' => $data['address'],
          'Tags' => implode(', ', $data['custom_tags']),
     ];

     return [
          "sheet" => array_values($body),
          "page" => "Propiedades"
     ];
}

function getDevelopment($id, $apiKey) {
     $data = getTokko('development', $id, $apiKey);
     $body = [];

     if(!$data) return $body;

     $body = [
          'id' => $data['id'],
          'Ref Code' => $data['id'],
          'Address' => $data['address'],
          'Name' => $data['name'],
     ];

     return [
          "sheet" => array_values($body),
          "page" => "Desarrollos"
     ];
}

function getContact($id, $apiKey) {
     $data = getTokko('contact', $id, $apiKey);
     $body = [];

     if(!$data) return $body;

     $body = [
          'ID' => $data['id'],
          'Agent' => $data['agent'] ? $data['agent']['name'] : '',
          'Name' => $data['name'],
          'Email' => $data['email'],
          'Phone' => $data['phone'],
     ];

     return [
          "sheet" => array_values($body),
          "page" => "Contactos"
     ];
}

function getTokko($resource, $id, $apiKey) {
     $url = "https://www.tokkobroker.com/api/v1/{$resource}/{$id}?format=json&key={$apiKey}";
     
     $context = stream_context_create([
          'http' => [
               'ignore_errors' => true
          ]
     ]);

     $response = file_get_contents($url, false, $context);
     
     if (strpos($http_response_header[0], '404') !== false) {
          return null;
     }
     
     return json_decode($response, true);
}