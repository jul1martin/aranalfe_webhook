<?php

require __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/secret_tokko_webhook');
$dotenv->load();

function getDevelopment($id) {
     $data = getTokko('development', $id);
     $body = [];

     if(!$data) return $body;

     $types = [
          1 => 'Edificio de oficinas',
          2 => 'Edificio',
          3 => 'Country', 
          4 => 'Barrio Privado',
          5 => 'Náutico',
          6 => 'Rural',
          7 => 'Edificio de Cocheras',
          8 => 'Condominio Industrial',
          9 => 'Centro Logístico',
          10 => 'Condominio',
          11 => 'Otro',
          12 => 'Comercial',
          13 => 'Hotel',
          14 => 'Barrio Abierto'
     ];

     $id = $data['id'];
     
     $body = [
          'tokko_id' => urlForExcel("https://www.tokkobroker.com/development/{$id}", $id),
          'name' => $data['name'],
          'web_url' => urlForExcel("https://aranalfe.com/emprendimiento/?id={$id}"),
          'type' => isset($data['type']) ? ($types[$data['type']['id']] ?? $data['type']['name']) : '',
          'address' => $data['address'],
          'location_name' => $data['location']['name'] ?? ''
     ];

     return [
          "sheet" => $body,
          "page" => "Desarrollos"
     ];
}

function getTokko($resource, $id, $urlParams = null) {
     $apiKey = $_ENV['TOKKO_API_KEY'];

     $url = "https://www.tokkobroker.com/api/v1/{$resource}" . ($id ? "/" . $id : "") . "?format=json&key={$apiKey}" . ($urlParams ? '&' . $urlParams : '');

     $context = stream_context_create([
          'http' => [
               'ignore_errors' => true
          ]
     ]);

     $attempts = 0;
     $maxAttempts = 3;
     
     do {
          $response = @file_get_contents($url, false, $context);
          $attempts++;

          if ($response !== false) {
               if (strpos($http_response_header[0], '404') !== false) {
                    return null;
               }
               return json_decode($response, true);
          }

          if ($attempts < $maxAttempts) {
               sleep(1); // Esperar 1 segundo antes de reintentar
          }

     } while ($attempts < $maxAttempts);

     throw new Exception("No se pudo obtener respuesta después de {$maxAttempts} intentos");
}

function urlForExcel($url, $text = 'VER') {
     return "=HYPERLINK(\"{$url}\", \"{$text}\")";
}

function appendToGoogleSheet($sheetData, $page) {
     try {
          $spreadsheetId = $_ENV['GOOGLE_SHEETS_ID'];

          // Configurar Google Client
          $client = new Google_Client();
          $client->setAuthConfig(dirname(__DIR__, 2) . '/secret_tokko_webhook/credenciales.json');
          $client->addScope([
               Google_Service_Sheets::SPREADSHEETS,
               Google_Service_Drive::DRIVE
          ]);

          $service = new Google_Service_Sheets($client);
          $range = "{$page}!A2";

          $body = new Google_Service_Sheets_ValueRange([
               'values' => $sheetData
          ]);

          $params = ['valueInputOption' => 'USER_ENTERED'];
          $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

          echo "Se enviaron " . count($sheetData) . " datos a Google Sheets\n";

          return true;
     } catch (Exception $e) {
          throw new Exception("Error al enviar datos a Google Sheets: " . $e->getMessage());
     }
}